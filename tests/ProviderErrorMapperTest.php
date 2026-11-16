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
    public function testKnownErrorsKeepTheirClassAndActualProviderDetails(array $error, ?int $status, string $class): void
    {
        try {
            ProviderErrorMapper::throwError($error + ['message' => 'private provider body', 'details' => ['original' => 'nested provider detail']], $status);
        } catch (\Throwable $exception) {
            self::assertSame($class, $exception::class);
            self::assertStringContainsString('private provider body', $exception->getMessage());
            self::assertStringContainsString('nested provider detail', $exception->getMessage());
            self::assertNotInstanceOf(ProviderErrorExceptionInterface::class, $exception);
        }
    }

    /** @return iterable<string, array{array<string, mixed>, int|null, class-string<\Throwable>}> */
    public static function mappedErrors(): iterable
    {
        yield 'subscription' => [['code' => 'insufficient_quota'], 400, \Symfony\AI\Platform\Bridge\OpenAIChatGPT\Exception\SubscriptionLimitException::class];
        yield 'policy' => [['code' => 'chatpass_v2_scope_not_authorized'], 403, \Symfony\AI\Platform\Bridge\OpenAIChatGPT\Exception\SubscriptionPolicyException::class];
        yield 'context' => [['code' => 'context_length_exceeded'], 400, \Symfony\AI\Platform\Exception\ExceedContextSizeException::class];
        yield 'content' => [['code' => 'content_filter'], 400, \Symfony\AI\Platform\Exception\ContentFilterException::class];
        yield 'auth' => [[], 401, \Symfony\AI\Platform\Exception\AuthenticationException::class];
        yield 'rate limit' => [[], 429, \Symfony\AI\Platform\Exception\RateLimitExceededException::class];
        yield 'server' => [[], 503, ServerException::class];
    }

    public function testUnmappedBadRequestRetainsAllProviderData(): void
    {
        try {
            ProviderErrorMapper::throwError(['code' => 'string_above_max_length', 'type' => 'invalid_request_error', 'param' => 'input[3].call_id', 'message' => 'secret prompt'], 400);
        } catch (UnmappedBadRequestException $exception) {
            self::assertSame(['code' => 'string_above_max_length', 'type' => 'invalid_request_error', 'param' => 'input[3].call_id', 'message' => 'secret prompt'], $exception->getProviderError());
            self::assertSame(400, $exception->getStatusCode());
            self::assertStringContainsString('secret prompt', $exception->getMessage());
        }
    }

    public function testUnmappedFailurePreservesArbitraryProviderData(): void
    {
        try {
            ProviderErrorMapper::throwError(['code' => "bad\033[31m", 'type' => str_repeat('x', 129), 'param' => ['secret'], 'message' => 'secret prompt']);
        } catch (RuntimeException $exception) {
            self::assertInstanceOf(ProviderErrorExceptionInterface::class, $exception);
            self::assertSame(['code' => "bad\033[31m", 'type' => str_repeat('x', 129), 'param' => ['secret'], 'message' => 'secret prompt'], $exception->getProviderError());
            self::assertStringContainsString('secret prompt', $exception->getMessage());
        }
    }

    public function testRecognizesStructuredTypeEvenWhenCodeIsUnknown(): void
    {
        $this->expectException(ServerException::class);
        ProviderErrorMapper::throwError(['code' => 'unknown', 'type' => 'server_error', 'message' => 'secret-marker']);
    }

    public function testMalformedCodesStillPreserveProviderMessage(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('secret-marker');
        ProviderErrorMapper::throwError(['code' => ['secret-marker'], 'type' => null, 'message' => 'secret-marker']);
    }
}
