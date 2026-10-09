<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAIChatGPT\Auth;

final readonly class AuthorizationRequestDTO
{
    public function __construct(#[\SensitiveParameter] public string $url, #[\SensitiveParameter] public string $state, #[\SensitiveParameter] public string $nonce, #[\SensitiveParameter] public string $verifier, public ?string $registeredClientId)
    {
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['registeredClientId' => $this->registeredClientId];
    }
}
