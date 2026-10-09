<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAIChatGPT\Auth;

/** Internal signal consumed under the credential storage lock. */
final class UnusableRefreshTokenException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('ChatGPT refresh credentials are no longer usable. Run auth:chatgpt login.');
    }
}
