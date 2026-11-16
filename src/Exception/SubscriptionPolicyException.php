<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAIChatGPT\Exception;

use Symfony\AI\Platform\Bridge\OpenAIChatGPT\ProviderErrorMessage;
use Symfony\AI\Platform\Exception\RuntimeException;

/** A terminal subscription restriction that must not retry the same request. */
final class SubscriptionPolicyException extends RuntimeException
{
    /** @param array<string, mixed> $error */
    public function __construct(public readonly string $errorCode, array $error = [], ?int $status = null, ?string $body = null)
    {
        parent::__construct(ProviderErrorMessage::format($error ?: ['code' => $errorCode], $status, $body));
    }
}
