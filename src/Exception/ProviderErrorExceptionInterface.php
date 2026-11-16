<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAIChatGPT\Exception;

interface ProviderErrorExceptionInterface
{
    /** @return array<string, mixed> */
    public function getProviderError(): array;

    public function getStatusCode(): ?int;
}
