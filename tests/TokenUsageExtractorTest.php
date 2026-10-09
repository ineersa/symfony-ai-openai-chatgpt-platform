<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAIChatGPT\Tests;

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
}
