<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAIChatGPT\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Exception\SubscriptionLimitException;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\ResponsesStream;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Tests\Support\AuthFixture;
use Symfony\AI\Platform\Exception\IncompleteStreamException;
use Symfony\AI\Platform\Exception\MalformedToolCallException;
use Symfony\AI\Platform\Exception\MaxOutputTokensException;
use Symfony\AI\Platform\Exception\RateLimitExceededException;
use Symfony\AI\Platform\Exception\ServerException;
use Symfony\Component\HttpClient\EventSourceHttpClient;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ResponsesStreamTest extends TestCase
{
    /**
     * @param list<array<string, mixed>> $events
     * @param class-string<\Throwable>   $exception
     */
    #[DataProvider('failedStreams')]
    public function testRejectsIncompleteOrFailedStreamsWithoutExposingProviderBodies(array $events, string $exception): void
    {
        $response = (new EventSourceHttpClient(new MockHttpClient(new MockResponse(AuthFixture::sse($events)))))->request('GET', 'https://example.invalid');
        try {
            iterator_to_array((new ResponsesStream())->stream($response));
            self::fail('Expected stream rejection.');
        } catch (\Throwable $error) {
            self::assertInstanceOf($exception, $error);
            self::assertStringNotContainsString('secret-marker', $error->getMessage());
        }
    }

    /** @return iterable<string, array{list<array<string, mixed>>, class-string<\Throwable>}> */
    public static function failedStreams(): iterable
    {
        yield 'empty' => [[], IncompleteStreamException::class];
        yield 'partial text' => [[['type' => 'response.output_text.delta', 'delta' => 'partial']], IncompleteStreamException::class];
        yield 'unfinished call even with completed' => [[['type' => 'response.output_item.added', 'item' => ['type' => 'function_call', 'id' => 'fc_one', 'call_id' => 'call_one', 'name' => 'read']], ['type' => 'response.completed', 'response' => ['output' => []]]], IncompleteStreamException::class];
        yield 'incomplete max tokens' => [[['type' => 'response.incomplete', 'response' => ['incomplete_details' => ['reason' => 'max_output_tokens']]]], MaxOutputTokensException::class];
        yield 'other incomplete' => [[['type' => 'response.incomplete', 'response' => ['incomplete_details' => ['reason' => 'secret-marker']]]], IncompleteStreamException::class];
        yield 'failed server' => [[['type' => 'response.failed', 'response' => ['error' => ['code' => 'server_error', 'message' => 'secret-marker']]]], ServerException::class];
        yield 'transient rate limit' => [[['type' => 'error', 'code' => 'rate_limit_exceeded', 'message' => 'secret-marker']], RateLimitExceededException::class];
        yield 'permanent quota' => [[['type' => 'error', 'code' => 'insufficient_quota', 'message' => 'secret-marker']], SubscriptionLimitException::class];
        yield 'malformed tool' => [[['type' => 'response.completed', 'response' => ['output' => [['type' => 'function_call', 'id' => 'fc', 'call_id' => 'call', 'name' => 'read', 'arguments' => 'secret-marker']]]]], MalformedToolCallException::class];
    }
}
