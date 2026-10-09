<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAIChatGPT\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\ReasoningConfiguration;
use Symfony\AI\Platform\Exception\InvalidArgumentException;

final class ReasoningConfigurationTest extends TestCase
{
    #[DataProvider('advertisedEfforts')]
    public function testNativeConfigurationRoundTripsWithoutRequestBaseline(string $effort): void
    {
        $item = ['type' => 'configuration_update', 'reasoning' => ['effort' => $effort]];
        self::assertSame($item, (new ReasoningConfiguration($effort))->toArray());
        self::assertSame($item, ReasoningConfiguration::fromArray($item)->toArray());
    }

    /** @return iterable<string, array{string}> */
    public static function advertisedEfforts(): iterable
    {
        foreach (['none', 'minimal', 'low', 'medium', 'high', 'xhigh', 'max'] as $effort) {
            yield $effort => [$effort];
        }
    }

    /** @param array<string, mixed> $item */
    #[DataProvider('invalidItems')]
    public function testRejectsInvalidNativeControlShapes(array $item): void
    {
        $this->expectException(InvalidArgumentException::class);
        ReasoningConfiguration::fromArray($item);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidItems(): iterable
    {
        yield 'missing reasoning' => [['type' => 'configuration_update']];
        yield 'extra role' => [['type' => 'configuration_update', 'role' => 'assistant', 'reasoning' => ['effort' => 'high']]];
        yield 'wrong type' => [['type' => 'message', 'reasoning' => ['effort' => 'high']]];
        yield 'non object reasoning' => [['type' => 'configuration_update', 'reasoning' => 'high']];
        yield 'extra reasoning field' => [['type' => 'configuration_update', 'reasoning' => ['effort' => 'high', 'summary' => 'auto']]];
        yield 'unknown effort' => [['type' => 'configuration_update', 'reasoning' => ['effort' => 'maximum']]];
        yield 'non scalar effort' => [['type' => 'configuration_update', 'reasoning' => ['effort' => ['high']]]];
    }
}
