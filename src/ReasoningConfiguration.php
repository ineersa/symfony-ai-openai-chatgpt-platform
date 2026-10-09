<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAIChatGPT;

use Symfony\AI\Platform\Exception\InvalidArgumentException;
use Symfony\AI\Platform\Message\Content\ContentInterface;

/** A native history control item, not a top-level reasoning override. */
final readonly class ReasoningConfiguration implements ContentInterface
{
    public const string METADATA_KEY = 'chatgpt.reasoning_effort';
    private const array EFFORTS = ['none', 'minimal', 'low', 'medium', 'high', 'xhigh', 'max'];

    public function __construct(public string $effort)
    {
        if (!\in_array($effort, self::EFFORTS, true)) {
            throw new InvalidArgumentException('ChatGPT reasoning configuration has an invalid effort.');
        }
    }

    /** @return array{type: string, reasoning: array{effort: string}} */
    public function toArray(): array
    {
        return ['type' => 'configuration_update', 'reasoning' => ['effort' => $this->effort]];
    }

    /** @param array<string, mixed> $item */
    public static function fromArray(array $item): self
    {
        if ('configuration_update' !== ($item['type'] ?? null) || 2 !== \count($item)
            || !\is_array($item['reasoning'] ?? null) || 1 !== \count($item['reasoning'])
            || !\is_string($item['reasoning']['effort'] ?? null)) {
            throw new InvalidArgumentException('ChatGPT reasoning configuration must contain only a reasoning effort.');
        }

        return new self($item['reasoning']['effort']);
    }
}
