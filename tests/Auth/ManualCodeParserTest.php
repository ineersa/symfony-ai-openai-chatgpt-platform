<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAIChatGPT\Tests\Auth;

use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Auth\AuthException;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Auth\ManualCodeParser;

final class ManualCodeParserTest extends TestCase
{
    public function testReturnsRegistrationAndStateWithoutDroppingErrorCallbacks(): void
    {
        self::assertSame(['code' => 'c', 'state' => 's', 'client_id' => 'issued', 'error' => null], ManualCodeParser::parse('http://127.0.0.1:1455/auth/callback?code=c&state=s&client_id=issued'));
        self::assertSame('access_denied', ManualCodeParser::parse('state=s&error=access_denied')['error']);
        $callback = ManualCodeParser::parse('state=s&error=access_denied&error_description=actual+provider+explanation&error_uri=https%3A%2F%2Fexample.invalid%2Fdetails');
        self::assertSame('actual provider explanation', $callback['error_description']);
        self::assertSame('https://example.invalid/details', $callback['error_uri']);
        self::assertSame('bare-code', ManualCodeParser::parse('bare-code')['code']);
        self::assertNull(ManualCodeParser::parse('bare-code')['state']);
        self::assertSame('s', ManualCodeParser::parse('c#s')['state']);
    }

    public function testRejectsNonScalarCallbacks(): void
    {
        $this->expectException(AuthException::class);
        ManualCodeParser::parse('code[]=value&state=s');
    }
}
