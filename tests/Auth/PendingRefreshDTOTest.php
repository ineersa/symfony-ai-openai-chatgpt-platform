<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAIChatGPT\Tests\Auth;

use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Auth\AuthException;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Auth\PendingRefreshDTO;

final class PendingRefreshDTOTest extends TestCase
{
    public function testProtectedPayloadRoundTripsButDebugOutputHidesTokens(): void
    {
        $pending = new PendingRefreshDTO('access-secret', 'refresh-secret', 2000000000, 'id-secret', ['openid'], true);
        self::assertEquals($pending, PendingRefreshDTO::fromArray($pending->toArray()));
        self::assertSame(['expires' => 2000000000, 'pending' => true], $pending->__debugInfo());
    }

    public function testMalformedStoredPayloadFailsClosed(): void
    {
        $pending = new PendingRefreshDTO('access', 'refresh', 2000000000, 'id', ['openid'], true);
        $data = $pending->toArray();
        $data['verifyIdentity'] = 'false';
        $this->expectException(AuthException::class);
        PendingRefreshDTO::fromArray($data);
    }
}
