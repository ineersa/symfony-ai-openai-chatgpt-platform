<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAIChatGPT\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\ProviderErrorMapper;
use Symfony\AI\Platform\Exception\RuntimeException;
use Symfony\AI\Platform\Exception\ServerException;

final class ProviderErrorMapperTest extends TestCase
{
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
