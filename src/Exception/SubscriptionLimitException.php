<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAIChatGPT\Exception;

use Symfony\AI\Platform\Bridge\OpenAIChatGPT\ProviderErrorMessage;
use Symfony\AI\Platform\Exception\RuntimeException;

/** A permanent plan/quota failure, not a transient rate-limit retry signal. */
final class SubscriptionLimitException extends RuntimeException
{
    /** @param array<string, mixed> $error */
    public function __construct(array $error = [], ?int $status = null, ?string $body = null)
    {
        parent::__construct(ProviderErrorMessage::format($error, $status, $body));
    }
}
