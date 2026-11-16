<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAIChatGPT\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\ProviderErrorMessage;

final class ProviderErrorMessageTest extends TestCase
{
    public function testActualMessagePrecedesCompletePayloadWithoutTruncation(): void
    {
        $message = "actual rejection\n".str_repeat('x', 4096)."\033[31m";
        $error = ['message' => $message, 'details' => ['nested' => 'original provider context']];
        $formatted = ProviderErrorMessage::format($error, 400);
        self::assertStringStartsWith('HTTP 400: '.$message, $formatted);
        self::assertStringContainsString('original provider context', $formatted);
    }

    public function testNonUtf8ValuesDoNotReplaceProviderFailureWithEncoderFailure(): void
    {
        $message = "actual failure\xff";
        $formatted = ProviderErrorMessage::format(['message' => $message, 'detail' => "binary\xfe"]);
        self::assertStringContainsString($message, $formatted);
        self::assertStringContainsString("binary\xfe", $formatted);
    }

    public function testRawBodyIsPreservedByteForByte(): void
    {
        $body = "<html>upstream failure\xff\033[31m</html>\n";
        self::assertSame("HTTP 502\n".$body, ProviderErrorMessage::format([], 502, $body));
    }
}
