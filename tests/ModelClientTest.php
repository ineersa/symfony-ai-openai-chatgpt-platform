<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAIChatGPT\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
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
    #[DataProvider('advertisedControlEfforts')]
    public function testNativeControlsKeepExactHistoryPositionsAndDoNotOverrideLowBaseline(string $effort): void
    {
        $body = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$body): MockResponse {
            $body = self::decodeBody($options);

            return new MockResponse(AuthFixture::sse([['type' => 'response.completed', 'response' => ['output' => []]]]));
        });
        $input = [
            ['role' => 'user', 'content' => 'Start'],
            ['type' => 'configuration_update', 'reasoning' => ['effort' => $effort]],
            ['role' => 'assistant', 'content' => 'High remains active'],
            ['role' => 'user', 'content' => 'Next turn'],
            ['type' => 'configuration_update', 'reasoning' => ['effort' => 'low']],
            ['role' => 'assistant', 'content' => 'Reset to low'],
        ];
        $client = new ModelClient($http, AuthFixture::service(new InMemoryAuthStorage(AuthFixture::record()), $http));
        $raw = $client->request(new ResponsesModel('test-model'), ['input' => $input], ['reasoning' => ['effort' => 'low'], 'prompt_cache_key' => 'stable', 'previous_response_id' => 'forbidden', 'truncation' => 'auto']);
        self::assertSame($input, $body['input']);
        self::assertSame(['effort' => 'low'], $body['reasoning']);
        self::assertSame('stable', $body['prompt_cache_key']);
        self::assertFalse($body['store']);
        self::assertTrue($body['stream']);
        self::assertArrayNotHasKey('previous_response_id', $body);
        self::assertArrayNotHasKey('truncation', $body);
        $raw->getObject()->cancel();
    }

    /** @return iterable<string, array{string}> */
    public static function advertisedControlEfforts(): iterable
    {
        foreach (['none', 'minimal', 'low', 'medium', 'high', 'xhigh', 'max'] as $effort) {
            yield $effort => [$effort];
        }
    }

    /** @param list<array<string, mixed>> $input */
    #[DataProvider('invalidControls')]
    public function testRejectsInvalidOrAdjacentNativeControlsBeforeHttpRequest(array $input): void
    {
        $http = new MockHttpClient(static function (): never { self::fail('Invalid history must not reach HTTP.'); });
        $client = new ModelClient($http, AuthFixture::service(new InMemoryAuthStorage(AuthFixture::record()), $http));
        $this->expectException(InvalidArgumentException::class);
        $client->request(new ResponsesModel('test-model'), ['input' => $input], ['prompt_cache_key' => 'stable']);
    }

    /** @return iterable<string, array{list<array<string, mixed>>}> */
    public static function invalidControls(): iterable
    {
        yield 'invalid shape' => [[['type' => 'configuration_update', 'reasoning' => ['effort' => 'high'], 'role' => 'assistant']]];
        yield 'invalid effort' => [[['type' => 'configuration_update', 'reasoning' => ['effort' => 'bogus']]]];
        yield 'adjacent updates' => [[['type' => 'configuration_update', 'reasoning' => ['effort' => 'high']], ['type' => 'configuration_update', 'reasoning' => ['effort' => 'low']]]];
    }

    public function testRestrictsBodyGroupsFunctionsPreservesHistoryAndOverridesHttpDeadline(): void
    {
        $body = $httpOptions = [];
        $response = new MockResponse(AuthFixture::sse([['type' => 'response.completed', 'response' => ['output' => []]]]));
        $httpClient = (new MockHttpClient(static function (string $method, string $url, array $options) use (&$body, &$httpOptions, $response): MockResponse {
            self::assertSame('POST', $method);
            self::assertSame('https://api.openai.com/v1/responses', $url);
            $body = self::decodeBody($options);
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
            $body = self::decodeBody($options);

            return new MockResponse('');
        });
        $client = new ModelClient($http, AuthFixture::service(new InMemoryAuthStorage(AuthFixture::record()), $http));
        $client->request(new ResponsesModel('test-model'), ['input' => []], ['prompt_cache_key' => 'stable', PlatformSubscriber::RESPONSE_FORMAT => ['type' => 'json_schema', 'json_schema' => ['name' => 'Result', 'schema' => ['type' => 'object']]]]);
        self::assertSame('json_schema', $body['text']['format']['type']);
        self::assertSame('Result', $body['text']['format']['name']);
        self::assertArrayNotHasKey(PlatformSubscriber::RESPONSE_FORMAT, $body);
    }

    public function testRejectsHostedToolsRatherThanSendingThem(): void
    {
        $http = new MockHttpClient(static function (): never { self::fail('Unexpected inference request.'); });
        $client = new ModelClient($http, AuthFixture::service(new InMemoryAuthStorage(AuthFixture::record(1)), $http));
        $this->expectException(InvalidArgumentException::class);
        $client->request(new ResponsesModel('test-model'), ['input' => []], ['prompt_cache_key' => 'stable', 'tools' => [['type' => 'tool_search']]]);
    }

    public function testTransportInterruptionAfterProgressDoesNotReconnectWithSseHeaders(): void
    {
        $chunks = (static function (): \Generator {
            yield AuthFixture::sse([['type' => 'response.output_text.delta', 'delta' => 'partial']]);
            throw new TransportException('Synthetic interruption.');
        })();
        $http = new MockHttpClient(new MockResponse($chunks, ['response_headers' => ['content-type: text/event-stream']]));
        $client = new ModelClient($http, AuthFixture::service(new InMemoryAuthStorage(AuthFixture::record()), $http));
        $raw = $client->request(new ResponsesModel('test-model'), ['input' => []], ['prompt_cache_key' => 'stable']);
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

    public function testSessionIdHeadersMirrorPromptCacheKeyAcrossConsecutiveRequests(): void
    {
        $seen = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen[] = [$options['normalized_headers'], self::decodeBody($options)['prompt_cache_key']];

            return new MockResponse(AuthFixture::sse([['type' => 'response.completed', 'response' => ['output' => []]]]));
        });
        $client = new ModelClient($http, AuthFixture::service(new InMemoryAuthStorage(AuthFixture::record()), $http));
        foreach ([1, 2] as $_) {
            $client->request(new ResponsesModel('test-model'), ['input' => []], ['prompt_cache_key' => 'stable-key'])->getObject()->cancel();
        }
        self::assertCount(2, $seen);
        foreach ($seen as [$headers, $bodyKey]) {
            self::assertSame('stable-key', $bodyKey);
            self::assertSame('session-id: stable-key', $headers['session-id'][0] ?? null);
            self::assertSame('x-client-request-id: stable-key', $headers['x-client-request-id'][0] ?? null);
            self::assertSame('Originator: Test app', $headers['originator'][0] ?? null);
        }
    }

    public function testCompressionPreservesOpaqueReasoningAndToolHistory(): void
    {
        $input = [
            ['type' => 'reasoning', 'id' => 'rs_native', 'encrypted_content' => base64_encode(str_repeat('opaque reasoning', 100)), 'summary' => []],
            ['type' => 'function_call', 'call_id' => 'call_native', 'name' => 'lookup', 'arguments' => '{"query":"hello"}'],
            ['type' => 'function_call_output', 'call_id' => 'call_native', 'output' => str_repeat('tool result ', 100)],
        ];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use ($input): MockResponse {
            self::assertSame('https://api.openai.com/v1/responses', $url);
            self::assertSame($input, self::decodeBody($options)['input']);
            if (\extension_loaded('zstd')) {
                self::assertSame(['Content-Encoding: zstd'], $options['normalized_headers']['content-encoding']);
                $uncompress = 'zstd_uncompress';
                $compress = 'zstd_compress';
                $decoded = $uncompress($options['body']);
                self::assertIsString($decoded);
                self::assertSame($options['body'], $compress($decoded, 3));
            } else {
                self::assertArrayNotHasKey('content-encoding', $options['normalized_headers']);
            }

            return new MockResponse('');
        });
        $client = new ModelClient($http, AuthFixture::service(new InMemoryAuthStorage(AuthFixture::record()), $http));
        $client->request(new ResponsesModel('test-model'), ['input' => $input], ['prompt_cache_key' => 'stable-key'])->getObject()->cancel();
    }

    public function testExcessiveCompressionRatioKeepsPlainJson(): void
    {
        $http = new MockHttpClient(static function (string $method, string $url, array $options): MockResponse {
            self::assertArrayNotHasKey('content-encoding', $options['normalized_headers']);
            self::assertSame(str_repeat('a', 100000), self::decodeBody($options)['input'][0]['content']);

            return new MockResponse('');
        });
        $client = new ModelClient($http, AuthFixture::service(new InMemoryAuthStorage(AuthFixture::record()), $http));
        $client->request(new ResponsesModel('test-model'), ['input' => [['role' => 'user', 'content' => str_repeat('a', 100000)]]], ['prompt_cache_key' => 'stable-key'])->getObject()->cancel();
    }

    /** @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private static function decodeBody(array $options): array
    {
        if (isset($options['normalized_headers']['content-encoding'])) {
            self::assertSame(['Content-Encoding: zstd'], $options['normalized_headers']['content-encoding']);
        }

        return AuthFixture::decodeBody($options);
    }

    /** @param array<string, mixed> $options */
    #[DataProvider('payloadCacheKeyOptions')]
    public function testPayloadCacheKeyControlsBodyAndRoutingHeaders(array $options): void
    {
        $http = new MockHttpClient(static function (string $method, string $url, array $httpOptions): MockResponse {
            self::assertSame('payload-key', self::decodeBody($httpOptions)['prompt_cache_key']);
            self::assertSame(['session-id: payload-key'], $httpOptions['normalized_headers']['session-id']);
            self::assertSame(['x-client-request-id: payload-key'], $httpOptions['normalized_headers']['x-client-request-id']);

            return new MockResponse('');
        });
        $client = new ModelClient($http, AuthFixture::service(new InMemoryAuthStorage(AuthFixture::record()), $http));
        $client->request(new ResponsesModel('test-model'), ['input' => [], 'prompt_cache_key' => 'payload-key'], $options)->getObject()->cancel();
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function payloadCacheKeyOptions(): iterable
    {
        yield 'payload only' => [[]];
        yield 'conflicting option' => [['prompt_cache_key' => 'option-key']];
        yield 'invalid option overridden' => [['prompt_cache_key' => null]];
    }

    /** @param array<string, mixed> $payload
     * @param array<string, mixed> $options
     */
    #[DataProvider('invalidCacheKeys')]
    public function testRejectsInvalidEffectivePromptCacheKeyBeforeHttpRequest(array $payload, array $options): void
    {
        $http = new MockHttpClient(static function (): never { self::fail('Requests without prompt_cache_key must not reach HTTP.'); });
        $client = new ModelClient($http, AuthFixture::service(new InMemoryAuthStorage(AuthFixture::record()), $http));
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('prompt_cache_key');
        $client->request(new ResponsesModel('test-model'), ['input' => [], ...$payload], $options);
    }

    /** @return iterable<string, array{array<string, mixed>, array<string, mixed>}> */
    public static function invalidCacheKeys(): iterable
    {
        yield 'missing' => [[], []];
        foreach ([null, '', 1, false, []] as $index => $invalid) {
            yield 'invalid option '.$index => [[], ['prompt_cache_key' => $invalid]];
            yield 'invalid payload overrides valid option '.$index => [['prompt_cache_key' => $invalid], ['prompt_cache_key' => 'valid-option']];
        }
    }
}
