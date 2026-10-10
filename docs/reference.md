# API reference

Package classes use the namespace `Symfony\AI\Platform\Bridge\OpenAIChatGPT` unless stated otherwise.

## Factory

`Factory::createProvider()` returns a Symfony AI `ProviderInterface`.

| Argument | Required | Default | Purpose |
| --- | --- | --- | --- |
| `auth` | Yes | | `Auth\OAuthService` supplying a usable access token |
| `httpClient` | Yes | | Symfony `HttpClientInterface` for inference |
| `modelCatalog` | Yes | | Symfony AI `ModelCatalogInterface` for model-name lookup |
| `contract` | No | `ChatGPTContract::create()` | Serialization contract |
| `eventDispatcher` | No | `null` | Symfony event dispatcher |
| `name` | No | `chatgpt` | Provider name |

The factory does not discover models or verify entitlement. See [model registration](usage.md#register-models).

## OAuth configuration

`Auth\OAuthConfig` is a readonly object.

| Constructor argument | Default | Purpose |
| --- | --- | --- |
| `appName` | Required | Name of the application requesting access |
| `port` | `1455` | Loopback callback port |
| `callbackTimeout` | `300.0` | Seconds to wait for the browser callback |

The redirect URI is `http://127.0.0.1:<port>/auth/callback`.

| Constant | Value |
| --- | --- |
| `ISSUER` | `https://auth.openai.com` |
| `RESOURCE` | `https://api.openai.com/v1` |
| `DIRECT_SCOPE` | `chatgpt.tokens.use.direct` |
| `SCOPE` | `openid profile email offline_access resource.invoke chatgpt.tokens.use.direct` |
| `USAGE_URL` | `https://chatgpt.com/settings/usage` |

## auth:chatgpt

Register `Auth\AuthCommand` in a Symfony Console application. It accepts an action argument, defaulting to `login`.

| Action | Effect |
| --- | --- |
| `login` | Start sign-in or reauthorize the saved registration |
| `refresh` | Force a token refresh |
| `disconnect` | Revoke the grant and retain the issued registration |

| Option | Default | Effect |
| --- | --- | --- |
| `--no-browser` | Off | Print the URL without opening a browser |
| `--manual` | Off | Ask for the complete callback URL instead of listening on loopback |
| `--consent` | Off | Add `prompt=consent`; valid with `login` only |

Port and timeout are constructor settings, not command options. See [authentication](auth.md) for procedures.

## Storage

`Auth\AuthFileStore(string $path, LockFactory $lockFactory, Filesystem $filesystem = new Filesystem())` implements `Auth\AuthStorageInterface`.

| Method | Return type | Contract |
| --- | --- | --- |
| `installationId()` | `string` | Stable installation identity, persisted before authorization |
| `load()` | `?AuthRecord` | Latest saved record, without refreshing it |
| `update(callable $update)` | `AuthRecord` | Lock, reread, mutate, and persist the record atomically |

The update callback receives `?AuthRecord` and returns `AuthRecord`. A custom backend must preserve the record's serialized fields, including `pendingRefresh`, and provide the same cross-worker guarantees.

A saved record is not proof of usable credentials. Use `OAuthService::accessToken()` rather than checking `load() !== null` or reading `access` directly. See [grant states and recovery](oauth.md).

## Verification and clocks

`Auth\IdTokenVerifier` accepts an HTTP client, an optional Symfony `CacheInterface`, and an optional Symfony `ClockInterface`. Defaults are a process-local `ArrayAdapter` and `NativeClock`.

Signing keys are cached for one hour. An unknown key ID triggers one fresh fetch. Supply a shared cache if workers should reuse the same public keys.

When overriding clocks, inject the same clock into `OAuthService` and `IdTokenVerifier`. `verify()` validates at the current clock. `verifyReceived()` is for staged refresh grants and must receive a trusted timestamp from protected storage, not a token claim. See [delayed verification](oauth.md#recover-after-token-rotation).

## Request limits

| Setting | Behavior |
| --- | --- |
| Endpoint | `POST https://api.openai.com/v1/responses` |
| Storage and streaming | Always `store: false` and `stream: true` |
| History | Full input array on every request; no `previous_response_id` |
| Instructions | Framework system messages become instructions; raw system input becomes developer input |
| Tools | Locally executed function tools in developer `additional_tools`; names unchanged; schemas default to `strict: false` |
| Reasoning | Requests encrypted content for models declaring `Capability::THINKING` |
| Generation timeout | 300-second idle timeout; `max_duration: 0` overrides the client's total cap for this request |
| OAuth timeout | 15-second idle timeout and 30-second total cap for token HTTP requests |
| Retry | No automatic inference retry or SSE reconnection |

Supported input item types are `message`, `reasoning`, `function_call`, `function_call_output`, `additional_tools`, and `configuration_update`. Ordinary role/content messages may omit `type`. Hosted tools and tool-search items are rejected.

The request body retains these fields: `model`, `input`, `instructions`, `reasoning`, `text`, `tool_choice`, `parallel_tool_calls`, `prompt_cache_key`, `include`, and `service_tier`. The client then sets `store` and `stream`. Other fields, including unsupported preview fields and host-only options, are omitted.

Structured output and invalid UTF-8 substitution use Symfony AI OpenResponses facilities. See OpenAI's [preview limitations](https://developers.openai.com/siwc/token-sharing-open-source/preview-limitations) for service restrictions beyond the adapter's checks.

## Errors

HTTP failures and streamed error events use the same classification. Provider messages and response bodies are not copied into exceptions.

| Exception | Meaning | Host action |
| --- | --- | --- |
| `Exception\SubscriptionLimitException` | A recognized permanent quota limit | Do not retry; show the usage link |
| `Exception\SubscriptionPolicyException` | A recognized subscription eligibility, scope, capability, or route restriction | Do not retry; inspect its `errorCode`; do not clear credentials automatically |
| Symfony AI `AuthenticationException` | Authentication failure | Ask the user to reauthorize; the package does not replay the request |
| Symfony AI `RateLimitExceededException` | Temporary rate limit | Apply the host's retry policy |
| Symfony AI `ServerException` | Temporary server or subscription availability failure | Apply the host's retry policy |
| Symfony AI `ExceedContextSizeException` | Context limit exceeded | Reduce history through the host's context policy |
| Symfony AI `ContentFilterException` | Content refused | Report the refusal |
| Symfony AI `BadRequestException` | Rejected request or permissions | Correct the request or permissions |
| `Auth\AuthException` | OAuth, identity, or storage failure | Report the failure; see [OAuth recovery](oauth.md) |

Empty, interrupted, failed, and incomplete streams also raise errors. Unfinished tool calls are never completed for dispatch. Unknown provider error codes use a generic Symfony AI runtime exception unless HTTP status determines another type; do not infer a retry guarantee from an unknown code.

## Generic OAuth helpers

These helpers can also serve other public OAuth clients:

| Helper | Contract |
| --- | --- |
| `Auth\PublicOAuthProvider` | Extends League `GenericProvider`; omits `approval_prompt` and an empty client secret |
| `Auth\BrowserLauncher::open(string, ?LoggerInterface): bool` | Opens the authorization URL |
| `Auth\LocalCallbackServer::waitForCallback(string, float, int, ?callable, string): ?array` | Waits for a loopback callback |
| `Auth\ManualCodeParser::parse(string): array` | Returns parsed `code`, `state`, `client_id`, and `error` fields |

Callers enforce their own redirect and state requirements. ChatGPT enforces both. Browser and callback degradation logs contain scalar context, not authorization URLs or token responses.
