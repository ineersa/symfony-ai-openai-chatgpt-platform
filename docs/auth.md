# Set up ChatGPT login

Start with the [standalone CLI example](../README.md#log-in-and-send-a-message). The same `AuthCommand` works in an existing Symfony Console application.

## Register the command

1. Create an `AuthFileStore` with your credential path and a Symfony `LockFactory`.
2. Create an `OAuthService` with that store, an HTTP client, an `IdTokenVerifier`, and your `OAuthConfig`.
3. Register `new AuthCommand($oauth, $config)` with `Application::addCommand()`.

Use your application's name in `OAuthConfig`. Keep the auth file out of version control. Workers sharing credentials must use the same file path and lock backend. `FlockStore` is suitable for processes on one machine; use a shared backend when credentials are shared across machines.

The [README](../README.md) contains the complete setup. See [custom storage](reference.md#storage) for databases and secret managers.

## Log in through a browser

With the command registered in `bin/console`, run:

```sh
php bin/console auth:chatgpt login
```

The command prints an authorization URL, opens a browser, and waits for the callback at `http://127.0.0.1:1455/auth/callback`. Complete sign-in and approve plan usage in the browser.

To open the URL yourself while keeping the callback listener:

```sh
php bin/console auth:chatgpt login --no-browser
```

Set the callback port and wait time in `OAuthConfig`, not command flags:

```php
$config = new OAuthConfig('My CLI', port: 1456, callbackTimeout: 120.0);
```

## Log in without a local callback listener

Manual login requires the complete callback URL. A bare authorization code is not enough.

1. Run `php bin/console auth:chatgpt login --manual`.
2. Open the printed URL and complete sign-in.
3. Copy the complete redirect URL from the browser, including its query string.
4. Paste the URL into the command's prompt.

The command checks the redirect, state, and issued client ID. It also offers this prompt if the local listener does not receive a callback.

## Enable plan usage after signing in

A successful sign-in does not necessarily grant permission to spend the subscription. If the command reports that plan usage is disabled, request explicit consent:

```sh
php bin/console auth:chatgpt login --consent
```

This reuses the saved client ID and adds `prompt=consent`. Ordinary login does not force consent. For a custom login interface, call `$oauth->beginAuthorization(consent: true)`.

## Refresh or disconnect

The provider refreshes credentials when needed. To force a refresh without opening a browser:

```sh
php bin/console auth:chatgpt refresh
```

To revoke the grant:

```sh
php bin/console auth:chatgpt disconnect
```

Disconnect retains the registration for later login. If remote revocation fails, the command raises an error and leaves local credentials intact.

## Show the usage link

Display `OAuthConfig::USAGE_URL` in your application's usage interface:

```php
echo OAuthConfig::USAGE_URL.PHP_EOL;
```

The link opens [ChatGPT usage settings](https://chatgpt.com/settings/usage). It is not a quota API and does not return numerical limits.

See the [command reference](reference.md#authchatgpt) for options and the [OAuth lifecycle](oauth.md) for storage and verification details.
