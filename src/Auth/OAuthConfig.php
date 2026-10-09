<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAIChatGPT\Auth;

final readonly class OAuthConfig
{
    public const string ISSUER = 'https://auth.openai.com';
    public const string RESOURCE = 'https://api.openai.com/v1';
    public const string DIRECT_SCOPE = 'chatgpt.tokens.use.direct';
    public const string SCOPE = 'openid profile email offline_access resource.invoke chatgpt.tokens.use.direct';
    public const string USAGE_URL = 'https://chatgpt.com/settings/usage';
    public const int DEFAULT_PORT = 1455;

    public function __construct(public string $appName, public int $port = self::DEFAULT_PORT, public float $callbackTimeout = 300.0)
    {
        if ('' === trim($appName) || $port < 1 || $port > 65535 || $callbackTimeout <= 0) {
            throw new \InvalidArgumentException('Invalid OAuth application or callback configuration.');
        }
    }

    public function redirectUri(): string
    {
        return 'http://127.0.0.1:'.$this->port.'/auth/callback';
    }
}
