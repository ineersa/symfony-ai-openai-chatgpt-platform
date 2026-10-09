<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAIChatGPT;

use Symfony\AI\Platform\Bridge\OpenResponses\ResultConverter as OpenResponsesResultConverter;
use Symfony\AI\Platform\Exception\InvalidArgumentException;
use Symfony\AI\Platform\Result\RawHttpResult;
use Symfony\AI\Platform\Result\RawResultInterface;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\Result\StreamResult;

final class ResultConverter extends OpenResponsesResultConverter
{
    public function getTokenUsageExtractor(): TokenUsageExtractor
    {
        return new TokenUsageExtractor();
    }

    public function convert(RawResultInterface|RawHttpResult $result, array $options = []): ResultInterface
    {
        if (!$result instanceof RawHttpResult) {
            throw new InvalidArgumentException('ChatGPT conversion requires an HTTP result.');
        }
        $status = $result->getObject()->getStatusCode();
        if ($status < 200 || $status >= 300) {
            try {
                $data = $result->getObject()->toArray(false);
            } catch (\Throwable) {
                ProviderErrorMapper::throwError([], $status);
            }
            ProviderErrorMapper::throwError(\is_array($data['error'] ?? null) ? $data['error'] : [], $status);
        }

        // The direct-token preview is always streamed, even if a consumer omitted its stream option.
        $messages = new MessageStream($result);
        $converted = parent::convert(new RawHttpResult($result->getObject(), $messages), array_replace($options, ['stream' => true]));
        \assert($converted instanceof StreamResult);

        return new StreamResult((static function () use ($messages, $converted): \Generator {
            foreach ($converted->getContent() as $delta) {
                yield from $messages->takeDeltas();
                yield $delta;
            }
            yield from $messages->takeDeltas();
        })());
    }
}
