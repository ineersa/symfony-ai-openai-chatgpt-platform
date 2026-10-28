<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAIChatGPT\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Exception\ProviderErrorExceptionInterface;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Exception\UnmappedBadRequestException;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\ProviderErrorMapper;
use Symfony\AI\Platform\Exception\RuntimeException;
use Symfony\AI\Platform\Exception\ServerException;

final class ProviderErrorMapperTest extends TestCase
{
    /** @param array<string, mixed> $error */
    #[\PHPUnit\Framework\Attributes\DataProvider('mappedErrors')]
    public function testKnownErrorsKeepTheirClassAndMessage(array $error, ?int $status, string $class, string $message): void
    {
        try {
            ProviderErrorMapper::throwError($error + ['message' => 'private provider body'], $status);
        } catch (\Throwable $exception) {
            self::assertSame($class, $exception::class);
            self::assertSame($message, $exception->getMessage());
            self::assertNotInstanceOf(ProviderErrorExceptionInterface::class, $exception);
        }
    }

    /** @return iterable<string, array{array<string, mixed>, int|null, class-string<\Throwable>, string}> */
    public static function mappedErrors(): iterable
    {
        yield 'subscription' => [['code' => 'insufficient_quota'], 400, \Symfony\AI\Platform\Bridge\OpenAIChatGPT\Exception\SubscriptionLimitException::class, 'ChatGPT subscription usage is unavailable. Manage usage at https://chatgpt.com/settings/usage'];
        yield 'policy' => [['code' => 'chatpass_v2_scope_not_authorized'], 403, \Symfony\AI\Platform\Bridge\OpenAIChatGPT\Exception\SubscriptionPolicyException::class, 'ChatGPT rejected subscription access. Check account eligibility, authorization, and supported request capabilities.'];
        yield 'context' => [['code' => 'context_length_exceeded'], 400, \Symfony\AI\Platform\Exception\ExceedContextSizeException::class, 'ChatGPT context exceeds the model limit.'];
        yield 'content' => [['code' => 'content_filter'], 400, \Symfony\AI\Platform\Exception\ContentFilterException::class, 'ChatGPT declined the content.'];
        yield 'auth' => [[], 401, \Symfony\AI\Platform\Exception\AuthenticationException::class, 'ChatGPT authentication failed. Reauthorize with auth:chatgpt login.'];
        yield 'rate limit' => [[], 429, \Symfony\AI\Platform\Exception\RateLimitExceededException::class, 'Rate limit exceeded. ChatGPT request was rate limited. Manage usage at https://chatgpt.com/settings/usage'];
        yield 'server' => [[], 503, ServerException::class, 'Server error (HTTP 503). ChatGPT is temporarily unavailable.'];
    }

    public function testUnmappedBadRequestRetainsOnlyStructuredMetadata(): void
    {
        try {
            ProviderErrorMapper::throwError(['code' => 'string_above_max_length', 'type' => 'invalid_request_error', 'param' => 'input[3].call_id', 'message' => 'secret prompt'], 400);
        } catch (UnmappedBadRequestException $exception) {
            self::assertSame(['code' => 'string_above_max_length', 'type' => 'invalid_request_error', 'param' => 'input[3].call_id'], $exception->getProviderError());
            self::assertSame(400, $exception->getStatusCode());
            self::assertSame('ChatGPT rejected the request or account permissions.', $exception->getMessage());
        }
    }

    public function testUnmappedGenericFailureRejectsUnsafeMetadata(): void
    {
        try {
            ProviderErrorMapper::throwError(['code' => "bad\033[31m", 'type' => str_repeat('x', 129), 'param' => ['secret'], 'message' => 'secret prompt']);
        } catch (RuntimeException $exception) {
            self::assertInstanceOf(ProviderErrorExceptionInterface::class, $exception);
            self::assertSame([], $exception->getProviderError());
            self::assertSame('ChatGPT request failed.', $exception->getMessage());
        }
    }

    public function testRecognizesStructuredTypeEvenWhenCodeIsUnknown(): void
    {
        $this->expectException(ServerException::class);
        ProviderErrorMapper::throwError(['code' => 'unknown', 'type' => 'server_error', 'message' => 'secret-marker']);
    }

    public function testMalformedCodesDoNotGetInterpolatedIntoErrors(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ChatGPT request failed.');
        ProviderErrorMapper::throwError(['code' => ['secret-marker'], 'type' => null, 'message' => 'secret-marker']);
    }
}
