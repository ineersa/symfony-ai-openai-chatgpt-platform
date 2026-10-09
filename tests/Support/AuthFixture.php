<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAIChatGPT\Tests\Support;

use Firebase\JWT\JWT;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Auth\AuthRecord;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Auth\AuthStorageInterface;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Auth\IdTokenVerifier;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Auth\OAuthConfig;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Auth\OAuthService;
use Symfony\Component\Clock\MockClock;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class AuthFixture
{
    private static ?\OpenSSLAsymmetricKey $key = null;

    public static function key(): \OpenSSLAsymmetricKey
    {
        if (null === self::$key) {
            $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => \OPENSSL_KEYTYPE_RSA]);
            if (false === $key) {
                throw new \RuntimeException('Test RSA key generation failed.');
            }
            self::$key = $key;
        }

        return self::$key;
    }

    /** @return array<string, mixed> */
    public static function jwks(string $kid = 'test-key'): array
    {
        $details = openssl_pkey_get_details(self::key());
        if (false === $details) {
            throw new \RuntimeException('Test RSA public key extraction failed.');
        }

        return ['keys' => [['kty' => 'RSA', 'alg' => 'RS256', 'use' => 'sig', 'kid' => $kid, 'n' => JWT::urlsafeB64Encode($details['rsa']['n']), 'e' => JWT::urlsafeB64Encode($details['rsa']['e'])]]];
    }

    /** @param array<string, mixed> $overrides */
    public static function idToken(string $nonce = 'nonce', array $overrides = [], string $kid = 'test-key'): string
    {
        return JWT::encode(array_replace(['iss' => OAuthConfig::ISSUER, 'sub' => 'account-one', 'aud' => 'issued-client', 'exp' => 2000000000, 'nonce' => $nonce], $overrides), self::key(), 'RS256', $kid);
    }

    public static function record(int $expires = 2000000000): AuthRecord
    {
        return new AuthRecord('issued-client', 'test-access', 'test-refresh', $expires, self::idToken(), OAuthConfig::ISSUER, 'account-one', [OAuthConfig::DIRECT_SCOPE], 'nonce');
    }

    /** @return array<string, mixed> */
    public static function token(string $nonce): array
    {
        return ['access_token' => 'new-access', 'refresh_token' => 'new-refresh', 'expires_in' => 3600, 'id_token' => self::idToken($nonce), 'scope' => OAuthConfig::SCOPE];
    }

    public static function service(AuthStorageInterface $storage, HttpClientInterface $httpClient, ?IdTokenVerifier $verifier = null): OAuthService
    {
        $clock = new MockClock('@1900000000');

        return new OAuthService($storage, $httpClient, $verifier ?? new IdTokenVerifier($httpClient, clock: $clock), new OAuthConfig('Test app'), $clock);
    }

    /** @param list<array<string, mixed>> $events */
    public static function sse(array $events): string
    {
        return implode('', array_map(static fn (array $event): string => 'data: '.json_encode($event, \JSON_THROW_ON_ERROR)."\n\n", $events));
    }
}
