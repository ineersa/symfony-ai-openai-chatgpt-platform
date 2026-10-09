<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAIChatGPT\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Exception\SubscriptionLimitException;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Exception\SubscriptionPolicyException;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\ResponsesStream;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\ResultConverter;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Tests\Support\AuthFixture;
use Symfony\AI\Platform\Exception\AuthenticationException;
use Symfony\AI\Platform\Exception\RateLimitExceededException;
use Symfony\AI\Platform\Exception\ServerException;
use Symfony\AI\Platform\Result\RawHttpResult;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\AI\Platform\Result\StreamResult;
use Symfony\Component\HttpClient\EventSourceHttpClient;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ResultConverterTest extends TestCase
{
    /** @param class-string<\Throwable> $exception */
    #[DataProvider('httpErrors')]
    public function testConvertsErrorsWithoutRawBodyOrMessage(int $status, string $code, string $exception): void
    {
        $response = (new MockHttpClient(new MockResponse(json_encode(['error' => ['code' => $code, 'message' => 'secret-marker']], \JSON_THROW_ON_ERROR), ['http_code' => $status])))->request('POST', 'https://example.invalid');
        try {
            (new ResultConverter())->convert(new RawHttpResult($response));
            self::fail('Expected failure.');
        } catch (\Throwable $error) {
            self::assertInstanceOf($exception, $error);
            self::assertStringNotContainsString('secret-marker', $error->getMessage());
            if ($error instanceof SubscriptionLimitException) {
                self::assertStringContainsString('https://chatgpt.com/settings/usage', $error->getMessage());
            }
        }
    }

    /** @return iterable<string, array{int, string, class-string<\Throwable>}> */
    public static function httpErrors(): iterable
    {
        yield 'authentication' => [401, 'invalid_token', AuthenticationException::class];
        yield 'rate limit' => [429, 'rate_limit_exceeded', RateLimitExceededException::class];
        yield 'subscription exhausted' => [429, 'insufficient_quota', SubscriptionLimitException::class];
        yield 'unavailable' => [503, 'server_error', ServerException::class];
        yield 'subscription usage limit' => [429, 'subscription_sharing_usage_limit_exceeded', SubscriptionLimitException::class];
        yield 'not eligible' => [403, 'subscription_sharing_user_not_eligible', SubscriptionPolicyException::class];
        yield 'unsupported capability' => [400, 'subscription_sharing_unsupported_capability', SubscriptionPolicyException::class];
        yield 'unsupported route' => [403, 'subscription_sharing_route_not_supported', SubscriptionPolicyException::class];
        yield 'scope' => [403, 'chatpass_v2_scope_not_authorized', SubscriptionPolicyException::class];
        yield 'authorization context' => [403, 'chatpass_v2_invalid_authorization_context', SubscriptionPolicyException::class];
        yield 'invalid user' => [401, 'subscription_sharing_invalid_user', SubscriptionPolicyException::class];
        yield 'usage unavailable' => [503, 'subscription_sharing_usage_unavailable', ServerException::class];
        yield 'user unavailable' => [503, 'subscription_sharing_user_unavailable', ServerException::class];
    }

    public function testProgressThenErrorDoesNotRetryOrReportACompleteTool(): void
    {
        $events = [['type' => 'response.output_text.delta', 'delta' => 'partial'], ['type' => 'response.failed', 'response' => ['error' => ['code' => 'server_error', 'message' => 'secret-marker']]]];
        $client = new MockHttpClient(new MockResponse(AuthFixture::sse($events)));
        $response = (new EventSourceHttpClient($client))->request('POST', 'https://example.invalid');
        $result = (new ResultConverter())->convert(new RawHttpResult($response, new ResponsesStream()));
        self::assertInstanceOf(StreamResult::class, $result);
        $stream = $result->getContent();
        self::assertInstanceOf(TextDelta::class, $stream->current());
        self::assertSame('partial', $stream->current()->getText());
        try {
            $stream->next();
            self::fail('Expected error after partial progress.');
        } catch (ServerException $error) {
            self::assertStringNotContainsString('secret-marker', $error->getMessage());
            self::assertSame(1, $client->getRequestsCount());
        }
    }
}
