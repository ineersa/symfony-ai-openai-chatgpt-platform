<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAIChatGPT\Auth;

final readonly class AuthRecord
{
    /** @param list<string> $scopes */
    public function __construct(
        public string $clientId,
        #[\SensitiveParameter] public ?string $access,
        #[\SensitiveParameter] public ?string $refresh,
        public int $expires,
        #[\SensitiveParameter] public ?string $idToken,
        public ?string $issuer,
        public ?string $subject,
        public array $scopes,
        public string $nonce,
        public ?PendingRefreshDTO $pendingRefresh = null,
    ) {
        $hasIdentity = null !== $idToken;
        if ('' === $clientId || '' === $idToken || '' === $nonce || $expires < 0
            || ($hasIdentity && (OAuthConfig::ISSUER !== $issuer || null === $subject || '' === $subject))
            || (!$hasIdentity && (null !== $issuer || null !== $subject || null !== $access))
            || (null === $access) !== (null === $refresh) || '' === $access || '' === $refresh
            || (null !== $pendingRefresh && (!$hasIdentity || null !== $access || null !== $refresh))) {
            throw new AuthException('Invalid saved ChatGPT registration.');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_replace(get_object_vars($this), ['pendingRefresh' => $this->pendingRefresh?->toArray()]);
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['clientId' => $this->clientId, 'expires' => $this->expires, 'connected' => null !== $this->access, 'pendingRefresh' => null !== $this->pendingRefresh];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        foreach (['clientId', 'nonce'] as $key) {
            if (!\is_string($data[$key] ?? null)) {
                throw new AuthException('Invalid saved ChatGPT registration.');
            }
        }
        if (!\is_int($data['expires'] ?? null) || !\is_array($data['scopes'] ?? null) || !array_is_list($data['scopes'])) {
            throw new AuthException('Invalid saved ChatGPT registration.');
        }
        foreach ($data['scopes'] as $scope) {
            if (!\is_string($scope)) {
                throw new AuthException('Invalid saved ChatGPT scopes.');
            }
        }
        foreach (['access', 'refresh', 'idToken', 'issuer', 'subject'] as $key) {
            if (!\array_key_exists($key, $data) || (null !== $data[$key] && !\is_string($data[$key]))) {
                throw new AuthException('Invalid saved ChatGPT credentials.');
            }
        }

        $pending = $data['pendingRefresh'] ?? null;
        if (null !== $pending && !\is_array($pending)) {
            throw new AuthException('Invalid pending ChatGPT refresh.');
        }

        return new self($data['clientId'], $data['access'], $data['refresh'], $data['expires'], $data['idToken'], $data['issuer'], $data['subject'], $data['scopes'], $data['nonce'], null === $pending ? null : PendingRefreshDTO::fromArray($pending));
    }

    public function disconnected(): self
    {
        // Keep the issued registration and verified identity for reauthorization, not the revoked grant.
        return new self($this->clientId, null, null, 0, $this->idToken, $this->issuer, $this->subject, [], $this->nonce);
    }

    public static function registration(string $clientId, string $nonce): self
    {
        // The callback issues the registration before token exchange can establish an account identity.
        return new self($clientId, null, null, 0, null, null, null, [], $nonce);
    }
}
