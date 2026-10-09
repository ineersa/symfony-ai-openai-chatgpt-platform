<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAIChatGPT\Auth;

use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class OAuthService
{
    public function __construct(
        private AuthStorageInterface $storage,
        private HttpClientInterface $httpClient,
        private IdTokenVerifier $idTokenVerifier,
        private OAuthConfig $config,
        private ClockInterface $clock = new NativeClock(),
    ) {
    }

    public function beginAuthorization(): AuthorizationRequestDTO
    {
        $hostId = $this->storage->installationId();
        $record = $this->storage->load();
        $provider = new PublicOAuthProvider([
            'clientId' => $record->clientId ?? 'dynamic_agent_client',
            'redirectUri' => $this->config->redirectUri(),
            'urlAuthorize' => OAuthConfig::ISSUER.'/api/accounts/authorize',
            'urlAccessToken' => OAuthConfig::ISSUER.'/api/accounts/oauth/token',
            'urlResourceOwnerDetails' => OAuthConfig::ISSUER.'/api/accounts/oauth/userinfo',
            'pkceMethod' => 'S256',
            'scopeSeparator' => ' ',
        ]);
        $nonce = bin2hex(random_bytes(32));
        $options = [
            'scope' => OAuthConfig::SCOPE,
            'nonce' => $nonce,
            'resource' => OAuthConfig::RESOURCE,
            'ext_agent_host_id' => $hostId,
        ];
        if (null === $record) {
            $options['agent_name_hint'] = $this->config->appName;
        }
        if (null !== $record?->access && null !== $record->idToken) {
            $options['id_token_hint'] = $record->idToken;
        }
        $url = $provider->getAuthorizationUrl($options);
        $verifier = $provider->getPkceCode();
        if (null === $verifier) {
            throw new AuthException('OAuth provider did not create a PKCE verifier.');
        }

        return new AuthorizationRequestDTO($url, $provider->getState(), $nonce, $verifier, $record?->clientId);
    }

    /** @param array<string, mixed> $callback */
    public function completeAuthorization(#[\SensitiveParameter] AuthorizationRequestDTO $request, #[\SensitiveParameter] array $callback): AuthRecord
    {
        if (!\is_string($callback['state'] ?? null) || !hash_equals($request->state, $callback['state'])) {
            throw new AuthException('OAuth callback state mismatch.');
        }
        if (isset($callback['error'])) {
            throw new AuthException('ChatGPT authorization was declined or failed.');
        }
        $clientId = $callback['client_id'] ?? $request->registeredClientId;
        if (!\is_string($clientId) || '' === trim($clientId) || 'dynamic_agent_client' === $clientId) {
            throw new AuthException('OAuth registration callback is missing the issued client ID.');
        }
        if (null !== $request->registeredClientId && $request->registeredClientId !== $clientId) {
            throw new AuthException('OAuth callback changed the registered client ID.');
        }
        if (!\is_string($callback['code'] ?? null) || '' === $callback['code']) {
            throw new AuthException('OAuth callback is missing an authorization code.');
        }
        $previous = $this->storage->load();
        $this->assertRegistration($previous, $request->registeredClientId);
        $this->storage->update(function (?AuthRecord $current) use ($request, $clientId): AuthRecord {
            $this->assertRegistration($current, $request->registeredClientId);

            // Preserve the issued client even if exchange/verification fails; retry must not register again.
            return $current ?? AuthRecord::registration($clientId, $request->nonce);
        });

        return $this->storage->update(function (?AuthRecord $current) use ($request, $callback, $clientId): AuthRecord {
            $this->assertRegistration($current, $clientId);
            $token = $this->requestToken([
                'grant_type' => 'authorization_code',
                'client_id' => $clientId,
                'code' => $callback['code'],
                'code_verifier' => $request->verifier,
                'redirect_uri' => $this->config->redirectUri(),
                'resource' => OAuthConfig::RESOURCE,
            ]);

            return $this->tokenRecord($token, $clientId, $request->nonce, $current);
        });
    }

    public function completeManualAuthorization(#[\SensitiveParameter] AuthorizationRequestDTO $request, #[\SensitiveParameter] string $url): AuthRecord
    {
        $parts = parse_url(trim($url));
        $expected = parse_url($this->config->redirectUri());
        if (false === $parts || false === $expected || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw new AuthException('Paste the complete OAuth callback URL.');
        }
        foreach (['scheme', 'host', 'port', 'path'] as $key) {
            if (($parts[$key] ?? null) !== ($expected[$key] ?? null)) {
                throw new AuthException('The pasted URL does not match the OAuth callback address.');
            }
        }

        return $this->completeAuthorization($request, ManualCodeParser::parse($url));
    }

    public function accessToken(): string
    {
        $record = $this->refreshCredentials(false);
        if (null === $record->access) {
            throw new AuthException('ChatGPT is disconnected. Run auth:chatgpt login.');
        }

        return $record->access;
    }

    public function refreshCredentials(bool $force = true): AuthRecord
    {
        $unusable = false;
        $updated = $this->storage->update(function (?AuthRecord $record) use ($force, &$unusable): AuthRecord {
            if (null === $record || null === $record->access || null === $record->refresh) {
                throw new AuthException('ChatGPT is not connected. Run auth:chatgpt login.');
            }
            if (!\in_array(OAuthConfig::DIRECT_SCOPE, $record->scopes, true)) {
                throw new AuthException('ChatGPT credentials lack direct-token permission. Reauthorize with auth:chatgpt login.');
            }
            if (!$force && $record->expires > $this->clock->now()->getTimestamp() + 60) {
                return $record;
            }
            // The store re-reads under the lock before this network call, so another worker's rotation wins.
            try {
                $token = $this->requestToken([
                    'grant_type' => 'refresh_token',
                    'client_id' => $record->clientId,
                    'refresh_token' => $record->refresh,
                    'resource' => OAuthConfig::RESOURCE,
                ]);
            } catch (UnusableRefreshTokenException) {
                $unusable = true;

                // Persist removal under this lock before surfacing the terminal failure.
                return AuthRecord::registration($record->clientId, $record->nonce);
            }

            return $this->tokenRecord($token, $record->clientId, null, $record);
        });
        if ($unusable) {
            throw new AuthException('ChatGPT refresh credentials are no longer usable. Run auth:chatgpt login.');
        }

        return $updated;
    }

    public function disconnect(): void
    {
        $this->storage->update(function (?AuthRecord $record): AuthRecord {
            if (null === $record) {
                throw new AuthException('No ChatGPT registration is saved.');
            }
            if (null === $record->refresh) {
                return $record;
            }
            $discovery = $this->requestJson('GET', OAuthConfig::ISSUER.'/.well-known/openid-configuration');
            $endpoint = $discovery['revocation_endpoint'] ?? null;
            if (OAuthConfig::ISSUER !== ($discovery['issuer'] ?? null) || !\is_string($endpoint)
                || 'https' !== parse_url($endpoint, \PHP_URL_SCHEME) || 'auth.openai.com' !== parse_url($endpoint, \PHP_URL_HOST)
                || null !== parse_url($endpoint, \PHP_URL_USER) || null !== parse_url($endpoint, \PHP_URL_PASS)) {
                throw new AuthException('OIDC discovery did not provide a trusted revocation endpoint.');
            }
            try {
                $response = $this->httpClient->request('POST', $endpoint, [
                    'body' => ['token' => $record->refresh, 'token_type_hint' => 'refresh_token', 'client_id' => $record->clientId],
                    'timeout' => 15, 'max_duration' => 30, 'max_redirects' => 0,
                ]);
                if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
                    throw new AuthException('Revocation failed.');
                }
                $response->getContent(false);
            } catch (\Throwable) {
                throw new AuthException('ChatGPT remote disconnect failed; local credentials remain saved.');
            }

            return $record->disconnected();
        });
    }

    /**
     * @param array<string, string> $body
     *
     * @return array<string, mixed>
     */
    private function requestToken(#[\SensitiveParameter] array $body): array
    {
        return $this->requestJson('POST', OAuthConfig::ISSUER.'/api/accounts/oauth/token', ['body' => $body]);
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function requestJson(string $method, string $url, #[\SensitiveParameter] array $options = []): array
    {
        try {
            $response = $this->httpClient->request($method, $url, $options + ['timeout' => 15, 'max_duration' => 30, 'max_redirects' => 0, 'headers' => ['Accept' => 'application/json']]);
            $status = $response->getStatusCode();
            $data = $response->toArray(false);
            if ($status >= 400 && $status < 500 && 'refresh_token' === ($options['body']['grant_type'] ?? null)) {
                $error = $data['error'] ?? null;
                $code = \is_array($error) ? ($error['code'] ?? $error['type'] ?? null) : $error;
                if (\in_array($code, ['invalid_grant', 'invalid_refresh_token', 'token_expired', 'refresh_token_expired', 'refresh_token_invalidated', 'refresh_token_reused'], true)) {
                    throw new UnusableRefreshTokenException();
                }
            }
            if ($status < 200 || $status >= 300) {
                throw new AuthException('OAuth endpoint rejected the request.');
            }
            if (array_is_list($data)) {
                throw new AuthException('OAuth endpoint returned a list.');
            }

            return $data;
        } catch (UnusableRefreshTokenException $exception) {
            throw $exception;
        } catch (\Throwable) {
            throw new AuthException('ChatGPT OAuth endpoint failed or returned invalid data.');
        }
    }

    /** @param array<string, mixed> $token */
    private function tokenRecord(#[\SensitiveParameter] array $token, string $clientId, ?string $nonce, ?AuthRecord $previous): AuthRecord
    {
        $access = $token['access_token'] ?? null;
        $refresh = \array_key_exists('refresh_token', $token) ? $token['refresh_token'] : (null === $nonce ? $previous?->refresh : null);
        $idToken = \array_key_exists('id_token', $token) ? $token['id_token'] : (null === $nonce ? $previous?->idToken : null);
        $expiry = $token['expires_in'] ?? null;
        $scope = \array_key_exists('scope', $token) ? $token['scope'] : (null === $nonce ? implode(' ', $previous->scopes ?? []) : null);
        if (!\is_string($access) || '' === $access || !\is_string($refresh) || '' === $refresh
            || !\is_string($idToken) || '' === $idToken || !\is_int($expiry) || $expiry <= 0 || $expiry > 31536000
            || !\is_string($scope) || '' === trim($scope)) {
            throw new AuthException('ChatGPT token response is missing valid credentials, identity or expiry.');
        }
        $scopes = preg_split('/\s+/', trim($scope)) ?: [];
        if (!\in_array(OAuthConfig::DIRECT_SCOPE, $scopes, true)) {
            throw new AuthException('ChatGPT grant did not include direct-token permission.');
        }
        $expectedNonce = $nonce ?? $previous?->nonce;
        if (null === $expectedNonce) {
            throw new AuthException('No verified authorization nonce is available.');
        }
        if (null !== $nonce || isset($token['id_token'])) {
            // OIDC refresh may omit nonce, but a returned nonce must match the original authorization.
            $identity = $this->idTokenVerifier->verify($idToken, $clientId, $expectedNonce, null !== $nonce);
            $this->assertIdentity($previous, $identity['issuer'], $identity['subject']);
        } elseif (null !== $previous && null !== $previous->issuer && null !== $previous->subject) {
            $identity = ['issuer' => $previous->issuer, 'subject' => $previous->subject];
        } else {
            throw new AuthException('No verified registration is available for refresh.');
        }

        return new AuthRecord($clientId, $access, $refresh, $this->clock->now()->getTimestamp() + $expiry, $idToken, $identity['issuer'], $identity['subject'], $scopes, $expectedNonce);
    }

    private function assertRegistration(?AuthRecord $record, ?string $clientId): void
    {
        if ($record?->clientId !== $clientId) {
            throw new AuthException('ChatGPT registration changed during authorization. Start login again.');
        }
    }

    private function assertIdentity(?AuthRecord $previous, string $issuer, string $subject): void
    {
        if (null !== $previous?->idToken && ($previous->issuer !== $issuer || $previous->subject !== $subject)) {
            throw new AuthException('ChatGPT reauthorization changed the verified account identity.');
        }
    }
}
