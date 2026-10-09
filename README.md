# Symfony AI ChatGPT platform

Single-account Sign in with ChatGPT OAuth and an HTTP-only OpenAI Responses bridge for PHP 8.5 and Symfony AI 0.14. This package does not use the legacy Codex backend or WebSockets.

## Configure authentication

Supply an application name, an auth file path and a Symfony Lock factory. Use the same path and a cross-process lock backend in every worker. The file store retains unrelated top-level records, normalizes the path, writes atomically with mode `0600`, and persists the installation UUID before login succeeds.

```php
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Auth\AuthCommand;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Auth\AuthFileStore;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Auth\IdTokenVerifier;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Auth\OAuthConfig;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Auth\OAuthService;
use Symfony\Component\Console\Application;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

$filesystem = new Filesystem();
$filesystem->mkdir(__DIR__.'/var/locks', 0700);
$httpClient = HttpClient::create();
$storage = new AuthFileStore(
	__DIR__.'/var/auth.json',
	new LockFactory(new FlockStore(__DIR__.'/var/locks')),
);
$config = new OAuthConfig('Your application');
$oauth = new OAuthService($storage, $httpClient, new IdTokenVerifier($httpClient), $config);
$application = new Application();
$application->addCommand(new AuthCommand($oauth, $config));
$application->run();
```

Run the command through the host's console executable:

```console
php bin/console auth:chatgpt login
php bin/console auth:chatgpt login --manual --no-browser
php bin/console auth:chatgpt refresh
php bin/console auth:chatgpt disconnect
```

The browser callback listens on `127.0.0.1:1455/auth/callback` by default. `OAuthConfig` accepts a different port and callback timeout. Manual login requires the complete matching callback URL, including state and the issued client ID on first registration. Never paste a bare code for this flow.

The service uses League OAuth PKCE S256, fresh state and nonce, and the direct-token resource and scope. It verifies ID-token RS256 signatures against OpenAI JWKS, issuer, audience, expiration, nonce and returning account identity. Issued registration IDs survive failed exchange, refresh and disconnect. A pending registration has no authenticated identity or usable tokens. Reauthorization uses the saved client ID and an ID-token hint when available.

Refresh re-reads under the storage lock, rotates credentials together, retains identity when refresh omits an ID token, and validates a newly returned identity. Refresh may omit nonce; a supplied nonce must match the saved authorization. Token operations have a separate 30-second duration budget. Remote disconnect discovers the revocation endpoint. A revocation failure leaves local credentials intact and raises an error rather than reporting success.

`AuthStorageInterface` exposes `installationId()`, `load()` and atomic `update(callable)`. Custom storage must provide the same cross-worker read/update guarantees. `AuthRecord` contains registration, identity, scopes, nonce and nullable grant credentials. `load() !== null` does not imply a connected account; pending and disconnected registrations have `access === null`.

## Configure inference

Pass the host's projected `ResponsesModel` catalog to the factory. The package does not discover or synchronize models at startup, and a configured model is not proof of account entitlement.

```php
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Factory;

$provider = Factory::createProvider(
	$oauth,
	$httpClient,
	$modelCatalog,
	eventDispatcher: $eventDispatcher,
	name: 'chatgpt',
);
```

The factory also accepts an optional Symfony AI `Contract`. It reuses the OpenResponses contract and converters by default.

Requests use `https://api.openai.com/v1/responses`, `store: false`, `stream: true` and full history. The adapter omits unsupported preview fields and internal host options. System input becomes developer input; framework system messages become instructions. Local function tools are placed in developer `additional_tools` items without renaming dispatch functions. Their default schema mode is non-strict, matching Pi's optional-field behavior. Hosted tools, tool search and unsupported history items are rejected.

Encrypted reasoning is requested for thinking-capable models and replayed through the framework contract. Paired `call_id|item_id` values retain both identities internally and are split into their wire fields on replay. Structured output and invalid UTF-8 substitution reuse OpenResponses facilities.

Generation uses a 300-second idle timeout and `max_duration: 0`, overriding an injected client's total duration default for this request only. Supply an ordinary Symfony HTTP client or a host-controlled decorator; the package adds no automatic transport or whole-turn retry. It uses framework `AsyncResponse` and `RawSseStream`, avoiding `EventSourceHttpClient`'s automatic reconnection policy. HTTP 401 does not trigger an inline replay. The host owns retry policy and cancellation through the preserved `RawHttpResult` response's `cancel()` method.

Empty, interrupted, failed and incomplete streams raise errors. Unfinished tool calls never become complete dispatchable calls. Provider error messages and bodies are not copied into exceptions. Known permanent quota codes raise `Exception\SubscriptionLimitException`; host retry classification must treat that type as non-retryable. Transient rate-limit and server errors use Symfony AI exception types. Unknown provider error codes still need live account validation.

## Present usage

Use `OAuthConfig::USAGE_URL` to print or display `https://chatgpt.com/settings/usage` in the host's usage interface. This is a management link, not a numerical quota probe. The package does not call a quota API or infer credential types from token prefixes.

## Reuse generic OAuth helpers

`Auth\PublicOAuthProvider` extends League `GenericProvider`, omitting `approval_prompt` and an empty client secret. Other public clients can supply their own provider options and standard League collaborators.

`BrowserLauncher::open(string, ?LoggerInterface): bool`, `LocalCallbackServer::waitForCallback(string, float, int, ?callable, string): ?array`, and `ManualCodeParser::parse(string): array` are provider-neutral. The parser returns `code`, `state`, `client_id` and `error`; callers enforce their own redirect and state requirements. ChatGPT always enforces both. Browser-launch and callback degradation logs contain scalar context, not authorization URLs or token responses.

## Validate changes

Install development dependencies, then run:

```console
composer install
castor test
castor lint
castor phpstan
castor cs-check
castor composer-validate
```

Tests use synthetic signed tokens, isolated files and mocked HTTP responses. They do not authenticate a real account. Live login, model access and host cancellation remain separate acceptance checks.

References: [Sign in](https://developers.openai.com/siwc/token-sharing-open-source/sign-in), [preview limitations](https://developers.openai.com/siwc/token-sharing-open-source/preview-limitations), and [models and inference](https://developers.openai.com/siwc/token-sharing-open-source/models-and-inference).
