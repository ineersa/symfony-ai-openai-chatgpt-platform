<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAIChatGPT\Tests\Auth;

use Firebase\JWT\JWT;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Auth\AuthException;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Auth\IdTokenVerifier;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Tests\Support\AuthFixture;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class IdTokenVerifierTest extends TestCase
{
    public function testTrustedReceiptVerificationRestoresSdkClockAcrossSuccessAndFailure(): void
    {
        $clock = new MockClock('@1900000100');
        $previous = JWT::$timestamp;
        JWT::$timestamp = 123456;
        try {
            $http = new MockHttpClient(static function (): MockResponse {
                self::assertSame(123456, JWT::$timestamp);

                return new MockResponse(json_encode(AuthFixture::jwks(), \JSON_THROW_ON_ERROR));
            });
            $verifier = new IdTokenVerifier($http, clock: $clock);
            $token = AuthFixture::idToken(overrides: ['exp' => 1900000010, 'iat' => 1900000000, 'nbf' => 1900000000]);
            self::assertSame('account-one', $verifier->verifyReceived($token, 'issued-client', 'nonce', 1900000000)['subject']);
            self::assertSame(123456, JWT::$timestamp);
            foreach ([$token, 'broken.secret-marker.signature'] as $invalid) {
                try {
                    $verifier->verify($invalid, 'issued-client', 'nonce');
                    self::fail('Current-time validation must reject expired or invalid identity.');
                } catch (AuthException $error) {
                    self::assertNull($error->getPrevious());
                    self::assertSame(123456, JWT::$timestamp);
                }
            }
            [$header, $body] = explode('.', $token);
            try {
                $verifier->verifyReceived($header.'.'.$body.'.broken-signature', 'issued-client', 'nonce', 1900000000);
                self::fail('Receipt verification must still reject bad signatures.');
            } catch (AuthException) {
                self::assertSame(123456, JWT::$timestamp);
            }
        } finally {
            JWT::$timestamp = $previous;
        }
    }

    public function testReceiptCannotBeInTheFuture(): void
    {
        $http = new MockHttpClient(static function (): never { self::fail('Invalid receipt must fail before HTTP.'); });
        $verifier = new IdTokenVerifier($http, clock: new MockClock('@1900000000'));
        $this->expectException(AuthException::class);
        $verifier->verifyReceived(AuthFixture::idToken(), 'issued-client', 'nonce', 1900000001);
    }

    public function testIssuerSigningKeysUseInjectedSymfonyCacheAcrossVerifierInstances(): void
    {
        $cache = new ArrayAdapter();
        $http = new MockHttpClient(new MockResponse(json_encode(AuthFixture::jwks(), \JSON_THROW_ON_ERROR)));
        $first = new IdTokenVerifier($http, $cache);
        $first->verify(AuthFixture::idToken(), 'issued-client', 'nonce');
        $first->verify(AuthFixture::idToken(), 'issued-client', 'nonce');
        (new IdTokenVerifier($http, $cache))->verify(AuthFixture::idToken(), 'issued-client', 'nonce');
        self::assertSame(1, $http->getRequestsCount());
    }

    public function testValidatesSignatureAndRequiredClaims(): void
    {
        $verifier = $this->verifier();
        self::assertSame(['issuer' => 'https://auth.openai.com', 'subject' => 'account-one'], $verifier->verify(AuthFixture::idToken(), 'issued-client', 'nonce'));
    }

    /** @param array<string, mixed> $claims */
    #[DataProvider('invalidClaims')]
    public function testRejectsInvalidClaims(array $claims): void
    {
        $this->expectException(AuthException::class);
        $this->verifier()->verify(AuthFixture::idToken(overrides: $claims), 'issued-client', 'nonce');
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidClaims(): iterable
    {
        yield 'issuer' => [['iss' => 'https://attacker.invalid']];
        yield 'audience' => [['aud' => 'another-client']];
        yield 'expired' => [['exp' => 1]];
        yield 'missing expiry' => [['exp' => null]];
        yield 'nonce' => [['nonce' => 'wrong']];
        yield 'subject' => [['sub' => '']];
        yield 'multiple audience without azp' => [['aud' => ['issued-client', 'other']]];
    }

    public function testRejectsBadSignatureWithoutLeakingToken(): void
    {
        $token = AuthFixture::idToken();
        [$header, $body] = explode('.', $token);
        $this->expectException(AuthException::class);
        $this->expectExceptionMessage('signature verification failed');
        $this->verifier()->verify($header.'.'.$body.'.broken-secret-signature', 'issued-client', 'nonce');
    }

    private function verifier(): IdTokenVerifier
    {
        return new IdTokenVerifier(new MockHttpClient(new MockResponse(json_encode(AuthFixture::jwks(), \JSON_THROW_ON_ERROR))));
    }
}
