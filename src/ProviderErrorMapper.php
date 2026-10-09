<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAIChatGPT;

use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Auth\OAuthConfig;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Exception\SubscriptionLimitException;
use Symfony\AI\Platform\Exception\AuthenticationException;
use Symfony\AI\Platform\Exception\BadRequestException;
use Symfony\AI\Platform\Exception\ContentFilterException;
use Symfony\AI\Platform\Exception\ExceedContextSizeException;
use Symfony\AI\Platform\Exception\RateLimitExceededException;
use Symfony\AI\Platform\Exception\RuntimeException;
use Symfony\AI\Platform\Exception\ServerException;

final class ProviderErrorMapper
{
    /** @param array<string, mixed> $error */
    public static function throwError(array $error, ?int $status = null): never
    {
        $codes = array_filter([$error['code'] ?? null, $error['type'] ?? null], static fn (mixed $value): bool => \is_string($value));
        if ([] !== array_intersect($codes, ['insufficient_quota', 'usage_limit_reached', 'subscription_limit_reached', 'billing_hard_limit_reached'])) {
            throw new SubscriptionLimitException();
        }
        if (\in_array('context_length_exceeded', $codes, true)) {
            throw new ExceedContextSizeException('ChatGPT context exceeds the model limit.');
        }
        if (\in_array('content_filter', $codes, true)) {
            throw new ContentFilterException('ChatGPT declined the content.');
        }
        if (401 === $status || [] !== array_intersect($codes, ['invalid_api_key', 'invalid_token', 'token_expired'])) {
            throw new AuthenticationException('ChatGPT authentication failed. Reauthorize with auth:chatgpt login.');
        }
        if (429 === $status || [] !== array_intersect($codes, ['rate_limit_exceeded', 'rate_limit_error', 'too_many_requests'])) {
            throw new RateLimitExceededException(null, 'ChatGPT request was rate limited. Manage usage at '.OAuthConfig::USAGE_URL);
        }
        if ((null !== $status && $status >= 500) || [] !== array_intersect($codes, ['server_error', 'internal_error', 'server_is_overloaded', 'service_unavailable_error'])) {
            throw new ServerException($status, 'ChatGPT is temporarily unavailable.');
        }
        if (400 === $status || 403 === $status) {
            throw new BadRequestException('ChatGPT rejected the request or account permissions.');
        }

        // Never copy an error body/message into exceptions that the host logs.
        throw new RuntimeException('ChatGPT request failed.');
    }
}
