<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAIChatGPT\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Factory;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Tests\Support\AuthFixture;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Tests\Support\InMemoryAuthStorage;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Tests\Support\TestModelCatalog;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\SystemMessage;
use Symfony\AI\Platform\Result\StreamResult;
use Symfony\AI\Platform\Tool\ExecutionReference;
use Symfony\AI\Platform\Tool\Tool;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class FactoryTest extends TestCase
{
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
