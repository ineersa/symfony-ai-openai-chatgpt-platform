<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAIChatGPT\Tests;

use Composer\InstalledVersions;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\TokenUsageExtractor;
use Symfony\AI\Platform\Result\RawHttpResult;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class TokenUsageExtractorTest extends TestCase
{
    public function testExtractionDoesNotTryToConsumeSseAsJsonWithoutAStreamOption(): void
    {
        $raw = new RawHttpResult((new MockHttpClient(new MockResponse('not JSON')))->request('GET', 'https://example.invalid'));
        self::assertNull((new TokenUsageExtractor())->extract($raw));
    }

    public function testFromDataArrayMapsCacheWriteTokensWhenPresent(): void
    {
        $usage = (new TokenUsageExtractor())->fromDataArray([
            'usage' => [
                'input_tokens' => 100,
                'output_tokens' => 20,
                'total_tokens' => 120,
                'input_tokens_details' => [
                    'cached_tokens' => 40,
                    'cache_write_tokens' => 15,
                ],
                'output_tokens_details' => [
                    'reasoning_tokens' => 5,
                ],
            ],
            'model' => 'gpt-test',
        ]);

        self::assertSame(100, $usage->getPromptTokens());
        self::assertSame(20, $usage->getCompletionTokens());
        self::assertSame(5, $usage->getThinkingTokens());
        self::assertSame(40, $usage->getCachedTokens());
        if (self::openResponsesMapsCacheWriteTokens()) {
            self::assertSame(15, $usage->getCacheCreationTokens());
        } else {
            self::assertNull($usage->getCacheCreationTokens());
        }
        self::assertSame(120, $usage->getTotalTokens());
        self::assertSame('gpt-test', $usage->getModel());
    }

    public function testFromDataArrayLeavesCacheCreationTokensAbsentWhenWriteCountIsMissing(): void
    {
        $usage = (new TokenUsageExtractor())->fromDataArray([
            'usage' => [
                'input_tokens' => 10,
                'output_tokens' => 2,
                'total_tokens' => 12,
                'input_tokens_details' => [
                    'cached_tokens' => 3,
                ],
            ],
        ]);

        self::assertSame(3, $usage->getCachedTokens());
        self::assertNull($usage->getCacheCreationTokens());
    }

    private static function openResponsesMapsCacheWriteTokens(): bool
    {
        $version = InstalledVersions::getVersion('symfony/ai-open-responses-platform');

        return null !== $version && version_compare($version, '0.15.0', '>=');
    }
}
