<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAIChatGPT\Contract;

use Symfony\AI\Platform\Bridge\OpenAIChatGPT\MessageItem;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\ReasoningConfiguration;
use Symfony\AI\Platform\Bridge\OpenResponses\Contract\Message\AssistantMessageNormalizer as OpenResponsesAssistantMessageNormalizer;
use Symfony\AI\Platform\Bridge\OpenResponses\ResponsesModel;
use Symfony\AI\Platform\Contract\Normalizer\ModelContractNormalizer;
use Symfony\AI\Platform\Exception\InvalidArgumentException;
use Symfony\AI\Platform\Message\AssistantMessage;
use Symfony\AI\Platform\Message\Content\ContentInterface;
use Symfony\AI\Platform\Message\Content\Text;
use Symfony\AI\Platform\Model;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareTrait;

/** Delegate ordinary parts to OpenResponses; signed native messages and controls fix replay boundaries. */
final class AssistantMessageNormalizer extends ModelContractNormalizer implements NormalizerAwareInterface
{
    use NormalizerAwareTrait;

    /**
     * @param AssistantMessage $data
     *
     * @return list<array<string, mixed>>
     */
    public function normalize(mixed $data, ?string $format = null, array $context = []): array
    {
        $delegate = new OpenResponsesAssistantMessageNormalizer();
        $delegate->setNormalizer($this->normalizer);
        $items = [];
        $parts = [];
        $effort = $data->getMetadata()->get(ReasoningConfiguration::METADATA_KEY);
        if (null !== $effort) {
            if (!\is_string($effort)) {
                throw new InvalidArgumentException('ChatGPT reasoning transition metadata must be an effort string.');
            }
            $items[] = (new ReasoningConfiguration($effort))->toArray();
        }
        foreach ($data->getContent() as $part) {
            $item = $part instanceof Text ? MessageItem::fromText($part) : null;
            if ($part instanceof ReasoningConfiguration) {
                $item = $part->toArray();
            }
            if (null === $item) {
                $parts[] = $part;
                continue;
            }
            $this->flush($items, $parts, $delegate, $format, $context);
            $items[] = $item;
        }
        $this->flush($items, $parts, $delegate, $format, $context);

        return [] === $items ? $delegate->normalize($data, $format, $context) : $items;
    }

    protected function supportedDataClass(): string
    {
        return AssistantMessage::class;
    }

    protected function supportsModel(Model $model): bool
    {
        return $model instanceof ResponsesModel;
    }

    /**
     * @param list<array<string, mixed>> $items
     * @param list<ContentInterface>     $parts
     * @param array<string, mixed>       $context
     */
    private function flush(array &$items, array &$parts, OpenResponsesAssistantMessageNormalizer $delegate, ?string $format, array $context): void
    {
        if ([] === $parts) {
            return;
        }
        array_push($items, ...$delegate->normalize(new AssistantMessage(...$parts), $format, $context));
        $parts = [];
    }
}
