<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAIChatGPT\Exception;

use Symfony\AI\Platform\Exception\RuntimeException;

/** A terminal subscription restriction that must not retry the same request. */
final class SubscriptionPolicyException extends RuntimeException
{
    public function __construct(public readonly string $errorCode)
    {
        parent::__construct('subscription_sharing_invalid_user' === $errorCode
            ? 'ChatGPT could not validate the subscriber context. Diagnose credentials; sign in again only after confirmed revocation or terminal refresh failure.'
            : 'ChatGPT rejected subscription access. Check account eligibility, authorization, and supported request capabilities.');
    }
}
