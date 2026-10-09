<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAIChatGPT\Auth;

use League\OAuth2\Client\Provider\GenericProvider;
use Psr\Http\Message\RequestInterface;

/** League's defaults include two fields rejected by public OAuth clients such as OpenAI and xAI. */
final class PublicOAuthProvider extends GenericProvider
{
    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    protected function getAuthorizationParameters(array $options)
    {
        $parameters = parent::getAuthorizationParameters($options);
        unset($parameters['approval_prompt']);

        return $parameters;
    }

    /** @param array<string, mixed> $params */
    protected function getAccessTokenRequest(array $params): RequestInterface
    {
        if (null === ($params['client_secret'] ?? null) || '' === $params['client_secret']) {
            unset($params['client_secret']);
        }

        return parent::getAccessTokenRequest($params);
    }
}
