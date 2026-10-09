<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAIChatGPT\Tests\Auth;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Auth\PublicOAuthProvider;

final class PublicOAuthProviderTest extends TestCase
{
    public function testProviderNeutralPublicClientOmitsSecretAndApprovalPrompt(): void
    {
        $history = [];
        $handler = HandlerStack::create(new MockHandler([new Response(200, ['Content-Type' => 'application/json'], '{"access_token":"test","token_type":"Bearer","expires_in":3600}')]));
        $handler->push(Middleware::history($history));
        $provider = new PublicOAuthProvider(['clientId' => 'client', 'redirectUri' => 'http://127.0.0.1:1455/callback', 'urlAuthorize' => 'https://example.invalid/authorize', 'urlAccessToken' => 'https://example.invalid/token', 'urlResourceOwnerDetails' => 'https://example.invalid/user', 'pkceMethod' => 'S256'], ['httpClient' => new Client(['handler' => $handler])]);
        parse_str((string) parse_url($provider->getAuthorizationUrl(), \PHP_URL_QUERY), $query);
        self::assertArrayNotHasKey('approval_prompt', $query);
        self::assertSame('S256', $query['code_challenge_method']);
        $provider->getAccessToken('authorization_code', ['code' => 'test-code']);
        $request = $history[0]['request'] ?? null;
        self::assertInstanceOf(RequestInterface::class, $request);
        parse_str((string) $request->getBody(), $body);
        self::assertArrayNotHasKey('client_secret', $body);
        self::assertArrayHasKey('code_verifier', $body);
    }
}
