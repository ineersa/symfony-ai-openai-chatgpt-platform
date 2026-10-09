<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAIChatGPT\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Factory;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\ReasoningConfiguration;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Result\Stream\Delta\MessageComplete;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Tests\Support\AuthFixture;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Tests\Support\InMemoryAuthStorage;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Tests\Support\TestModelCatalog;
use Symfony\AI\Platform\Message\AssistantMessage;
use Symfony\AI\Platform\Message\Content\Text;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\SystemMessage;
use Symfony\AI\Platform\Result\Stream\AssistantMessageStreamListener;
use Symfony\AI\Platform\Result\Stream\Delta\DeltaInterface;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\AI\Platform\Result\StreamResult;
use Symfony\AI\Platform\TokenUsage\TokenUsageInterface;
use Symfony\AI\Platform\Tool\ExecutionReference;
use Symfony\AI\Platform\Tool\Tool;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class FactoryTest extends TestCase
{
    public function testNativeMessagePhasesBoundariesAndControlsRoundTripThroughFactoryAfterSerialization(): void
    {
        $comment = ['type' => 'message', 'id' => 'msg_comment', 'role' => 'assistant', 'name' => 'assistant', 'phase' => 'commentary', 'content' => [['type' => 'output_text', 'text' => 'Checking']]];
        $adjacent = ['type' => 'message', 'id' => 'msg_adjacent', 'role' => 'assistant', 'phase' => 'commentary', 'content' => [['type' => 'output_text', 'text' => 'One'], ['type' => 'output_text', 'text' => 'Two']]];
        $final = ['type' => 'message', 'id' => 'msg_final', 'role' => 'assistant', 'phase' => 'final_answer', 'content' => [['type' => 'output_text', 'text' => 'Final']]];
        $noPhase = ['type' => 'message', 'id' => 'msg_no_phase', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => 'No phase']]];
        $reasoning = ['type' => 'reasoning', 'id' => 'rs_one', 'encrypted_content' => 'ciphertext', 'summary' => []];
        $call = ['type' => 'function_call', 'id' => 'fc_one', 'call_id' => 'call_one', 'name' => 'local_lookup', 'arguments' => '{"path":"file"}'];
        $events = [
            ['type' => 'response.output_item.added', 'output_index' => 0, 'item' => array_replace($comment, ['content' => []])],
            ['type' => 'response.output_text.delta', 'item_id' => 'msg_comment', 'delta' => 'Checking'],
            ['type' => 'response.output_item.done', 'output_index' => 0, 'item' => $comment],
            ['type' => 'response.output_item.done', 'output_index' => 1, 'item' => $reasoning],
            ['type' => 'response.output_item.added', 'output_index' => 2, 'item' => array_replace($adjacent, ['content' => []])],
            ['type' => 'response.output_text.delta', 'item_id' => 'msg_adjacent', 'delta' => 'One'],
            ['type' => 'response.output_text.delta', 'item_id' => 'msg_adjacent', 'delta' => 'Two'],
            ['type' => 'response.output_item.done', 'output_index' => 2, 'item' => $adjacent],
            ['type' => 'response.output_item.added', 'output_index' => 3, 'item' => array_replace($call, ['arguments' => ''])],
            ['type' => 'response.function_call_arguments.delta', 'item_id' => 'fc_one', 'delta' => '{"path":"file"}'],
            ['type' => 'response.output_item.done', 'output_index' => 3, 'item' => $call],
            ['type' => 'response.completed', 'response' => ['output' => [$comment, $reasoning, $adjacent, $call, $final, $noPhase], 'usage' => ['input_tokens' => 12, 'output_tokens' => 5, 'total_tokens' => 17]]],
        ];
        $bodies = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$bodies, $events): MockResponse {
            $bodies[] = json_decode($options['body'], true, flags: \JSON_THROW_ON_ERROR);

            return new MockResponse(AuthFixture::sse(1 === \count($bodies) ? $events : [['type' => 'response.completed', 'response' => ['output' => []]]]));
        });
        $provider = Factory::createProvider(AuthFixture::service(new InMemoryAuthStorage(AuthFixture::record()), $http), $http, new TestModelCatalog());
        $result = $provider->invoke('test-model', new MessageBag(Message::ofUser('Start')), ['reasoning' => ['effort' => 'low'], 'prompt_cache_key' => 'stable-session-key'])->getResult();
        self::assertInstanceOf(StreamResult::class, $result);
        $framework = new AssistantMessageStreamListener();
        $native = [];
        $visible = '';
        foreach ($result->getContent() as $delta) {
            if ($delta instanceof MessageComplete) {
                $index = $delta->getOutputIndex();
                self::assertNotNull($index);
                $native[$index] = $delta->getContent();
            } elseif ($delta instanceof TextDelta) {
                $visible .= $delta->getText();
            } elseif ($delta instanceof DeltaInterface) {
                $framework->accumulate($delta);
            }
        }
        self::assertSame('CheckingOneTwo', $visible);
        $usage = $result->getMetadata()->get('token_usage');
        self::assertInstanceOf(TokenUsageInterface::class, $usage);
        self::assertSame(12, $usage->getPromptTokens());
        self::assertSame([0, 2, 4, 5], array_keys($native));
        // Simulate durable Text fields, not a full raw response or a flattened transcript.
        $saved = json_decode(json_encode(array_map(static fn (Text $text): array => ['text' => $text->getText(), 'signature' => $text->getSignature()], $native), \JSON_THROW_ON_ERROR), true, flags: \JSON_THROW_ON_ERROR);
        $restored = array_map(static fn (array $text): Text => new Text($text['text'], $text['signature']), $saved);
        $nonText = $framework->getAssistantMessage();
        self::assertCount(1, $nonText->getThinking());
        self::assertCount(1, $nonText->getToolCalls());
        $assistant = new AssistantMessage($restored[0], $nonText->getThinking()[0], $restored[2], new ReasoningConfiguration('high'), $nonText->getToolCalls()[0], $restored[4], $restored[5]);
        $next = $provider->invoke('test-model', new MessageBag(Message::ofUser('Start'), $assistant), ['reasoning' => ['effort' => 'low'], 'prompt_cache_key' => 'stable-session-key', 'previous_response_id' => 'forbidden', 'store' => true, 'stream' => false])->getResult();
        self::assertInstanceOf(StreamResult::class, $next);
        iterator_to_array($next->getContent());
        self::assertSame($comment, $bodies[1]['input'][1]);
        self::assertSame($reasoning, $bodies[1]['input'][2]);
        self::assertSame($adjacent, $bodies[1]['input'][3]);
        self::assertSame(['type' => 'configuration_update', 'reasoning' => ['effort' => 'high']], $bodies[1]['input'][4]);
        self::assertSame('call_one', $bodies[1]['input'][5]['call_id']);
        self::assertSame('fc_one', $bodies[1]['input'][5]['id']);
        self::assertSame('local_lookup', $bodies[1]['input'][5]['name']);
        self::assertSame($final, $bodies[1]['input'][6]);
        self::assertSame($noPhase, $bodies[1]['input'][7]);
        self::assertSame(['effort' => 'low'], $bodies[1]['reasoning']);
        self::assertSame('stable-session-key', $bodies[1]['prompt_cache_key']);
        self::assertSame($bodies[0]['prompt_cache_key'], $bodies[1]['prompt_cache_key']);
        self::assertFalse($bodies[1]['store']);
        self::assertTrue($bodies[1]['stream']);
        self::assertArrayNotHasKey('previous_response_id', $bodies[1]);
    }

    public function testRealFrameworkToolCallResultAndEncryptedReasoningRoundTrip(): void
    {
        $reasoning = ['type' => 'reasoning', 'id' => 'rs_new', 'encrypted_content' => 'opaque-encrypted-state', 'summary' => []];
        $call = ['type' => 'function_call', 'id' => 'fc_new', 'call_id' => 'call_new', 'name' => 'local_lookup', 'arguments' => '{"path":"file.txt"}'];
        $events = [
            ['type' => 'response.output_item.done', 'item' => $reasoning],
            ['type' => 'response.output_item.added', 'item' => array_replace($call, ['arguments' => ''])],
            ['type' => 'response.function_call_arguments.delta', 'item_id' => 'fc_new', 'delta' => '{"path":"file.txt"}'],
            ['type' => 'response.output_item.done', 'item' => $call],
            ['type' => 'response.completed', 'response' => ['status' => 'completed', 'output' => [$reasoning, $call]]],
        ];
        $bodies = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$bodies, $events): MockResponse {
            $bodies[] = json_decode($options['body'], true, flags: \JSON_THROW_ON_ERROR);

            return new MockResponse(AuthFixture::sse(1 === \count($bodies) ? $events : [['type' => 'response.output_text.delta', 'delta' => 'Done'], ['type' => 'response.completed', 'response' => ['output' => []]]]));
        });
        $provider = Factory::createProvider(AuthFixture::service(new InMemoryAuthStorage(AuthFixture::record()), $http), $http, new TestModelCatalog());
        $history = new MessageBag(new SystemMessage('Follow developer instructions'), Message::ofUser('Look up a file'));
        $first = $provider->invoke('test-model', $history, ['tools' => [new Tool(new ExecutionReference(self::class), 'local_lookup', 'Look up a file', ['type' => 'object', 'properties' => ['path' => ['type' => 'string', 'description' => 'File path']], 'required' => ['path'], 'additionalProperties' => false])]])->getResult();
        self::assertInstanceOf(StreamResult::class, $first);
        $assistant = $first->getAssistantMessage();
        self::assertCount(1, $assistant->getToolCalls());
        $toolCall = $assistant->getToolCalls()[0];
        self::assertSame('call_new|fc_new', $toolCall->getId());
        self::assertSame('local_lookup', $toolCall->getName());
        self::assertSame(['path' => 'file.txt'], $toolCall->getArguments());
        self::assertCount(1, $assistant->getThinking());
        $signature = $assistant->getThinking()[0]->getSignature();
        self::assertNotNull($signature);
        self::assertSame($reasoning, json_decode($signature, true, flags: \JSON_THROW_ON_ERROR));
        $next = new MessageBag(...[...$history->getMessages(), $assistant, Message::ofToolCall($toolCall, 'file contents')]);
        $second = $provider->invoke('test-model', $next)->getResult();
        self::assertInstanceOf(StreamResult::class, $second);
        self::assertSame('Done', $second->getAssistantMessage()->asText());
        self::assertSame('Follow developer instructions', $bodies[1]['instructions']);
        self::assertSame($reasoning, $bodies[1]['input'][1]);
        self::assertSame('call_new', $bodies[1]['input'][2]['call_id']);
        self::assertSame('fc_new', $bodies[1]['input'][2]['id']);
        self::assertSame('call_new', $bodies[1]['input'][3]['call_id']);
        self::assertSame('file contents', $bodies[1]['input'][3]['output']);
    }
}
