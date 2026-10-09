<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAIChatGPT\Tests\Auth;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Auth\AuthException;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Auth\IdTokenVerifier;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Tests\Support\AuthFixture;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class IdTokenVerifierTest extends TestCase
{
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
