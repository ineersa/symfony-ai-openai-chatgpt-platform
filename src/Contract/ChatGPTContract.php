<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAIChatGPT\Contract;

use Symfony\AI\Platform\Bridge\OpenResponses\Contract\Message;
use Symfony\AI\Platform\Bridge\OpenResponses\Contract\ToolCallNormalizer;
use Symfony\AI\Platform\Bridge\OpenResponses\Contract\ToolNormalizer;
use Symfony\AI\Platform\Contract;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

final class ChatGPTContract extends Contract
{
    /** @param list<NormalizerInterface> $normalizers */
    public static function create(array $normalizers = []): Contract
    {
        // OpenResponses registers its final assistant normalizer first, so its factory cannot override that class.
        return parent::create([
            ...$normalizers,
            new AssistantMessageNormalizer(),
            new Message\MessageBagNormalizer(),
            new Message\Content\ImageNormalizer(),
            new Message\Content\ImageUrlNormalizer(),
            new Message\Content\TextNormalizer(),
            new ToolNormalizer(),
            new ToolCallNormalizer(),
            new Message\ToolCallMessageNormalizer(),
            new Message\Content\DocumentNormalizer(),
        ]);
    }
}
