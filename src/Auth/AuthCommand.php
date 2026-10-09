<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAIChatGPT\Auth;

use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'auth:chatgpt', description: 'Connect, refresh or disconnect one ChatGPT account')]
final readonly class AuthCommand
{
    public function __construct(private OAuthService $service, private OAuthConfig $config, private LocalCallbackServer $callbackServer = new LocalCallbackServer())
    {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'login, refresh or disconnect')] string $action = 'login',
        #[Option(description: 'Do not launch a browser')] bool $noBrowser = false,
        #[Option(description: 'Paste the full callback URL instead of listening on loopback')] bool $manual = false,
        #[Option(description: 'Explicitly request consent to enable ChatGPT plan usage during login')] bool $consent = false,
    ): int {
        if ($consent && 'login' !== $action) {
            throw new \InvalidArgumentException('Use --consent with login only.');
        }
        if ('refresh' === $action) {
            $this->service->refreshCredentials();
            $io->success('ChatGPT credentials refreshed.');

            return Command::SUCCESS;
        }
        if ('disconnect' === $action) {
            $this->service->disconnect();
            $io->success('ChatGPT disconnected. The issued registration is retained for reauthorization.');

            return Command::SUCCESS;
        }
        if ('login' !== $action) {
            throw new \InvalidArgumentException('Choose login, refresh or disconnect.');
        }
        $request = $this->service->beginAuthorization($consent);
        $io->writeln('Open this authorization URL:');
        $io->writeln($request->url);
        $callback = null;
        if (!$manual) {
            $callback = $this->callbackServer->waitForCallback($request->state, $this->config->callbackTimeout, $this->config->port, static function () use ($noBrowser, $request): void {
                if (!$noBrowser) {
                    BrowserLauncher::open($request->url);
                }
            });
        }
        if (null === $callback) {
            $url = $io->ask('Paste the complete callback URL');
            if (!\is_string($url) || '' === trim($url)) {
                throw new AuthException('A complete callback URL is required.');
            }
            $record = $this->service->completeManualAuthorization($request, $url);
        } else {
            $record = $this->service->completeAuthorization($request, $callback);
        }
        if (\in_array(OAuthConfig::DIRECT_SCOPE, $record->scopes, true)) {
            $io->success('ChatGPT connected. Manage plan usage at '.OAuthConfig::USAGE_URL);
        } else {
            $io->warning('ChatGPT sign-in saved; plan usage is disabled. Run auth:chatgpt login --consent to enable it.');
        }

        return Command::SUCCESS;
    }
}
