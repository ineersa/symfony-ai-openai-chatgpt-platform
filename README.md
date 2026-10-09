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
php bin/console auth:chatgpt login --consent
php bin/console auth:chatgpt refresh
php bin/console auth:chatgpt disconnect
```

The browser callback listens on `127.0.0.1:1455/auth/callback` by default. `OAuthConfig` accepts a different port and callback timeout. Manual login requires the complete matching callback URL, including state and the issued client ID on first registration. Never paste a bare code for this flow.

The service uses League OAuth PKCE S256, fresh state and nonce, and the direct-token resource and scope. It verifies ID-token RS256 signatures against OpenAI JWKS, issuer, audience, expiration, nonce and returning account identity. Issued registration IDs survive failed exchange, refresh and disconnect. A pending registration has no authenticated identity or usable tokens. The application name hint is sent only during initial dynamic registration. Reauthorization uses the saved client ID and sends an ID-token hint while a verified sign-in with granted scopes is saved. Disconnect retains the verified identity but omits the ID-token hint on the next login.

An identity-only grant retains the verified sign-in but cannot authorize inference. To enable plan usage explicitly, run `auth:chatgpt login --consent`. This sets `prompt=consent`, reuses the issued client ID, and requests all scopes with fresh PKCE, state, and nonce. Ordinary login does not force consent. Hosts can request the same flow with `OAuthService::beginAuthorization(consent: true)`.

Refresh re-reads under the storage lock, rotates credentials together, retains identity when refresh omits an ID token, and validates a newly returned identity. Refresh may omit nonce; a supplied nonce must match the saved authorization. Token operations have a separate 30-second duration budget. Remote disconnect discovers the revocation endpoint. A revocation failure leaves local credentials intact and raises an error rather than reporting success.

Before verifying a rotated grant, refresh persists `AuthRecord::pendingRefresh` in protected storage and removes the consumed access and refresh tokens. `PendingRefreshDTO` contains only the replacement credentials, fixed expiry, scopes, and verification flag. A failed JWKS fetch leaves the replacement pending. Retry, restart, and other workers resume verification under the storage lock without repeating refresh. Pending access and identity never authorize inference. Disconnect revokes the pending replacement when present.

`IdTokenVerifier` caches public signing keys for one hour using Symfony Cache. An unknown key ID triggers one new fetch. The optional second constructor argument accepts a Symfony `CacheInterface`, allowing a host-managed shared cache; the default is a process-local `ArrayAdapter`. No host dependency-injection change is required.

Confirmed unusable refresh-token errors clear access, refresh, and ID tokens under the same storage lock before raising `AuthException`. The issued client ID remains saved for the next login. Temporary failures, `invalid_client`, and unknown errors preserve the grant.

`AuthStorageInterface` exposes `installationId()`, `load()` and atomic `update(callable)`. Custom storage must provide the same cross-worker read/update guarantees and preserve the optional `pendingRefresh` payload. `AuthRecord` contains registration, identity, scopes, nonce and nullable grant credentials. `load() !== null` and non-null access do not establish plan permission. Pending and disconnected registrations have `access === null`; inference also requires the direct-token scope.

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

The factory also accepts an optional Symfony AI `Contract`. Its default `Contract\ChatGPTContract` delegates ordinary parts to OpenResponses and preserves native message signatures and reasoning controls. Use `ChatGPTContract::create($normalizers)` when adding host normalizers that must retain these adaptations.

Requests use `https://api.openai.com/v1/responses`, `store: false`, `stream: true` and full history. The adapter omits unsupported preview fields and internal host options. System input becomes developer input; framework system messages become instructions. Local function tools are placed in developer `additional_tools` items without renaming dispatch functions. Their default schema mode is non-strict, matching Pi's optional-field behavior. Hosted tools, tool search and unsupported history items are rejected.

Encrypted reasoning is requested for thinking-capable models and replayed through the framework contract. Paired `call_id|item_id` values retain both identities internally and are split into their wire fields on replay. Structured output and invalid UTF-8 substitution reuse OpenResponses facilities.

Generation uses a 300-second idle timeout and `max_duration: 0`, overriding an injected client's total duration default for this request only. Supply an ordinary Symfony HTTP client or a host-controlled decorator; the package adds no automatic transport or whole-turn retry. It uses framework `AsyncResponse` and `RawSseStream`, avoiding `EventSourceHttpClient`'s automatic reconnection policy. HTTP 401 does not trigger an inline replay. The host owns retry policy and cancellation through the preserved `RawHttpResult` response's `cancel()` method.

Empty, interrupted, failed and incomplete streams raise errors. Unfinished tool calls never become complete dispatchable calls. Provider error messages and bodies are not copied into exceptions. Known permanent quota codes raise `Exception\SubscriptionLimitException`; host retry classification must treat that type as non-retryable. Transient rate-limit and server errors use Symfony AI exception types. Unknown provider error codes still need live account validation.

Documented terminal subscription restrictions raise `Exception\SubscriptionPolicyException`, not a quota or authentication exception. Hosts must classify this type as non-retryable. Its `errorCode` property contains the recognized SIWC code. An invalid subscriber context does not clear credentials or trigger OAuth automatically. Both HTTP errors and streamed error events use this classification.

## Preserve assistant message items

The stream retains framework `TextDelta`, reasoning, tool, usage, and error conversion. It also emits two package deltas under `Result\Stream\Delta`:

- `MessageStart::getItem()` returns the message header without content, including the original ID and phase when present. `getOutputIndex()` returns the provider index or `null`.
- `MessageComplete::getContent()` returns one framework `Text`. Its signature contains the exact native message item, including ID, name, phase, annotations, and distinct content parts. `getOutputIndex()` identifies its output position when available.

Messages present only in terminal output still emit `MessageComplete`. Completion is not emitted again when both item-done and terminal frames contain the same message ID. A phase announced on item-added is retained if item-done omits it. No phase is invented for messages without one.

For exact replay, persist both `Text::getText()` and `Text::getSignature()` for each completed message, then rebuild separate `Text` parts in the assistant's original order. `MessageItem::toText($nativeItem)` and `MessageItem::fromText($text)` convert between the native item and its signed text part. Clear the signature when editing text; replay rejects text that disagrees with its signed original.

Do not rely on the framework's `StreamResult::getAssistantMessage()` to preserve these boundaries: its listener merges adjacent text and ignores package deltas. The host must combine completed message parts with the framework listener's reasoning and tool slots. Use `MessageStart` to route subsequent visible text by phase, and `MessageComplete` to recover terminal-only content. Retain commentary in the transcript even if the UI separates it from the final answer. These semantic deltas remain visible through `Factory`; generic `MetadataDelta` events would be consumed by the framework metadata listener.

## Replay reasoning configuration updates

Use `ReasoningConfiguration` as an ordered assistant content part, or attach an effort string under `ReasoningConfiguration::METADATA_KEY` to insert the control before that assistant message:

```php
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\ReasoningConfiguration;
use Symfony\AI\Platform\Message\AssistantMessage;
use Symfony\AI\Platform\Message\Content\Text;

$message = new AssistantMessage(new ReasoningConfiguration('high'), new Text('After transition'));
// Alternative for a durable transition anchored before an existing assistant message:
$message = new AssistantMessage(new Text('After transition'));
$message->getMetadata()->add(ReasoningConfiguration::METADATA_KEY, 'high');
```

The normalizer emits `{"type":"configuration_update","reasoning":{"effort":"high"}}` at that position. It does not change a request baseline such as `reasoning.effort: low` or the session's `prompt_cache_key`. The client also accepts validated native control arrays. It rejects malformed controls, unknown effort values, and adjacent controls. Efforts are `none`, `minimal`, `low`, `medium`, `high`, and `xhigh`; model support remains the host's responsibility.

The host owns transition persistence, reset and fork scope, model baselines, and placement after compaction. The adapter adds no automatic compaction, truncation, WebSocket continuation, or fallback to a top-level override. Mocked serialization tests do not establish that the subscription route accepts configuration updates; live route acceptance remains unverified.

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
