<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAIChatGPT\Auth;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/** One-shot loopback callback listener, shared with other public-client OAuth flows. */
final class LocalCallbackServer
{
    public function __construct(private readonly LoggerInterface $logger = new NullLogger())
    {
    }

    /**
     * @param callable(): void|null $afterListen
     *
     * @return array{code: ?string, state: ?string, client_id: ?string, error: ?string}|null
     */
    public function waitForCallback(string $expectedState, float $timeoutSeconds = 300.0, int $port = OAuthConfig::DEFAULT_PORT, ?callable $afterListen = null, string $callbackPath = '/auth/callback'): ?array
    {
        if ($timeoutSeconds <= 0 || $port < 1 || $port > 65535 || !str_starts_with($callbackPath, '/')) {
            throw new \InvalidArgumentException('Invalid loopback callback configuration.');
        }
        $server = @stream_socket_server('tcp://127.0.0.1:'.$port, $errno, $error);
        if (false === $server) {
            $this->logger->warning('oauth.callback_bind_failed', ['component' => 'oauth', 'event_type' => 'oauth.callback_bind_failed', 'port' => $port]);

            return null;
        }
        try {
            if (null !== $afterListen) {
                $afterListen();
            }
            $connection = @stream_socket_accept($server, $timeoutSeconds);
            if (false === $connection) {
                $this->logger->notice('oauth.callback_timeout', ['component' => 'oauth', 'event_type' => 'oauth.callback_timeout']);

                return null;
            }
            try {
                stream_set_timeout($connection, 5);
                $line = fgets($connection, 16384);
                if (!\is_string($line) || 1 !== preg_match('~^GET ([^ ]+) HTTP/1\.[01]\r?\n$~', $line, $match)
                    || $callbackPath !== parse_url($match[1], \PHP_URL_PATH)) {
                    throw new AuthException('Invalid OAuth callback request.');
                }
                $result = ManualCodeParser::parse('http://127.0.0.1:'.$port.$match[1]);
                if (null === $result['state'] || !hash_equals($expectedState, $result['state'])) {
                    throw new AuthException('OAuth callback state mismatch.');
                }
                $body = 'Callback received. Return to the terminal to finish authentication.';
                fwrite($connection, "HTTP/1.1 200 OK\r\nContent-Type: text/plain; charset=utf-8\r\nConnection: close\r\nContent-Length: ".\strlen($body)."\r\n\r\n".$body);

                return $result;
            } finally {
                fclose($connection);
            }
        } finally {
            fclose($server);
        }
    }
}
