# OpenAI ChatGPT for Symfony AI

Use your ChatGPT subscription with Symfony AI over the OpenAI Responses API. The package handles browser login, token refresh, and HTTP streaming.

Requires PHP 8.5+, Symfony AI 0.14, and a ChatGPT account that permits subscription sharing. This package does not use the legacy Codex backend or WebSockets.

## Install the package

There is no stable release yet. Add the Git repository and install the development branch:

```sh
composer config repositories.chatgpt vcs https://github.com/ineersa/symfony-ai-openai-chatgpt-platform
composer require ineersa/symfony-ai-openai-chatgpt-platform:dev-main
```

## Log in and send a message

Save this as `chatgpt.php`. It stores credentials in `var/chatgpt/auth.json`. Add `/var/chatgpt/` to `.gitignore` before logging in.

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

$lockDir = __DIR__.'/var/chatgpt/locks';
(new Filesystem())->mkdir($lockDir, 0700);
$http = HttpClient::create();
$store = new AuthFileStore(
	__DIR__.'/var/chatgpt/auth.json',
	new LockFactory(new FlockStore($lockDir)),
);
$config = new OAuthConfig('My CLI'); // Use your application's name.
$oauth = new OAuthService($store, $http, new IdTokenVerifier($http), $config);

if ('ask' !== ($argv[1] ?? null)) {
	$console = new Application();
	$console->addCommand(new AuthCommand($oauth, $config));
	exit($console->run());
}

$modelName = $argv[2] ?? throw new InvalidArgumentException('Usage: php chatgpt.php ask MODEL');
$provider = Factory::createProvider($oauth, $http, new ModelCatalog());
$result = $provider->invoke(
	new ResponsesModel($modelName, [Capability::INPUT_MESSAGES, Capability::OUTPUT_TEXT]),
	new MessageBag(Message::ofUser('Hello')),
);
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
