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
