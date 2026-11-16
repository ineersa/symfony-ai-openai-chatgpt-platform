<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAIChatGPT\Exception;

use Symfony\AI\Platform\Bridge\OpenAIChatGPT\ProviderErrorMessage;
use Symfony\AI\Platform\Exception\RuntimeException;

final class UnmappedProviderException extends RuntimeException implements ProviderErrorExceptionInterface
{
    /** @var array<string, mixed> */
    private readonly array $providerError;

    /** @param array<string, mixed> $error */
    public function __construct(array $error, private readonly ?int $statusCode = null, private readonly ?string $responseBody = null)
    {
        parent::__construct(ProviderErrorMessage::format($error, $statusCode, $responseBody));
        $this->providerError = $error;
    }

    public function getProviderError(): array
    {
        return $this->providerError;
    }

    public function getResponseBody(): ?string
    {
        return $this->responseBody;
    }

    public function getStatusCode(): ?int
    {
        return $this->statusCode;
    }
}
