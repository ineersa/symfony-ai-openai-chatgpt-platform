<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAIChatGPT\Exception;

use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Validation\ProviderErrorMetadataValidator;
use Symfony\AI\Platform\Exception\BadRequestException;

final class UnmappedBadRequestException extends BadRequestException implements ProviderErrorExceptionInterface
{
    /** @var array<string, string> */
    private readonly array $providerError;

    /** @param array<string, mixed> $error */
    public function __construct(array $error, private readonly ?int $statusCode = null)
    {
        parent::__construct('ChatGPT rejected the request or account permissions.');
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
