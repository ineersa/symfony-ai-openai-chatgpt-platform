<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAIChatGPT\Result\Stream\Delta;

use Symfony\AI\Platform\Result\Stream\Delta\DeltaInterface;

/** Native message header; subsequent TextDelta events still carry the visible text. */
final readonly class MessageStart implements DeltaInterface
{
    /** @param array<string, mixed> $item Header without the content payload. */
    public function __construct(private array $item, private ?int $outputIndex)
    {
    }

    /** @return array<string, mixed> */
    public function getItem(): array
    {
        return $this->item;
    }

    public function getOutputIndex(): ?int
    {
        return $this->outputIndex;
    }
}
