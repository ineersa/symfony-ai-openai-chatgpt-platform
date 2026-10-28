<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAIChatGPT\Tests\Validation;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Validation\ProviderErrorMetadataValidator;

final class ProviderErrorMetadataValidatorTest extends TestCase
{
    #[DataProvider('invalidValues')]
    public function testRejectsUntrustedValues(mixed $value): void
    {
        self::assertSame([], ProviderErrorMetadataValidator::validate(['code' => $value, 'type' => $value, 'param' => $value]));
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidValues(): iterable
    {
        yield 'array' => [['secret']];
        yield 'integer' => [400];
        yield 'null' => [null];
        yield 'empty' => [''];
        yield 'oversized' => [str_repeat('x', 129)];
        yield 'terminal control' => ["bad\033[31m"];
        yield 'line break' => ["bad\nvalue"];
        yield 'invalid UTF-8' => ["bad\xff"];
        yield 'free text' => ['private prompt text'];
    }

    public function testRetainsBoundedIdentifiersOnly(): void
    {
        $expected = ['code' => str_repeat('x', 128), 'type' => 'invalid_request_error', 'param' => 'input[3].call_id'];
        self::assertSame($expected, ProviderErrorMetadataValidator::validate($expected + ['message' => 'private prompt text']));
    }
}
