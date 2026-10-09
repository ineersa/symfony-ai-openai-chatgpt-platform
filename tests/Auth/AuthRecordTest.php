<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAIChatGPT\Tests\Auth;

use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Auth\AuthException;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Auth\AuthRecord;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Tests\Support\AuthFixture;

final class AuthRecordTest extends TestCase
{
    public function testRoundTripRetainsIdentityAndNonceButDebugInfoHidesCredentials(): void
    {
        $record = AuthFixture::record();
        self::assertEquals($record, AuthRecord::fromArray($record->toArray()));
        self::assertSame('nonce', $record->disconnected()->nonce);
        self::assertSame(['clientId' => 'issued-client', 'expires' => 2000000000, 'connected' => true], $record->__debugInfo());
    }

    public function testMalformedStoredScopeFailsClosed(): void
    {
        $data = AuthFixture::record()->toArray();
        $data['scopes'] = [42];
        $this->expectException(AuthException::class);
        AuthRecord::fromArray($data);
    }
}
