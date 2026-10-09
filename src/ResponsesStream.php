<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAIChatGPT;

use Symfony\AI\Platform\Exception\IncompleteStreamException;
use Symfony\AI\Platform\Exception\MalformedToolCallException;
use Symfony\AI\Platform\Exception\MaxOutputTokensException;
use Symfony\AI\Platform\Exception\RuntimeException;
use Symfony\AI\Platform\Result\Stream\HttpStreamInterface;
use Symfony\AI\Platform\Result\Stream\RawSseStream;
use Symfony\Contracts\HttpClient\ResponseInterface;

/** Keep framework SSE parsing/conversion; enforce terminal/tool integrity and safe provider errors. */
final class ResponsesStream implements HttpStreamInterface
{
    public function stream(ResponseInterface $response): iterable
    {
        $completed = false;
        $pending = [];
        foreach ((new RawSseStream())->stream($response) as $event) {
            $type = $event['type'] ?? null;
            if ($completed) {
                throw new RuntimeException('ChatGPT emitted an event after response completion.');
            }
            if ('error' === $type || 'response.failed' === $type) {
                $data = 'response.failed' === $type ? ($event['response'] ?? []) : $event;
                $error = \is_array($data['error'] ?? null) ? $data['error'] : $data;
                ProviderErrorMapper::throwError(\is_array($error) ? $error : []);
            }
            if ('response.incomplete' === $type) {
                if ('max_output_tokens' === ($event['response']['incomplete_details']['reason'] ?? null)) {
                    throw new MaxOutputTokensException('ChatGPT response reached an output limit.');
                }
                throw new IncompleteStreamException('ChatGPT response is incomplete.');
            }
            if (\in_array($type, ['response.output_item.added', 'response.output_item.done'], true)
                && 'function_call' === ($event['item']['type'] ?? null)) {
                $item = $event['item'];
                $key = $item['call_id'] ?? $item['id'] ?? null;
                if (!\is_string($key) || '' === $key) {
                    throw new MalformedToolCallException('ChatGPT tool call has no identity.');
                }
                if ('response.output_item.added' === $type) {
                    $pending[$key] = true;
                } else {
                    $this->validateCall($item);
                    unset($pending[$key], $pending[$item['id'] ?? '']);
                }
                $event['item'] = $this->pairedIdentity($item);
            }
            if ('response.completed' === $type) {
                foreach ($event['response']['output'] ?? [] as $index => $item) {
                    if ('function_call' === ($item['type'] ?? null)) {
                        $this->validateCall($item);
                        unset($pending[$item['call_id'] ?? ''], $pending[$item['id'] ?? '']);
                        $event['response']['output'][$index] = $this->pairedIdentity($item);
                    }
                }
                if ([] !== $pending) {
                    throw new IncompleteStreamException('ChatGPT completed with unfinished tool calls.');
                }
                $completed = true;
            }
            yield $event;
        }
        if (!$completed) {
            throw new IncompleteStreamException('ChatGPT stream ended before response.completed.');
        }
    }

    /** @param array<string, mixed> $item */
    private function validateCall(array $item): void
    {
        if (!\is_string($item['name'] ?? null) || '' === $item['name'] || !\is_string($item['arguments'] ?? null)
            || !\is_string($item['call_id'] ?? $item['id'] ?? null) || '' === ($item['call_id'] ?? $item['id'] ?? '')) {
            throw new MalformedToolCallException('ChatGPT returned a malformed tool call.');
        }
        try {
            $arguments = json_decode($item['arguments'], true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new MalformedToolCallException('ChatGPT tool arguments are invalid JSON.');
        }
        if (!\is_array($arguments) || !str_starts_with(ltrim($item['arguments']), '{')) {
            throw new MalformedToolCallException('ChatGPT tool arguments must be an object.');
        }
    }

    /**
     * @param array<string, mixed> $item
     *
     * @return array<string, mixed>
     */
    private function pairedIdentity(array $item): array
    {
        if (\is_string($item['call_id'] ?? null) && \is_string($item['id'] ?? null) && '' !== $item['id']) {
            // Framework ToolCall has one ID slot. Preserve both provider identities for stateless replay.
            $item['call_id'] .= '|'.$item['id'];
        }

        return $item;
    }
}
