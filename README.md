# OpenAI ChatGPT for Symfony AI

Use your ChatGPT subscription with Symfony AI through [Sign in with ChatGPT](https://developers.openai.com/cookbook/articles/sign-in-with-chatgpt). The package handles browser login, token refresh, and requests to the OpenAI Responses API using only HTTP and server-sent events (SSE).

Requires PHP 8.5+, Symfony AI 0.14, 0.15, or 1.0, and a ChatGPT account that permits subscription sharing. Symfony AI 1.0.0-BETA1 requires a package-specific beta allowance such as `^1.0@beta`; no global stability change is needed.

> [!WARNING]
> Install `ext-zstd` to enable request compression. Without it, uncompressed requests exceeding roughly 1 MiB can fail with HTTP 503 because of an observed OpenAI gateway upload-limit bug, not a documented universal API limit. Compression mitigates this bug but does not guarantee that the model's full context window will fit.

## Install the package

```sh
composer require ineersa/symfony-ai-openai-chatgpt-platform
```

## Log in and send a message

This basic `chatgpt.php` example provides a login command and streams a reply to "Hello". Add `/var/chatgpt/` to `.gitignore` before logging in to keep credentials out of version control.

> [!WARNING]
> This package requires a non-empty `prompt_cache_key`, although the OpenAI API makes it optional. Reuse one key per conversation: the package sends it as `prompt_cache_key`, `session-id`, and `x-client-request-id` to aid routing and cache reuse for identical prompt prefixes, without guaranteeing cache hits.

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Auth\AuthCommand;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Auth\AuthFileStore;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Auth\IdTokenVerifier;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Auth\OAuthConfig;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Auth\OAuthService;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Factory;
use Symfony\AI\Platform\Bridge\OpenResponses\ModelCatalog;
use Symfony\AI\Platform\Bridge\OpenResponses\ResponsesModel;
use Symfony\AI\Platform\Capability;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Component\Console\Application;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

// Keep credentials on disk and coordinate token refresh across local processes.
$lockDir = __DIR__.'/var/chatgpt/locks';
(new Filesystem())->mkdir($lockDir, 0700);
$http = HttpClient::create();
$store = new AuthFileStore(
	__DIR__.'/var/chatgpt/auth.json',
	new LockFactory(new FlockStore($lockDir)),
);
// Use your application's name for OAuth registration and the Originator header.
$config = new OAuthConfig('My CLI');
$oauth = new OAuthService($store, $http, new IdTokenVerifier($http), $config);

// Handle login, refresh, and disconnect through Symfony Console.
if ('ask' !== ($argv[1] ?? null)) {
	$console = new Application();
	$console->addCommand(new AuthCommand($oauth, $config));
	exit($console->run());
}

$modelName = $argv[2] ?? throw new InvalidArgumentException('Usage: php chatgpt.php ask MODEL');
$provider = Factory::createProvider($oauth, $http, new ModelCatalog());
// Each ask starts a new conversation. Persist this key with history to continue it.
$conversationKey = bin2hex(random_bytes(16));
// Invoke an account-supported model; OAuthService refreshes expired credentials.
$result = $provider->invoke(
	new ResponsesModel($modelName, [Capability::INPUT_MESSAGES, Capability::OUTPUT_TEXT]),
	new MessageBag(Message::ofUser('Hello')),
	['prompt_cache_key' => $conversationKey],
);
// Print text as SSE events arrive, rather than waiting for the complete response.
foreach ($result->asTextStream() as $text) {
	echo $text;
}
echo PHP_EOL;
```

Log in, then stream a reply. Replace `YOUR_MODEL` with a model available to your account:

```sh
php chatgpt.php auth:chatgpt login
php chatgpt.php ask YOUR_MODEL
```

If you signed in without enabling plan usage, run `php chatgpt.php auth:chatgpt login --consent`. The provider refreshes expired credentials before sending requests.

## Documentation

| Guide | Use it to |
| --- | --- |
| [Authentication](docs/auth.md) | Register the command, use manual login, refresh, and disconnect |
| [Usage](docs/usage.md) | Configure models, send conversation history, and cancel streams |
| [History and reasoning](docs/replay.md) | Preserve message phases, encrypted reasoning, and effort changes |
| [API reference](docs/reference.md) | Look up options, request limits, and error types |
| [OAuth lifecycle](docs/oauth.md) | Understand verification, storage locks, and recovery after refresh |

## Develop

Run `composer install`, then `castor test`, `castor lint`, `castor phpstan`, `castor cs-check`, and `castor composer-validate`. Tests use mocked HTTP and synthetic credentials, not a real ChatGPT account.

[MIT license](LICENSE).
