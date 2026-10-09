<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAIChatGPT\Tests\Auth;

use Firebase\JWT\JWT;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Auth\AuthException;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Auth\AuthFileStore;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Auth\AuthRecord;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Auth\AuthStorageInterface;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Auth\IdTokenVerifier;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Auth\OAuthConfig;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Auth\OAuthService;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Auth\PendingRefreshDTO;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Tests\Support\AuthFixture;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Tests\Support\InMemoryAuthStorage;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Tests\Support\IsolatedTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

final class OAuthServiceTest extends IsolatedTestCase
{
    #[DataProvider('delayedRestarts')]
    public function testRestartVerifiesPendingIdentityAtReceiptAfterIdExpiryAndRedeemsOnlyReplacement(int $delay, int $refreshCount): void
    {
        $clock = new MockClock('@1900000000');
        $receivedAt = $clock->now()->getTimestamp();
        $path = $this->directory.'/delayed-rotation.json';
        $locks = new LockFactory(new FlockStore($this->directory));
        $store = new AuthFileStore($path, $locks);
        $identity = AuthFixture::record();
        $store->update(static fn () => $identity);
        $rotated = AuthFixture::token('nonce');
        $rotated['id_token'] = AuthFixture::idToken(overrides: ['exp' => $receivedAt + 10, 'iat' => $receivedAt, 'nbf' => $receivedAt]);
        $refreshes = [];
        $keyFetches = 0;
        $previousTimestamp = JWT::$timestamp;
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$refreshes, &$keyFetches, $rotated, $previousTimestamp): MockResponse {
            self::assertSame($previousTimestamp, JWT::$timestamp, 'No SDK clock override may span HTTP work.');
            if ('POST' === $method) {
                parse_str($options['body'], $body);
                $refreshes[] = $body['refresh_token'];

                return new MockResponse(1 === \count($refreshes) ? json_encode($rotated, \JSON_THROW_ON_ERROR) : '{"access_token":"fresh-access","refresh_token":"fresh-refresh","expires_in":3600}');
            }
            ++$keyFetches;

            return new MockResponse(1 === $keyFetches ? '{"error":"secret-marker"}' : json_encode(AuthFixture::jwks(), \JSON_THROW_ON_ERROR), ['http_code' => 1 === $keyFetches ? 503 : 200]);
        });
        $service = new OAuthService($store, $http, new IdTokenVerifier($http, clock: $clock), new OAuthConfig('Test app'), $clock);
        try {
            $service->refreshCredentials();
            self::fail('Expected post-rotation JWKS failure.');
        } catch (AuthException $error) {
            self::assertNull($error->getPrevious());
        }
        $pending = $store->load()?->pendingRefresh;
        self::assertNotNull($pending);
        self::assertSame($receivedAt, $pending->receivedAt);
        self::assertSame($receivedAt + 3600, $pending->expires);
        $clock->modify('+'.$delay.' seconds');
        $restarted = new AuthFileStore($path, $locks);
        self::assertEquals($pending, $restarted->load()?->pendingRefresh);
        $verifier = new IdTokenVerifier($http, clock: $clock);
        try {
            $verifier->verify($pending->idToken, 'issued-client', 'nonce');
            self::fail('Ordinary current-time verification must reject the expired ID token.');
        } catch (AuthException) {
            self::assertSame($previousTimestamp, JWT::$timestamp);
        }
        $worker = new OAuthService($restarted, $http, $verifier, new OAuthConfig('Test app'), $clock);
        self::assertSame(1 === $refreshCount ? 'new-access' : 'fresh-access', $worker->accessToken());
        self::assertSame(1 === $refreshCount ? ['test-refresh'] : ['test-refresh', 'new-refresh'], $refreshes);
        self::assertSame($previousTimestamp, JWT::$timestamp);
        self::assertSame('account-one', $restarted->load()?->subject);
        self::assertNull($restarted->load()?->pendingRefresh);
    }

    /** @return iterable<string, array{int, int}> */
    public static function delayedRestarts(): iterable
    {
        yield 'ID expired but access still current' => [20, 1];
        yield 'ID and access expired' => [4000, 2];
    }

    #[DataProvider('verificationRetries')]
    public function testRotatedRefreshSurvivesJwksFailureAndResumesWithoutAnotherRefresh(bool $restart): void
    {
        $path = $this->directory.'/rotation.json';
        $locks = new LockFactory(new FlockStore($this->directory));
        $store = new AuthFileStore($path, $locks);
        $record = AuthFixture::record();
        $store->update(static fn () => $record);
        $rotated = AuthFixture::token('nonce');
        $rotated['id_token'] = AuthFixture::idToken(kid: 'rotated-key');
        $refreshes = 0;
        $keyFetches = 0;
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$refreshes, &$keyFetches, $rotated): MockResponse {
            if ('POST' === $method) {
                ++$refreshes;
                parse_str($options['body'], $body);
                self::assertSame('test-refresh', $body['refresh_token']);

                return new MockResponse(json_encode($rotated, \JSON_THROW_ON_ERROR));
            }
            ++$keyFetches;
            self::assertStringEndsWith('/jwks.json', $url);
            if (2 === $keyFetches) {
                return new MockResponse('{"error":"secret-marker"}', ['http_code' => 503]);
            }

            return new MockResponse(json_encode(AuthFixture::jwks(1 === $keyFetches ? 'test-key' : 'rotated-key'), \JSON_THROW_ON_ERROR));
        });
        $verifier = new IdTokenVerifier($http, clock: new MockClock('@1900000000'));
        $verifier->verify($record->idToken ?? '', $record->clientId, $record->nonce);
        $service = AuthFixture::service($store, $http, $verifier);
        try {
            $service->refreshCredentials();
            self::fail('Expected temporary verification failure after successful rotation.');
        } catch (AuthException $error) {
            self::assertNull($error->getPrevious());
            self::assertStringNotContainsString('secret-marker', $error->getMessage());
        }
        $pending = (new AuthFileStore($path, $locks))->load();
        self::assertNotNull($pending);
        self::assertNull($pending->access);
        self::assertNull($pending->refresh);
        self::assertSame($record->idToken, $pending->idToken);
        self::assertNotNull($pending->pendingRefresh);
        self::assertSame('new-refresh', $pending->pendingRefresh->refresh);
        self::assertSame(1900003600, $pending->pendingRefresh->expires);
        self::assertSame(0600, fileperms($path) & 0777);
        if ($restart) {
            $service = AuthFixture::service(new AuthFileStore($path, $locks), $http);
        }
        self::assertSame('new-access', $service->accessToken());
        $verified = $store->load();
        self::assertNotNull($verified);
        self::assertNull($verified->pendingRefresh);
        self::assertSame($rotated['id_token'], $verified->idToken);
        self::assertSame('new-refresh', $verified->refresh);
        self::assertSame(1900003600, $verified->expires);
        self::assertSame(1, $refreshes);
        self::assertSame(3, $keyFetches);
    }

    /** @return iterable<string, array{bool}> */
    public static function verificationRetries(): iterable
    {
        yield 'same worker' => [false];
        yield 'restart' => [true];
    }

    public function testExpiredPendingAccessRefreshesReplacementOnlyAfterIdentityVerification(): void
    {
        $identity = AuthFixture::record();
        $pending = new PendingRefreshDTO('expired-access', 'rotated-refresh', 1, AuthFixture::idToken(), $identity->scopes, true, 1900000000);
        $storage = new InMemoryAuthStorage(new AuthRecord($identity->clientId, null, null, 0, $identity->idToken, $identity->issuer, $identity->subject, $identity->scopes, $identity->nonce, $pending));
        $methods = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use ($storage, &$methods): MockResponse {
            $methods[] = $method;
            if ('GET' === $method) {
                return new MockResponse(json_encode(AuthFixture::jwks(), \JSON_THROW_ON_ERROR));
            }
            self::assertNull($storage->load()?->pendingRefresh);
            parse_str($options['body'], $body);
            self::assertSame('rotated-refresh', $body['refresh_token']);

            return new MockResponse('{"access_token":"fresh-access","refresh_token":"fresh-refresh","expires_in":3600}');
        });
        self::assertSame('fresh-access', AuthFixture::service($storage, $http)->accessToken());
        self::assertSame(['GET', 'POST'], $methods);
    }

    public function testDisconnectRevokesPendingReplacementAndRetainsIssuedClient(): void
    {
        $identity = AuthFixture::record();
        $pending = new PendingRefreshDTO('pending-access', 'pending-refresh', 2000000000, AuthFixture::idToken('wrong-nonce'), $identity->scopes, true, 1900000000);
        $storage = new InMemoryAuthStorage(new AuthRecord($identity->clientId, null, null, 0, $identity->idToken, $identity->issuer, $identity->subject, $identity->scopes, $identity->nonce, $pending));
        $http = new MockHttpClient(static function (string $method, string $url, array $options): MockResponse {
            if ('GET' === $method) {
                return new MockResponse('{"issuer":"https://auth.openai.com","revocation_endpoint":"https://auth.openai.com/api/accounts/oauth/revoke"}');
            }
            parse_str($options['body'], $body);
            self::assertSame('pending-refresh', $body['token']);

            return new MockResponse('');
        });
        AuthFixture::service($storage, $http)->disconnect();
        self::assertNull($storage->record?->pendingRefresh);
        self::assertNull($storage->record?->access);
        self::assertSame('issued-client', $storage->record?->clientId);
        self::assertSame(2, $http->getRequestsCount());
    }

    public function testOtherLockedWorkerCanActivatePendingRotationBeforeFirstWorkerResumes(): void
    {
        $path = $this->directory.'/interleaved.json';
        $locks = new LockFactory(new FlockStore($this->directory));
        $first = new AuthFileStore($path, $locks);
        $record = AuthFixture::record(1);
        $first->update(static fn () => $record);
        $http = new MockHttpClient([
            new MockResponse(json_encode(AuthFixture::token('nonce'), \JSON_THROW_ON_ERROR)),
            new MockResponse(json_encode(AuthFixture::jwks(), \JSON_THROW_ON_ERROR)),
        ]);
        $second = AuthFixture::service(new AuthFileStore($path, $locks), $http);
        $barrier = new class($first, static function () use ($second): void {
            self::assertSame('new-access', $second->accessToken());
        }) implements AuthStorageInterface {
            private bool $entered = false;

            public function __construct(private readonly AuthStorageInterface $store, private readonly \Closure $afterPending)
            {
            }

            public function installationId(): string
            {
                return $this->store->installationId();
            }

            public function load(): ?AuthRecord
            {
                return $this->store->load();
            }

            public function update(callable $update): AuthRecord
            {
                $saved = $this->store->update($update);
                if (!$this->entered && null !== $saved->pendingRefresh) {
                    $this->entered = true;
                    // Deterministic barrier after the atomic pending write and lock release, before verification.
                    ($this->afterPending)();
                }

                return $saved;
            }
        };
        self::assertSame('new-access', AuthFixture::service($barrier, $http)->accessToken());
        self::assertSame(2, $http->getRequestsCount());
        self::assertNull($first->load()?->pendingRefresh);
    }

    /** @param array<string, mixed> $claims */
    #[DataProvider('invalidPendingIdentities')]
    public function testUnverifiedPendingRefreshCannotActivateOrReuseConsumedGrant(array $claims, string $kid, bool $badSignature): void
    {
        $record = AuthFixture::record();
        $storage = new InMemoryAuthStorage($record);
        $replacement = AuthFixture::token('nonce');
        $idToken = AuthFixture::idToken(overrides: $claims, kid: $kid);
        if ($badSignature) {
            [$header, $body] = explode('.', $idToken);
            $idToken = $header.'.'.$body.'.secret-marker';
        }
        $replacement['id_token'] = $idToken;
        $refreshes = 0;
        $http = new MockHttpClient(static function (string $method) use ($replacement, &$refreshes): MockResponse {
            if ('POST' === $method) {
                ++$refreshes;
            }

            return new MockResponse(json_encode('POST' === $method ? $replacement : AuthFixture::jwks(), \JSON_THROW_ON_ERROR));
        });
        $service = AuthFixture::service($storage, $http);
        $saved = null;
        foreach ([true, false] as $force) {
            try {
                $service->refreshCredentials($force);
                self::fail('Expected identity verification failure.');
            } catch (AuthException $error) {
                self::assertNull($error->getPrevious());
                self::assertStringNotContainsString('secret-marker', $error->getMessage());
                if (null === $saved) {
                    $saved = $storage->load();
                } else {
                    self::assertSame($saved, $storage->load());
                }
            }
        }
        self::assertNull($storage->record?->access);
        self::assertSame($record->idToken, $storage->record?->idToken);
        self::assertSame($idToken, $storage->record?->pendingRefresh?->idToken);
        self::assertSame('new-refresh', $storage->record->pendingRefresh->refresh);
        // Repeated verification uses only GET; POST is the single consumed refresh request.
        self::assertSame(1, $refreshes);
    }

    /** @return iterable<string, array{array<string, mixed>, string, bool}> */
    public static function invalidPendingIdentities(): iterable
    {
        yield 'audience' => [['aud' => 'wrong-client'], 'test-key', false];
        yield 'issuer' => [['iss' => 'https://attacker.invalid'], 'test-key', false];
        yield 'authorized party' => [['azp' => 'wrong-client'], 'test-key', false];
        yield 'expired at receipt' => [['exp' => 1900000000], 'test-key', false];
        yield 'not yet valid at receipt' => [['nbf' => 1900000001], 'test-key', false];
        yield 'issued in future even with valid nbf' => [['iat' => 1900000001, 'nbf' => 1900000000], 'test-key', false];
        yield 'invalid temporal type' => [['iat' => '1900000000'], 'test-key', false];
        yield 'account binding' => [['sub' => 'wrong-account'], 'test-key', false];
        yield 'unknown signing key' => [[], 'unknown-key', false];
        yield 'bad signature' => [[], 'test-key', true];
    }

    #[DataProvider('identityOnlyGrants')]
    public function testIdentityOnlySignInCanExplicitlyEnablePlanWithSameRegistration(bool $hasCredentials): void
    {
        $nonce = '';
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$nonce, $hasCredentials): MockResponse {
            if (str_ends_with($url, '/jwks.json')) {
                return new MockResponse(json_encode(AuthFixture::jwks(), \JSON_THROW_ON_ERROR));
            }
            parse_str($options['body'], $body);
            $token = AuthFixture::token($nonce);
            if ('new-code' !== $body['code']) {
                $token['scope'] = 'openid profile email';
                if (!$hasCredentials) {
                    unset($token['access_token'], $token['refresh_token']);
                }
            }

            return new MockResponse(json_encode($token, \JSON_THROW_ON_ERROR));
        });
        $storage = new InMemoryAuthStorage();
        $service = AuthFixture::service($storage, $http);
        $ordinary = $service->beginAuthorization();
        parse_str((string) parse_url($ordinary->url, \PHP_URL_QUERY), $query);
        self::assertArrayNotHasKey('prompt', $query);
        self::assertArrayNotHasKey('force_reconsent', $query);
        $nonce = $ordinary->nonce;
        $identity = $service->completeAuthorization($ordinary, ['state' => $ordinary->state, 'client_id' => 'issued-client', 'code' => 'code']);
        self::assertSame('account-one', $identity->subject);
        self::assertNotNull($identity->idToken);
        self::assertSame(['openid', 'profile', 'email'], $identity->scopes);
        $requests = $http->getRequestsCount();
        try {
            $service->accessToken();
            self::fail('Identity-only grants must not enable inference.');
        } catch (AuthException $error) {
            self::assertStringContainsString('login --consent', $error->getMessage());
            self::assertSame($identity, $storage->load());
            self::assertSame($requests, $http->getRequestsCount());
        }
        $consent = $service->beginAuthorization(true);
        parse_str((string) parse_url($consent->url, \PHP_URL_QUERY), $enabled);
        self::assertSame('consent', $enabled['prompt']);
        self::assertArrayNotHasKey('force_reconsent', $enabled);
        self::assertSame('issued-client', $enabled['client_id']);
        self::assertSame(OAuthConfig::SCOPE, $enabled['scope']);
        self::assertSame($query['ext_agent_host_id'], $enabled['ext_agent_host_id']);
        self::assertSame('S256', $enabled['code_challenge_method']);
        self::assertSame($identity->idToken, $enabled['id_token_hint']);
        self::assertNotSame($ordinary->nonce, $consent->nonce);
        self::assertNotSame($ordinary->state, $consent->state);
        self::assertNotSame($ordinary->verifier, $consent->verifier);
        $nonce = $consent->nonce;
        $authorized = $service->completeAuthorization($consent, ['state' => $consent->state, 'code' => 'new-code']);
        self::assertSame('issued-client', $authorized->clientId);
        self::assertSame('account-one', $authorized->subject);
        self::assertContains(OAuthConfig::DIRECT_SCOPE, $authorized->scopes);
        self::assertSame('new-access', $service->accessToken());
        parse_str((string) parse_url($service->beginAuthorization()->url, \PHP_URL_QUERY), $normal);
        self::assertArrayNotHasKey('prompt', $normal);
        self::assertArrayNotHasKey('force_reconsent', $normal);
    }

    /** @return iterable<string, array{bool}> */
    public static function identityOnlyGrants(): iterable
    {
        yield 'with identity credentials' => [true];
        yield 'ID token only' => [false];
    }

    #[DataProvider('invalidIdentityOnlyClaims')]
    public function testIdentityOnlyLoginStillValidatesNonceAndAudience(string $claim, string $value): void
    {
        $nonce = '';
        $http = new MockHttpClient(static function (string $method, string $url) use (&$nonce, $claim, $value): MockResponse {
            $token = AuthFixture::token($nonce);
            $token['scope'] = 'openid';
            $token['id_token'] = AuthFixture::idToken($nonce, [$claim => $value]);

            return new MockResponse(json_encode(str_ends_with($url, '/jwks.json') ? AuthFixture::jwks() : $token, \JSON_THROW_ON_ERROR));
        });
        $storage = new InMemoryAuthStorage();
        $service = AuthFixture::service($storage, $http);
        $request = $service->beginAuthorization();
        $nonce = $request->nonce;
        try {
            $service->completeAuthorization($request, ['state' => $request->state, 'client_id' => 'issued-client', 'code' => 'code']);
            self::fail('Expected identity-only claim rejection.');
        } catch (AuthException) {
            self::assertNull($storage->record?->idToken);
            self::assertNull($storage->record?->access);
            self::assertSame('issued-client', $storage->record?->clientId);
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidIdentityOnlyClaims(): iterable
    {
        yield 'nonce' => ['nonce', 'wrong-nonce'];
        yield 'audience' => ['aud', 'wrong-client'];
    }

    public function testRefreshRemovingDirectScopeDisablesInferenceWithoutDiscardingVerifiedSignIn(): void
    {
        $storage = new InMemoryAuthStorage(AuthFixture::record(1));
        $http = new MockHttpClient(new MockResponse('{"access_token":"new-access","refresh_token":"new-refresh","expires_in":3600,"scope":"openid"}'));
        try {
            AuthFixture::service($storage, $http)->accessToken();
            self::fail('Expected disabled plan usage.');
        } catch (AuthException $error) {
            self::assertStringContainsString('login --consent', $error->getMessage());
            self::assertSame('account-one', $storage->record?->subject);
            self::assertSame('new-refresh', $storage->record->refresh);
            self::assertSame(['openid'], $storage->record->scopes);
        }
    }

    /** @param array<string, mixed> $body */
    #[DataProvider('refreshFailures')]
    public function testRefreshFailurePersistsOnlyConfirmedUnusableTokens(array $body, int $status, bool $terminal): void
    {
        $record = AuthFixture::record();
        $storage = new AuthFileStore($this->directory.'/refresh-errors.json', new LockFactory(new FlockStore($this->directory)));
        $storage->update(static fn () => $record);
        $client = new MockHttpClient(new MockResponse(json_encode($body, \JSON_THROW_ON_ERROR), ['http_code' => $status]));
        $service = AuthFixture::service($storage, $client);
        try {
            $service->refreshCredentials();
            self::fail('Expected refresh failure.');
        } catch (AuthException $error) {
            self::assertNull($error->getPrevious());
            self::assertStringNotContainsString('secret-marker', $error->getMessage());
            $saved = $storage->load();
            self::assertNotNull($saved);
            if ($terminal) {
                self::assertNull($saved->access);
                self::assertNull($saved->refresh);
                self::assertNull($saved->idToken);
                self::assertSame($record->clientId, $saved->clientId);
                parse_str((string) parse_url($service->beginAuthorization()->url, \PHP_URL_QUERY), $query);
                self::assertSame($record->clientId, $query['client_id']);
                self::assertArrayNotHasKey('id_token_hint', $query);
            } else {
                self::assertEquals($record, $saved);
            }
        }
    }

    /** @return iterable<string, array{array<string, mixed>, int, bool}> */
    public static function refreshFailures(): iterable
    {
        foreach (['invalid_grant', 'invalid_refresh_token', 'token_expired', 'refresh_token_expired', 'refresh_token_invalidated', 'refresh_token_reused'] as $code) {
            yield $code => [['error' => $code, 'error_description' => 'secret-marker'], 400, true];
            yield $code.' structured' => [['error' => ['code' => $code, 'message' => 'secret-marker']], 401, true];
        }
        yield 'invalid client' => [['error' => 'invalid_client'], 400, false];
        yield 'unknown' => [['error' => 'unknown', 'message' => 'secret-marker'], 400, false];
        yield 'temporary' => [['error' => 'server_error'], 503, false];
        yield 'temporary terminal-looking body' => [['error' => 'invalid_grant'], 503, false];
    }

    public function testNetworkFailurePreservesRefreshGrantWithoutExceptionChain(): void
    {
        $record = AuthFixture::record();
        $storage = new InMemoryAuthStorage($record);
        $client = new MockHttpClient(static function (): never {
            throw new \Symfony\Component\HttpClient\Exception\TransportException('secret-marker');
        });
        try {
            AuthFixture::service($storage, $client)->refreshCredentials();
            self::fail('Expected network failure.');
        } catch (AuthException $error) {
            self::assertSame($record, $storage->load());
            self::assertNull($error->getPrevious());
            self::assertStringNotContainsString('secret-marker', $error->getMessage());
        }
    }

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

    public function testIdentityOnlyGrantStillRequiresVerifiedIdentity(): void
    {
        $storage = new InMemoryAuthStorage();
        $client = new MockHttpClient(new MockResponse('{"access_token":"a","refresh_token":"r","expires_in":3600,"id_token":"not-verified","scope":"openid"}'));
        $service = AuthFixture::service($storage, $client);
        $request = $service->beginAuthorization();
        try {
            $service->completeAuthorization($request, ['state' => $request->state, 'code' => 'code', 'client_id' => 'issued-client']);
            self::fail('Expected unverified identity rejection.');
        } catch (AuthException $exception) {
            self::assertStringContainsString('signature verification failed', $exception->getMessage());
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
        $idToken = JWT::encode(['iss' => OAuthConfig::ISSUER, 'sub' => 'account-one', 'aud' => 'issued-client', 'exp' => 2000000000], AuthFixture::key(), 'RS256', 'test-key');
        $storage = new InMemoryAuthStorage(AuthFixture::record(1));
        $http = new MockHttpClient([new MockResponse(json_encode(['access_token' => 'new-access', 'expires_in' => 3600, 'id_token' => $idToken], \JSON_THROW_ON_ERROR)), new MockResponse(json_encode(AuthFixture::jwks(), \JSON_THROW_ON_ERROR))]);
        $updated = AuthFixture::service($storage, $http)->refreshCredentials();
        self::assertSame($idToken, $updated->idToken);
        self::assertSame('nonce', $updated->nonce);
        self::assertSame('test-refresh', $updated->refresh);
    }

    public function testRefreshRejectsChangedNonceAndRetainsOnlyPendingReplacement(): void
    {
        $record = AuthFixture::record(1);
        $storage = new InMemoryAuthStorage($record);
        $http = new MockHttpClient([new MockResponse(json_encode(['access_token' => 'new-access', 'expires_in' => 3600, 'id_token' => AuthFixture::idToken('wrong-nonce')], \JSON_THROW_ON_ERROR)), new MockResponse(json_encode(AuthFixture::jwks(), \JSON_THROW_ON_ERROR))]);
        try {
            AuthFixture::service($storage, $http)->refreshCredentials();
            self::fail('Expected changed nonce rejection.');
        } catch (AuthException $exception) {
            self::assertStringContainsString('identity claims are invalid', $exception->getMessage());
            self::assertNull($storage->record?->access);
            self::assertNull($storage->record?->refresh);
            self::assertSame($record->idToken, $storage->record?->idToken);
            self::assertSame('new-access', $storage->record?->pendingRefresh?->access);
            self::assertSame('test-refresh', $storage->record->pendingRefresh->refresh);
        }
    }
}
