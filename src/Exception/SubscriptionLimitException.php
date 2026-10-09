<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAIChatGPT\Exception;

use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Auth\OAuthConfig;
use Symfony\AI\Platform\Exception\RuntimeException;

/** A permanent plan/quota failure, not a transient rate-limit retry signal. */
final class SubscriptionLimitException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('ChatGPT subscription usage is unavailable. Manage usage at '.OAuthConfig::USAGE_URL);
    }
}
