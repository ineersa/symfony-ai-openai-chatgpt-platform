<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAIChatGPT\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\MessageItem;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\MessageStream;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\ResponsesStream;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Result\Stream\Delta\MessageComplete;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Result\Stream\Delta\MessageStart;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Tests\Support\AuthFixture;
use Symfony\AI\Platform\Exception\IncompleteStreamException;
use Symfony\AI\Platform\Result\RawHttpResult;
use Symfony\Component\HttpClient\EventSourceHttpClient;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class MessageStreamTest extends TestCase
{
    public function testBoundaryDeltasPreserveAnnouncedPhaseAndTerminalOnlyMessagesOnce(): void
    {
        $first = ['type' => 'message', 'id' => 'msg_first', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => 'First']]];
        $last = ['type' => 'message', 'id' => 'msg_last', 'role' => 'assistant', 'phase' => 'final_answer', 'content' => [['type' => 'output_text', 'text' => 'Last']]];
        $events = [
            ['type' => 'response.output_item.added', 'output_index' => 0, 'item' => array_replace($first, ['phase' => 'commentary', 'content' => []])],
            ['type' => 'response.output_item.done', 'item' => $first],
            ['type' => 'response.completed', 'response' => ['output' => [$first, $last]]],
        ];
        $response = (new EventSourceHttpClient(new MockHttpClient(new MockResponse(AuthFixture::sse($events)))))->request('GET', 'https://example.invalid');
        $stream = new MessageStream(new RawHttpResult($response, new ResponsesStream()));
        self::assertSame($events, iterator_to_array($stream->stream($response)));
        $deltas = $stream->takeDeltas();
        self::assertCount(3, $deltas);
        self::assertInstanceOf(MessageStart::class, $deltas[0]);
        self::assertArrayNotHasKey('content', $deltas[0]->getItem());
        self::assertInstanceOf(MessageComplete::class, $deltas[1]);
        self::assertSame(0, $deltas[1]->getOutputIndex());
        self::assertSame(array_replace($first, ['phase' => 'commentary']), MessageItem::fromText($deltas[1]->getContent()));
        self::assertInstanceOf(MessageComplete::class, $deltas[2]);
        self::assertSame(1, $deltas[2]->getOutputIndex());
        self::assertSame($last, MessageItem::fromText($deltas[2]->getContent()));
        self::assertSame([], $stream->takeDeltas());
    }

    public function testIncompleteSourceStillFailsWithoutPublishingCompletedMessages(): void
    {
        $response = (new EventSourceHttpClient(new MockHttpClient(new MockResponse(AuthFixture::sse([['type' => 'response.output_text.delta', 'delta' => 'Partial']])))))->request('GET', 'https://example.invalid');
        $stream = new MessageStream(new RawHttpResult($response, new ResponsesStream()));
        $this->expectException(IncompleteStreamException::class);
        iterator_to_array($stream->stream($response));
    }
}
