<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAIChatGPT;

use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Result\Stream\Delta\MessageComplete;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Result\Stream\Delta\MessageStart;
use Symfony\AI\Platform\Result\RawHttpResult;
use Symfony\AI\Platform\Result\Stream\HttpStreamInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/** Adds message boundaries without replacing framework text, reasoning, tool, or usage conversion. */
final class MessageStream implements HttpStreamInterface
{
    /** @var list<MessageStart|MessageComplete> */
    private array $deltas = [];

    public function __construct(private readonly RawHttpResult $source)
    {
    }

    public function stream(ResponseInterface $response): iterable
    {
        $announced = [];
        $completed = [];
        foreach ($this->source->getDataStream() as $event) {
            if ('response.output_item.added' === ($event['type'] ?? null) && 'message' === ($event['item']['type'] ?? null)) {
                $item = $event['item'];
                $key = $item['id'] ?? $event['output_index'] ?? null;
                if (\is_string($key) || \is_int($key)) {
                    $announced[$key] = array_intersect_key($item, ['phase' => true]);
                    $announced[$key]['output_index'] = $event['output_index'] ?? null;
                }
                $this->deltas[] = new MessageStart(array_diff_key($item, ['content' => true]), \is_int($event['output_index'] ?? null) ? $event['output_index'] : null);
            }
            if ('response.output_item.done' === ($event['type'] ?? null) && 'message' === ($event['item']['type'] ?? null)) {
                $this->complete($event['item'], $event['output_index'] ?? null, $announced, $completed);
            }
            if ('response.completed' === ($event['type'] ?? null)) {
                // Some providers emit message content only in the terminal output array.
                foreach ($event['response']['output'] ?? [] as $index => $item) {
                    if ('message' === ($item['type'] ?? null)) {
                        $this->complete($item, $index, $announced, $completed);
                    }
                }
            }
            yield $event;
        }
    }

    /** @return list<MessageStart|MessageComplete> */
    public function takeDeltas(): array
    {
        $deltas = $this->deltas;
        $this->deltas = [];

        return $deltas;
    }

    /**
     * @param array<string, mixed>                    $item
     * @param array<string|int, array<string, mixed>> $announced
     * @param array<string|int, true>                 $completed
     */
    private function complete(array $item, mixed $index, array $announced, array &$completed): void
    {
        $key = $item['id'] ?? $index;
        if ((\is_string($key) || \is_int($key)) && isset($completed[$key])) {
            return;
        }
        if (\is_string($key) || \is_int($key)) {
            $index ??= $announced[$key]['output_index'] ?? null;
            if (!\array_key_exists('phase', $item) && \array_key_exists('phase', $announced[$key] ?? [])) {
                $item['phase'] = $announced[$key]['phase'];
            }
            $completed[$key] = true;
        }
        // Carry only the native message item in a signed Text, never a duplicate full response.
        $this->deltas[] = new MessageComplete(MessageItem::toText($item), \is_int($index) ? $index : null);
    }
}
