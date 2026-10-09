<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAIChatGPT\Tests\Auth;

use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Auth\AuthCommand;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Auth\OAuthConfig;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Tests\Support\AuthFixture;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Tests\Support\InMemoryAuthStorage;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class AuthCommandTest extends TestCase
{
    public function testInvokableConsoleRegistrationRefreshesWithoutPrintingCredentials(): void
    {
        $storage = new InMemoryAuthStorage(AuthFixture::record());
        $http = new MockHttpClient(new MockResponse('{"access_token":"new-access","refresh_token":"new-refresh","expires_in":3600}'));
        $app = new Application();
        $app->addCommand(new AuthCommand(AuthFixture::service($storage, $http), new OAuthConfig('Test app')));
        $command = $app->find('auth:chatgpt');
        self::assertTrue($command->getDefinition()->hasOption('no-browser'));
        self::assertTrue($command->getDefinition()->hasOption('manual'));
        $tester = new CommandTester($command);
        self::assertSame(0, $tester->execute(['action' => 'refresh']));
        self::assertStringContainsString('credentials refreshed', $tester->getDisplay());
        self::assertStringNotContainsString('new-access', $tester->getDisplay());
        self::assertStringNotContainsString('new-refresh', $tester->getDisplay());
        self::assertSame('new-access', $storage->record?->access);
    }

    public function testManualLoginUsesCompleteCallbackAndShowsUsageManagement(): void
    {
        $nonce = '';
        // The token callback must observe the nonce chosen by the service after the manual answer is prepared.
        $http = new MockHttpClient(static function (string $method, string $url) use (&$nonce): MockResponse {
            return new MockResponse(json_encode(str_ends_with($url, '/jwks.json') ? AuthFixture::jwks() : AuthFixture::token($nonce), \JSON_THROW_ON_ERROR));
        });
        $storage = new InMemoryAuthStorage();
        $output = new BufferedOutput();
        $answer = static function () use ($output, &$nonce): string {
            $printed = $output->fetch();
            self::assertSame(1, preg_match('~https://auth\.openai\.com/api/accounts/authorize\?[^\s]+~', $printed, $match));
            parse_str((string) parse_url($match[0], \PHP_URL_QUERY), $query);
            self::assertIsString($query['nonce']);
            self::assertIsString($query['state']);
            $nonce = $query['nonce'];

            return 'http://127.0.0.1:1455/auth/callback?code=code&client_id=issued-client&state='.$query['state'];
        };
        $io = new class(new ArrayInput([]), $output, $answer) extends SymfonyStyle {
            public function __construct(ArrayInput $input, BufferedOutput $output, private readonly \Closure $answer)
            {
                parent::__construct($input, $output);
            }

            public function ask(string $question, ?string $default = null, ?callable $validator = null): mixed
            {
                return ($this->answer)();
            }
        };
        $command = new AuthCommand(AuthFixture::service($storage, $http), new OAuthConfig('Test app'));
        self::assertSame(0, $command($io, manual: true, noBrowser: true));
        self::assertSame('issued-client', $storage->record?->clientId);
        self::assertStringContainsString(OAuthConfig::USAGE_URL, $output->fetch());
    }

    public function testDisconnectCommandRevokesRatherThanJustDeletingLocalTokens(): void
    {
        $storage = new InMemoryAuthStorage(AuthFixture::record());
        $http = new MockHttpClient([new MockResponse('{"issuer":"https://auth.openai.com","revocation_endpoint":"https://auth.openai.com/api/accounts/oauth/revoke"}'), new MockResponse('')]);
        $app = new Application();
        $app->addCommand(new AuthCommand(AuthFixture::service($storage, $http), new OAuthConfig('Test app')));
        $tester = new CommandTester($app->find('auth:chatgpt'));
        self::assertSame(0, $tester->execute(['action' => 'disconnect']));
        self::assertSame(2, $http->getRequestsCount());
        self::assertNull($storage->record?->refresh);
        self::assertSame('issued-client', $storage->record?->clientId);
    }
}
