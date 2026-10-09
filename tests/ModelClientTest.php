<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAIChatGPT\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\ModelClient;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Tests\Support\AuthFixture;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Tests\Support\InMemoryAuthStorage;
use Symfony\AI\Platform\Bridge\OpenResponses\ResponsesModel;
use Symfony\AI\Platform\Capability;
use Symfony\AI\Platform\Exception\InvalidArgumentException;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\AI\Platform\Result\StreamResult;
use Symfony\AI\Platform\StructuredOutput\PlatformSubscriber;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ModelClientTest extends TestCase
{
    public function testRestrictsBodyGroupsFunctionsPreservesHistoryAndOverridesHttpDeadline(): void
    {
        $body = $httpOptions = [];
        $response = new MockResponse(AuthFixture::sse([['type' => 'response.completed', 'response' => ['output' => []]]]));
        $httpClient = (new MockHttpClient(static function (string $method, string $url, array $options) use (&$body, &$httpOptions, $response): MockResponse {
            self::assertSame('POST', $method);
            self::assertSame('https://api.openai.com/v1/responses', $url);
            $body = json_decode($options['body'], true, flags: \JSON_THROW_ON_ERROR);
            $httpOptions = $options;

            return $response;
        }))->withOptions(['max_duration' => 120.0]);
        $client = new ModelClient($httpClient, AuthFixture::service(new InMemoryAuthStorage(AuthFixture::record()), $httpClient));
        $model = new ResponsesModel('test-model', [Capability::THINKING]);
        $forbidden = array_fill_keys(['background', 'conversation', 'max_output_tokens', 'max_tool_calls', 'metadata', 'moderation', 'multi_agent', 'prompt', 'prompt_cache_retention', 'safety_identifier', 'temperature', 'top_logprobs', 'top_p', 'truncation', 'user', 'previous_response_id', '_hatfield_transition', 'provider_cancellation'], 'forbidden-value');
        $options = $forbidden + ['store' => true, 'stream' => false, 'prompt_cache_key' => 'session-key', 'tools' => [['type' => 'function', 'name' => 'local_lookup', 'description' => 'Look up a path']], 'reasoning' => ['effort' => 'high']];
        $result = $client->request($model, ['input' => [
            ['role' => 'system', 'content' => 'Developer instructions'],
            ['type' => 'reasoning', 'id' => 'rs_saved', 'summary' => [], 'encrypted_content' => 'ciphertext'],
            ['type' => 'function_call', 'call_id' => 'call_saved|fc_saved', 'name' => 'local_lookup', 'arguments' => '{}'],
            ['type' => 'function_call_output', 'call_id' => 'call_saved|fc_saved', 'output' => "result\xFF"],
        ]], $options);
        self::assertFalse($body['store']);
        self::assertTrue($body['stream']);
        foreach (array_keys($forbidden) as $key) {
            self::assertArrayNotHasKey($key, $body);
        }
        self::assertArrayNotHasKey('tools', $body);
        self::assertSame('additional_tools', $body['input'][0]['type']);
        self::assertSame('local_lookup', $body['input'][0]['tools'][0]['name']);
        self::assertFalse($body['input'][0]['tools'][0]['strict']);
        self::assertSame('developer', $body['input'][1]['role']);
        self::assertSame('ciphertext', $body['input'][2]['encrypted_content']);
        self::assertSame('call_saved', $body['input'][3]['call_id']);
        self::assertSame('fc_saved', $body['input'][3]['id']);
        self::assertSame('call_saved', $body['input'][4]['call_id']);
        self::assertSame("result\u{FFFD}", $body['input'][4]['output']);
        self::assertSame('session-key', $body['prompt_cache_key']);
        self::assertContains('reasoning.encrypted_content', $body['include']);
        self::assertSame(0.0, $httpOptions['max_duration']);
        self::assertSame(300.0, $httpOptions['timeout']);
        $result->getObject()->cancel();
        self::assertTrue($result->getObject()->getInfo('canceled'));
    }

    public function testReusesFrameworkStructuredOutputAdaptation(): void
    {
        $body = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$body): MockResponse {
            $body = json_decode($options['body'], true, flags: \JSON_THROW_ON_ERROR);

            return new MockResponse('');
        });
        $client = new ModelClient($http, AuthFixture::service(new InMemoryAuthStorage(AuthFixture::record()), $http));
        $client->request(new ResponsesModel('test-model'), ['input' => []], [PlatformSubscriber::RESPONSE_FORMAT => ['type' => 'json_schema', 'json_schema' => ['name' => 'Result', 'schema' => ['type' => 'object']]]]);
        self::assertSame('json_schema', $body['text']['format']['type']);
        self::assertSame('Result', $body['text']['format']['name']);
        self::assertArrayNotHasKey(PlatformSubscriber::RESPONSE_FORMAT, $body);
    }

    public function testRejectsHostedToolsRatherThanSendingThem(): void
    {
        $http = new MockHttpClient(static function (): never { self::fail('Unexpected inference request.'); });
        $client = new ModelClient($http, AuthFixture::service(new InMemoryAuthStorage(AuthFixture::record(1)), $http));
        $this->expectException(InvalidArgumentException::class);
        $client->request(new ResponsesModel('test-model'), ['input' => []], ['tools' => [['type' => 'tool_search']]]);
    }

    public function testTransportInterruptionAfterProgressDoesNotReconnectWithSseHeaders(): void
    {
        $chunks = (static function (): \Generator {
            yield AuthFixture::sse([['type' => 'response.output_text.delta', 'delta' => 'partial']]);
            throw new TransportException('Synthetic interruption.');
        })();
        $http = new MockHttpClient(new MockResponse($chunks, ['response_headers' => ['content-type: text/event-stream']]));
        $client = new ModelClient($http, AuthFixture::service(new InMemoryAuthStorage(AuthFixture::record()), $http));
        $raw = $client->request(new ResponsesModel('test-model'), ['input' => []]);
        $result = (new \Symfony\AI\Platform\Bridge\OpenAIChatGPT\ResultConverter())->convert($raw);
        self::assertInstanceOf(StreamResult::class, $result);
        $stream = $result->getContent();
        self::assertInstanceOf(TextDelta::class, $stream->current());
        self::assertSame('partial', $stream->current()->getText());
        try {
            $stream->next();
            self::fail('Expected interruption.');
        } catch (TransportException $exception) {
            self::assertStringContainsString('Synthetic interruption', $exception->getMessage());
            self::assertSame(1, $http->getRequestsCount());
        }
    }
}
