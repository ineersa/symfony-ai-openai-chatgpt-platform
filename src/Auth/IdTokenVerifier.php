<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAIChatGPT\Auth;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class IdTokenVerifier
{
    private const string JWKS_CACHE_KEY = 'chatgpt.issuer.jwks';

    public function __construct(private HttpClientInterface $httpClient, private CacheInterface $cache = new ArrayAdapter())
    {
    }

    /** @return array{issuer: string, subject: string} */
    public function verify(#[\SensitiveParameter] string $token, string $clientId, string $nonce, bool $requireNonce = true): array
    {
        if ('' === $nonce) {
            throw new AuthException('An authorization nonce is required for identity verification.');
        }
        try {
            $header = JWT::jsonDecode(JWT::urlsafeB64Decode(explode('.', $token)[0]));
            $keys = JWK::parseKeySet($this->signingKeys(), 'RS256');
            if (\is_string($header->kid ?? null) && !isset($keys[$header->kid])) {
                // A rotated key may appear before the cached set expires. Fetch once, never bypass verification.
                $this->cache->delete(self::JWKS_CACHE_KEY);
                $keys = JWK::parseKeySet($this->signingKeys(), 'RS256');
            }
            $claims = JWT::decode($token, $keys);
        } catch (\Throwable) {
            // Deliberately discard library/HTTP exception payloads, which can include credentials or bodies.
            throw new AuthException('ChatGPT ID-token signature verification failed.');
        }
        $audience = \is_array($claims->aud ?? null) ? $claims->aud : [$claims->aud ?? null];
        foreach ($audience as $audienceId) {
            if (!\is_string($audienceId)) {
                throw new AuthException('ChatGPT ID-token audience is invalid.');
            }
        }
        if (OAuthConfig::ISSUER !== ($claims->iss ?? null) || !\in_array($clientId, $audience, true)
            || !\is_int($claims->exp ?? null) || $claims->exp <= time()
            || !\is_string($claims->sub ?? null) || '' === $claims->sub
            || (($requireNonce || property_exists($claims, 'nonce')) && (!\is_string($claims->nonce ?? null) || !hash_equals($nonce, $claims->nonce)))) {
            throw new AuthException('ChatGPT ID-token identity claims are invalid.');
        }
        if ((\count($audience) > 1 || isset($claims->azp)) && $clientId !== ($claims->azp ?? null)) {
            throw new AuthException('ChatGPT ID-token authorized party is invalid.');
        }

        return ['issuer' => $claims->iss, 'subject' => $claims->sub];
    }

    /** @return array<string, mixed> */
    private function signingKeys(): array
    {
        return $this->cache->get(self::JWKS_CACHE_KEY, function (ItemInterface $item): array {
            $response = $this->httpClient->request('GET', OAuthConfig::ISSUER.'/.well-known/jwks.json', ['timeout' => 15, 'max_duration' => 30, 'max_redirects' => 0]);
            $jwks = $response->toArray();
            // Restrict to the issuer's advertised signing algorithm, regardless of token header input.
            foreach ($jwks['keys'] ?? [] as $key) {
                if (isset($key['alg']) && 'RS256' !== $key['alg']) {
                    throw new AuthException('Unsupported ID-token signing key.');
                }
            }
            JWK::parseKeySet($jwks, 'RS256');
            $item->expiresAfter(3600);

            return $jwks;
        });
    }
}
