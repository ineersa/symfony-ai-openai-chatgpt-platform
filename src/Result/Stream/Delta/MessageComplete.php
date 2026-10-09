<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAIChatGPT\Result\Stream\Delta;

use Symfony\AI\Platform\Message\Content\Text;
use Symfony\AI\Platform\Result\Stream\Delta\DeltaInterface;

/** One complete native message, with its exact replay item in the Text signature. */
final readonly class MessageComplete implements DeltaInterface
{
    public function __construct(private Text $content, private ?int $outputIndex)
    {
    }

    public function getContent(): Text
    {
        return $this->content;
    }

    public function getOutputIndex(): ?int
    {
        return $this->outputIndex;
    }
}
