<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAIChatGPT\Tests\Auth;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Auth\AuthException;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Auth\PendingRefreshDTO;

final class PendingRefreshDTOTest extends TestCase
{
    #[DataProvider('invalidReceiptFields')]
    public function testStoredReceiptIsRequiredAndStrictlyValidated(mixed $receipt): void
    {
        $pending = new PendingRefreshDTO('access', 'refresh', 2000000000, 'id', ['openid'], true, 1900000000);
        $data = $pending->toArray();
        if (null === $receipt) {
            unset($data['receivedAt']);
        } else {
            $data['receivedAt'] = $receipt;
        }
        $this->expectException(AuthException::class);
        PendingRefreshDTO::fromArray($data);
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidReceiptFields(): iterable
    {
        yield 'missing' => [null];
        yield 'string' => ['1900000000'];
        yield 'zero' => [0];
        yield 'negative' => [-1];
        yield 'fractional' => [1900000000.5];
    }

    public function testProtectedPayloadRoundTripsButDebugOutputHidesTokens(): void
    {
        $pending = new PendingRefreshDTO('access-secret', 'refresh-secret', 2000000000, 'id-secret', ['openid'], true, 1900000000);
        self::assertEquals($pending, PendingRefreshDTO::fromArray($pending->toArray()));
        self::assertSame(['expires' => 2000000000, 'pending' => true], $pending->__debugInfo());
    }

    public function testMalformedStoredPayloadFailsClosed(): void
    {
        $pending = new PendingRefreshDTO('access', 'refresh', 2000000000, 'id', ['openid'], true, 1900000000);
        $data = $pending->toArray();
        $data['verifyIdentity'] = 'false';
        $this->expectException(AuthException::class);
        PendingRefreshDTO::fromArray($data);
    }
}
