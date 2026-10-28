<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAIChatGPT\Exception;

use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Validation\ProviderErrorMetadataValidator;
use Symfony\AI\Platform\Exception\RuntimeException;

final class UnmappedProviderException extends RuntimeException implements ProviderErrorExceptionInterface
{
    /** @var array<string, string> */
    private readonly array $providerError;

    /** @param array<string, mixed> $error */
    public function __construct(array $error, private readonly ?int $statusCode = null)
    {
        parent::__construct('ChatGPT request failed.');
        $this->providerError = ProviderErrorMetadataValidator::validate($error);
    }

    public function getProviderError(): array
    {
        return $this->providerError;
    }

    public function getStatusCode(): ?int
    {
        return $this->statusCode;
    }
}
