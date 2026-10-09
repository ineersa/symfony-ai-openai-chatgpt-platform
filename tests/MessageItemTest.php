<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAIChatGPT\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\MessageItem;
use Symfony\AI\Platform\Exception\InvalidArgumentException;
use Symfony\AI\Platform\Message\Content\Text;

final class MessageItemTest extends TestCase
{
    public function testSignedTextRetainsNativeIdentityPhaseNameAndAdjacentContentParts(): void
    {
        $item = ['type' => 'message', 'id' => 'msg_one', 'role' => 'assistant', 'phase' => 'commentary', 'name' => 'assistant', 'content' => [
            ['type' => 'output_text', 'text' => 'One', 'annotations' => []],
            ['type' => 'output_text', 'text' => 'Two', 'annotations' => [['type' => 'url_citation', 'url' => 'https://example.invalid']]],
        ]];
        $text = MessageItem::toText($item);
        self::assertSame('OneTwo', $text->getText());
        self::assertSame($item, MessageItem::fromText(new Text($text->getText(), $text->getSignature())));
    }

    public function testEmptyNativeMessageAndRefusalRemainReplayable(): void
    {
        $empty = ['type' => 'message', 'id' => 'msg_empty', 'role' => 'assistant', 'content' => []];
        self::assertSame($empty, MessageItem::fromText(MessageItem::toText($empty)));
        $refusal = ['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'refusal', 'refusal' => 'Declined']]];
        self::assertSame($refusal, MessageItem::fromText(MessageItem::toText($refusal)));
    }

    public function testOrdinaryAndOtherProviderTextSignaturesRemainOrdinary(): void
    {
        self::assertNull(MessageItem::fromText(new Text('Legacy')));
        self::assertNull(MessageItem::fromText(new Text('Legacy', 'opaque-other-provider')));
    }

    public function testEditedTextCannotSilentlyReplayTheSignedOriginal(): void
    {
        $item = ['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => 'Original']]];
        $this->expectException(InvalidArgumentException::class);
        MessageItem::fromText(new Text('Edited', MessageItem::toText($item)->getSignature()));
    }
}
