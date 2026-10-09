<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAIChatGPT;

use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Auth\OAuthService;
use Symfony\AI\Platform\Bridge\OpenResponses\Contract\OpenResponsesContract;
use Symfony\AI\Platform\Contract;
use Symfony\AI\Platform\ModelCatalog\ModelCatalogInterface;
use Symfony\AI\Platform\Provider;
use Symfony\AI\Platform\ProviderInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class Factory
{
    /** @param non-empty-string $name */
    public static function createProvider(OAuthService $auth, HttpClientInterface $httpClient, ModelCatalogInterface $modelCatalog, ?Contract $contract = null, ?EventDispatcherInterface $eventDispatcher = null, string $name = 'chatgpt'): ProviderInterface
    {
        return new Provider($name, [new ModelClient($httpClient, $auth)], [new ResultConverter()], $modelCatalog, $contract ?? OpenResponsesContract::create(), $eventDispatcher);
    }
}
