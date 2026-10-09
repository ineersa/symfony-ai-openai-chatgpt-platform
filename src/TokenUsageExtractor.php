<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAIChatGPT;

use Symfony\AI\Platform\Bridge\OpenResponses\TokenUsageExtractor as OpenResponsesTokenUsageExtractor;
use Symfony\AI\Platform\Result\RawResultInterface;
use Symfony\AI\Platform\TokenUsage\TokenUsage;

final class TokenUsageExtractor extends OpenResponsesTokenUsageExtractor
{
    public function extract(RawResultInterface $rawResult, array $options = []): ?TokenUsage
    {
        // This bridge is always SSE. Usage comes from stream events, never an eager JSON body read.
        return null;
    }
}
