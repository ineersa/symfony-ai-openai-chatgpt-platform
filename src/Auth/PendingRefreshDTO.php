<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAIChatGPT\Auth;

/** A rotated grant awaiting identity verification, never an inference credential. */
final readonly class PendingRefreshDTO
{
    /** @param list<string> $scopes */
    public function __construct(
        #[\SensitiveParameter] public string $access,
        #[\SensitiveParameter] public string $refresh,
        public int $expires,
        #[\SensitiveParameter] public string $idToken,
        public array $scopes,
        public bool $verifyIdentity,
    ) {
        if ('' === $access || '' === $refresh || '' === $idToken || $expires <= 0) {
            throw new AuthException('Invalid pending ChatGPT refresh.');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['expires' => $this->expires, 'pending' => true];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        foreach (['access', 'refresh', 'idToken'] as $key) {
            if (!\is_string($data[$key] ?? null)) {
                throw new AuthException('Invalid pending ChatGPT refresh.');
            }
        }
        if (!\is_int($data['expires'] ?? null) || !\is_bool($data['verifyIdentity'] ?? null)
            || !\is_array($data['scopes'] ?? null) || !array_is_list($data['scopes'])) {
            throw new AuthException('Invalid pending ChatGPT refresh.');
        }
        foreach ($data['scopes'] as $scope) {
            if (!\is_string($scope)) {
                throw new AuthException('Invalid pending ChatGPT refresh scope.');
            }
        }

        return new self($data['access'], $data['refresh'], $data['expires'], $data['idToken'], $data['scopes'], $data['verifyIdentity']);
    }
}
