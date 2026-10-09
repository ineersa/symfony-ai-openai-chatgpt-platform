<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAIChatGPT;

use Symfony\AI\Platform\Exception\InvalidArgumentException;
use Symfony\AI\Platform\Message\Content\Text;

/** One native assistant message per signed Text, including its original content-part boundaries. */
final class MessageItem
{
    /** @param array<string, mixed> $item */
    public static function toText(array $item): Text
    {
        return new Text(self::text($item), json_encode($item, \JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed>|null */
    public static function fromText(Text $text): ?array
    {
        if (null === $text->getSignature()) {
            return null;
        }
        try {
            $item = json_decode($text->getSignature(), true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            // Other providers' opaque signatures are not ChatGPT message items.
            return null;
        }
        if (!\is_array($item) || 'message' !== ($item['type'] ?? null)) {
            return null;
        }
        if (self::text($item) !== $text->getText()) {
            throw new InvalidArgumentException('Edited ChatGPT text must not retain its original message signature.');
        }

        return $item;
    }

    /** @param array<string, mixed> $item */
    private static function text(array $item): string
    {
        if ('message' !== ($item['type'] ?? null) || 'assistant' !== ($item['role'] ?? null)
            || !\is_array($item['content'] ?? null) || !array_is_list($item['content'])) {
            throw new InvalidArgumentException('ChatGPT assistant message item is malformed.');
        }
        $text = '';
        foreach ($item['content'] as $part) {
            if (!\is_array($part)) {
                throw new InvalidArgumentException('ChatGPT assistant content part is malformed.');
            }
            $value = match ($part['type'] ?? null) {
                'output_text' => $part['text'] ?? null,
                'refusal' => $part['refusal'] ?? null,
                default => null,
            };
            if (!\is_string($value)) {
                throw new InvalidArgumentException('ChatGPT assistant content part is unsupported or malformed.');
            }
            $text .= $value;
        }

        return $text;
    }
}
