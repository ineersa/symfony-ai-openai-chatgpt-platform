<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAIChatGPT;

use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Exception\SubscriptionLimitException;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Exception\SubscriptionPolicyException;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Exception\UnmappedBadRequestException;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Exception\UnmappedProviderException;
use Symfony\AI\Platform\Exception\AuthenticationException;
use Symfony\AI\Platform\Exception\ContentFilterException;
use Symfony\AI\Platform\Exception\ExceedContextSizeException;
use Symfony\AI\Platform\Exception\RateLimitExceededException;
use Symfony\AI\Platform\Exception\ServerException;

final class ProviderErrorMapper
{
    /** @param array<string, mixed> $error */
    public static function throwError(array $error, ?int $status = null, ?string $body = null): never
    {
        $message = ProviderErrorMessage::format($error, $status, $body);
        $codes = array_filter([$error['code'] ?? null, $error['type'] ?? null], static fn (mixed $value): bool => \is_string($value));
        if ([] !== array_intersect($codes, ['subscription_sharing_usage_limit_exceeded', 'insufficient_quota', 'usage_limit_reached', 'subscription_limit_reached', 'billing_hard_limit_reached'])) {
            throw new SubscriptionLimitException($error, $status, $body);
        }
        foreach (['subscription_sharing_user_not_eligible', 'subscription_sharing_unsupported_capability', 'subscription_sharing_route_not_supported', 'chatpass_v2_scope_not_authorized', 'chatpass_v2_invalid_authorization_context', 'subscription_sharing_invalid_user'] as $code) {
            if (\in_array($code, $codes, true)) {
                throw new SubscriptionPolicyException($code, $error, $status, $body);
            }
        }
        if ([] !== array_intersect($codes, ['subscription_sharing_usage_unavailable', 'subscription_sharing_user_unavailable'])) {
            throw new ServerException($status, $message);
        }
        if (\in_array('context_length_exceeded', $codes, true)) {
            throw new ExceedContextSizeException($message);
        }
        if (\in_array('content_filter', $codes, true)) {
            throw new ContentFilterException($message);
        }
        if (401 === $status || [] !== array_intersect($codes, ['invalid_api_key', 'invalid_token', 'token_expired'])) {
            throw new AuthenticationException($message);
        }
        if (429 === $status || [] !== array_intersect($codes, ['rate_limit_exceeded', 'rate_limit_error', 'too_many_requests'])) {
            throw new RateLimitExceededException(null, $message);
        }
        if ((null !== $status && $status >= 500) || [] !== array_intersect($codes, ['server_error', 'internal_error', 'server_is_overloaded', 'service_unavailable_error'])) {
            throw new ServerException($status, $message);
        }
        if (400 === $status || 403 === $status) {
            throw new UnmappedBadRequestException($error, $status, $body);
        }

        throw new UnmappedProviderException($error, $status, $body);
    }
}
