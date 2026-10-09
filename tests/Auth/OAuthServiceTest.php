<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAIChatGPT\Tests\Auth;

use Firebase\JWT\JWT;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Auth\AuthException;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Auth\AuthFileStore;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Auth\OAuthConfig;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Tests\Support\AuthFixture;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Tests\Support\InMemoryAuthStorage;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Tests\Support\IsolatedTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

final class OAuthServiceTest extends IsolatedTestCase
{
    public function testFirstRegistrationVerifiesGrantAndPersistsIssuedClient(): void
    {
        $nonce = '';
        $requests = [];
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$nonce, &$requests): MockResponse {
            $requests[] = $url;
            if (str_ends_with($url, '/jwks.json')) {
                return new MockResponse(json_encode(AuthFixture::jwks(), \JSON_THROW_ON_ERROR));
            }
            parse_str($options['body'], $body);
            self::assertSame('issued-client', $body['client_id']);
            self::assertSame(OAuthConfig::RESOURCE, $body['resource']);
            self::assertArrayNotHasKey('client_secret', $body);
            self::assertNotEmpty($body['code_verifier']);
            self::assertSame('authorization_code', $body['grant_type']);

            return new MockResponse(json_encode(AuthFixture::token($nonce), \JSON_THROW_ON_ERROR));
        });
        $storage = new InMemoryAuthStorage();
        $service = AuthFixture::service($storage, $client);
        $request = $service->beginAuthorization();
        $nonce = $request->nonce;
        parse_str((string) parse_url($request->url, \PHP_URL_QUERY), $query);
        self::assertSame('dynamic_agent_client', $query['client_id']);
        self::assertSame('Test app', $query['agent_name_hint']);
        self::assertSame('urn:uuid:00000000-0000-4000-8000-000000000001', $query['ext_agent_host_id']);
        self::assertSame('S256', $query['code_challenge_method']);
        self::assertArrayNotHasKey('approval_prompt', $query);
        self::assertArrayNotHasKey('id_token_hint', $query);
        $record = $service->completeAuthorization($request, ['code' => 'test-code', 'state' => $request->state, 'client_id' => 'issued-client']);
        self::assertSame('issued-client', $record->clientId);
        self::assertSame('account-one', $record->subject);
        self::assertSame(1900003600, $record->expires);
        self::assertSame($record, $storage->load());
        self::assertCount(2, $requests);
    }

    public function testDisconnectedReauthorizationUsesSavedClientAndAcceptsOmittedCallbackClient(): void
    {
        $nonce = '';
        $client = new MockHttpClient(static function (string $method, string $url) use (&$nonce): MockResponse {
            return new MockResponse(json_encode(str_ends_with($url, '/jwks.json') ? AuthFixture::jwks() : AuthFixture::token($nonce), \JSON_THROW_ON_ERROR));
        });
        $storage = new InMemoryAuthStorage(AuthFixture::record()->disconnected());
        $service = AuthFixture::service($storage, $client);
        $request = $service->beginAuthorization();
        $nonce = $request->nonce;
        parse_str((string) parse_url($request->url, \PHP_URL_QUERY), $query);
        self::assertSame('issued-client', $query['client_id']);
        self::assertArrayNotHasKey('id_token_hint', $query);
        self::assertArrayNotHasKey('agent_name_hint', $query);
        self::assertSame('new-access', $service->completeManualAuthorization($request, 'http://127.0.0.1:1455/auth/callback?code=code&state='.$request->state)->access);
        self::assertNotSame($request->state, $service->beginAuthorization()->state);
    }

    public function testActiveReauthorizationRetainsRegistrationAndIdentityWithTokenHint(): void
    {
        $record = AuthFixture::record();
        $storage = new InMemoryAuthStorage($record);
        $client = new MockHttpClient(static function (): never { self::fail('Unexpected HTTP request.'); });
        $request = AuthFixture::service($storage, $client)->beginAuthorization();
        parse_str((string) parse_url($request->url, \PHP_URL_QUERY), $query);
        self::assertSame('issued-client', $query['client_id']);
        self::assertSame('issued-client', $request->registeredClientId);
        self::assertSame($record->idToken, $query['id_token_hint']);
        self::assertArrayNotHasKey('agent_name_hint', $query);
        self::assertSame($record, $storage->load());
        self::assertNotNull($record->idToken);
        self::assertSame(OAuthConfig::ISSUER, $record->issuer);
        self::assertSame('account-one', $record->subject);
    }

    /** @param array<string, mixed> $callback */
    #[DataProvider('invalidCallbacks')]
    public function testRejectsCallbackBeforeTokenExchange(array $callback): void
    {
        $client = new MockHttpClient(static function (): never { self::fail('Unexpected HTTP request.'); });
        $service = AuthFixture::service(new InMemoryAuthStorage(AuthFixture::record()), $client);
        $request = $service->beginAuthorization();
        $callback = array_replace(['state' => $request->state, 'code' => 'code'], $callback);
        $this->expectException(AuthException::class);
        $service->completeAuthorization($request, $callback);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidCallbacks(): iterable
    {
        yield 'mismatched client' => [['client_id' => 'attacker-client']];
        yield 'missing state' => [['state' => null]];
        yield 'bad state on error' => [['state' => 'bad', 'error' => 'secret-error']];
        yield 'legitimate denial' => [['error' => 'access_denied']];
        yield 'missing code' => [['code' => null]];
        yield 'non scalar client' => [['client_id' => ['bad']]];
    }

    public function testNewRegistrationNeedsIssuedClient(): void
    {
        $service = AuthFixture::service(new InMemoryAuthStorage(), new MockHttpClient());
        $request = $service->beginAuthorization();
        $this->expectException(AuthException::class);
        $service->completeAuthorization($request, ['state' => $request->state, 'code' => 'code']);
    }

    public function testMissingScopeCannotSaveCredentials(): void
    {
        $storage = new InMemoryAuthStorage();
        $client = new MockHttpClient(new MockResponse('{"access_token":"a","refresh_token":"r","expires_in":3600,"id_token":"not-verified","scope":"openid"}'));
        $service = AuthFixture::service($storage, $client);
        $request = $service->beginAuthorization();
        try {
            $service->completeAuthorization($request, ['state' => $request->state, 'code' => 'code', 'client_id' => 'issued-client']);
            self::fail('Expected missing direct-token permission.');
        } catch (AuthException $exception) {
            self::assertStringContainsString('direct-token permission', $exception->getMessage());
            self::assertNotNull($storage->record);
            self::assertSame('issued-client', $storage->record->clientId);
            self::assertNull($storage->record->access);
            self::assertNull($storage->record->idToken);
        }
    }

    public function testReauthorizationRejectsAnotherAccount(): void
    {
        $nonce = '';
        $client = new MockHttpClient(static function (string $method, string $url) use (&$nonce): MockResponse {
            $token = AuthFixture::token($nonce);
            $token['id_token'] = AuthFixture::idToken($nonce, ['sub' => 'another-account']);

            return new MockResponse(json_encode(str_ends_with($url, '/jwks.json') ? AuthFixture::jwks() : $token, \JSON_THROW_ON_ERROR));
        });
        $storage = new InMemoryAuthStorage(AuthFixture::record());
        $service = AuthFixture::service($storage, $client);
        $request = $service->beginAuthorization();
        $nonce = $request->nonce;
        $this->expectException(AuthException::class);
        $this->expectExceptionMessage('verified account identity');
        $service->completeAuthorization($request, ['state' => $request->state, 'code' => 'code']);
    }

    public function testRefreshRotatesOnceAcrossStoresAndRetainsRegistrationWhenIdentityOmitted(): void
    {
        $path = $this->directory.'/auth.json';
        $factory = new LockFactory(new FlockStore($this->directory));
        $first = new AuthFileStore($path, $factory);
        $second = new AuthFileStore($path, $factory);
        $record = AuthFixture::record(1);
        $first->installationId();
        $first->update(static fn () => $record);
        self::assertSame(1, $second->load()?->expires);
        $requests = 0;
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests): MockResponse {
            ++$requests;
            parse_str($options['body'], $body);
            self::assertSame('refresh_token', $body['grant_type']);
            self::assertSame('issued-client', $body['client_id']);
            self::assertSame('test-refresh', $body['refresh_token']);

            return new MockResponse('{"access_token":"rotated-access","refresh_token":"rotated-refresh","expires_in":3600}');
        });
        self::assertSame('rotated-access', AuthFixture::service($first, $client)->accessToken());
        self::assertSame('rotated-access', AuthFixture::service($second, $client)->accessToken());
        self::assertSame(1, $requests);
        self::assertSame('rotated-refresh', $second->load()?->refresh);
        self::assertSame($record->idToken, $second->load()?->idToken);
        self::assertSame('issued-client', $second->load()?->clientId);
    }

    #[DataProvider('malformedResponses')]
    public function testFailedRefreshPreservesCredentialsAndDoesNotExposeRawFailure(string $response, int $status): void
    {
        $record = AuthFixture::record(1);
        $storage = new InMemoryAuthStorage($record);
        $service = AuthFixture::service($storage, new MockHttpClient(new MockResponse($response, ['http_code' => $status])));
        try {
            $service->accessToken();
            self::fail('Expected refresh failure.');
        } catch (AuthException $exception) {
            self::assertSame($record, $storage->record);
            self::assertStringNotContainsString('secret-marker', $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }
    }

    /** @return iterable<string, array{string, int}> */
    public static function malformedResponses(): iterable
    {
        yield 'server failure' => ['{"error":"secret-marker"}', 500];
        yield 'broken json' => ['secret-marker', 200];
        yield 'list' => ['[]', 200];
        yield 'missing access' => ['{"expires_in":3600}', 200];
        yield 'negative expiry' => ['{"access_token":"secret-marker","expires_in":-1}', 200];
        yield 'scope removed' => ['{"access_token":"secret-marker","expires_in":3600,"scope":"openid"}', 200];
        yield 'explicit null identity' => ['{"access_token":"secret-marker","expires_in":3600,"id_token":null}', 200];
        yield 'explicit null refresh token' => ['{"access_token":"secret-marker","expires_in":3600,"refresh_token":null}', 200];
        yield 'explicit null scope' => ['{"access_token":"secret-marker","expires_in":3600,"scope":null}', 200];
    }

    public function testRemoteDisconnectRetainsRegistrationAndUsesDocumentedDiscovery(): void
    {
        $storage = new InMemoryAuthStorage(AuthFixture::record());
        $client = new MockHttpClient([
            new MockResponse('{"issuer":"https://auth.openai.com","revocation_endpoint":"https://auth.openai.com/api/accounts/oauth/revoke"}'),
            new MockResponse('', ['http_code' => 200]),
        ]);
        AuthFixture::service($storage, $client)->disconnect();
        self::assertNull($storage->record?->access);
        self::assertNull($storage->record?->refresh);
        self::assertSame('issued-client', $storage->record?->clientId);
        self::assertSame(2, $client->getRequestsCount());
    }

    public function testFailedRemoteDisconnectKeepsLocalGrant(): void
    {
        $record = AuthFixture::record();
        $storage = new InMemoryAuthStorage($record);
        $client = new MockHttpClient([
            new MockResponse('{"issuer":"https://auth.openai.com","revocation_endpoint":"https://auth.openai.com/api/accounts/oauth/revoke"}'),
            new MockResponse('secret-marker', ['http_code' => 500]),
        ]);
        try {
            AuthFixture::service($storage, $client)->disconnect();
            self::fail('Expected remote revocation failure.');
        } catch (AuthException $exception) {
            self::assertStringContainsString('local credentials remain saved', $exception->getMessage());
            self::assertSame($record, $storage->record);
            self::assertStringNotContainsString('secret-marker', $exception->getMessage());
        }
    }

    public function testManualCallbackCannotUseForeignOrigin(): void
    {
        $service = AuthFixture::service(new InMemoryAuthStorage(), new MockHttpClient());
        $request = $service->beginAuthorization();
        $this->expectException(AuthException::class);
        $service->completeManualAuthorization($request, 'https://attacker.invalid/auth/callback?code=code&state='.$request->state);
    }

    public function testFailedExchangeRetainsInstallationAndIssuedClientForRetry(): void
    {
        $path = $this->directory.'/auth.json';
        $store = new AuthFileStore($path, new LockFactory(new FlockStore($this->directory)));
        $service = AuthFixture::service($store, new MockHttpClient(new MockResponse('failure', ['http_code' => 500])));
        $request = $service->beginAuthorization();
        $hostId = $store->installationId();
        self::assertFileExists($path);
        try {
            $service->completeAuthorization($request, ['state' => $request->state, 'code' => 'code', 'client_id' => 'issued-client']);
            self::fail('Expected token exchange failure.');
        } catch (AuthException $exception) {
            self::assertStringContainsString('OAuth endpoint failed', $exception->getMessage());
            self::assertSame($hostId, $store->installationId());
            self::assertSame('issued-client', $store->load()?->clientId);
            self::assertNull($store->load()?->access);
        }
        parse_str((string) parse_url($service->beginAuthorization()->url, \PHP_URL_QUERY), $query);
        self::assertSame('issued-client', $query['client_id']);
        self::assertSame($hostId, $query['ext_agent_host_id']);
        self::assertArrayNotHasKey('id_token_hint', $query);
        self::assertArrayNotHasKey('agent_name_hint', $query);
    }

    public function testRefreshAllowsOmittedNonceInNewSignedIdentityButRetainsAuthorizationNonce(): void
    {
        $idToken = JWT::encode(['iss' => OAuthConfig::ISSUER, 'sub' => 'account-one', 'aud' => 'issued-client', 'exp' => time() + 3600], AuthFixture::key(), 'RS256', 'test-key');
        $storage = new InMemoryAuthStorage(AuthFixture::record(1));
        $http = new MockHttpClient([new MockResponse(json_encode(['access_token' => 'new-access', 'expires_in' => 3600, 'id_token' => $idToken], \JSON_THROW_ON_ERROR)), new MockResponse(json_encode(AuthFixture::jwks(), \JSON_THROW_ON_ERROR))]);
        $updated = AuthFixture::service($storage, $http)->refreshCredentials();
        self::assertSame($idToken, $updated->idToken);
        self::assertSame('nonce', $updated->nonce);
        self::assertSame('test-refresh', $updated->refresh);
    }

    public function testRefreshRejectsChangedNonceAndDoesNotOverwriteSavedCredentials(): void
    {
        $record = AuthFixture::record(1);
        $storage = new InMemoryAuthStorage($record);
        $http = new MockHttpClient([new MockResponse(json_encode(['access_token' => 'new-access', 'expires_in' => 3600, 'id_token' => AuthFixture::idToken('wrong-nonce')], \JSON_THROW_ON_ERROR)), new MockResponse(json_encode(AuthFixture::jwks(), \JSON_THROW_ON_ERROR))]);
        try {
            AuthFixture::service($storage, $http)->refreshCredentials();
            self::fail('Expected changed nonce rejection.');
        } catch (AuthException $exception) {
            self::assertStringContainsString('identity claims are invalid', $exception->getMessage());
            self::assertSame($record, $storage->record);
        }
    }
}
