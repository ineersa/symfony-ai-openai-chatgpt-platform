# Send requests and manage streams

Start with the [login and first-request example](../README.md#log-in-and-send-a-message). The examples below reuse its `$oauth` and `$http` variables.

## Register models

The package does not discover or synchronize models. Register models your account can use and declare their capabilities:

```php
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Factory;
use Symfony\AI\Platform\Bridge\OpenResponses\ModelCatalog;
use Symfony\AI\Platform\Bridge\OpenResponses\ResponsesModel;
use Symfony\AI\Platform\Capability;

$modelName = 'YOUR_MODEL';
$catalog = new ModelCatalog([
	$modelName => [
		'class' => ResponsesModel::class,
		'capabilities' => [Capability::INPUT_MESSAGES, Capability::OUTPUT_TEXT],
	],
]);
$provider = Factory::createProvider($oauth, $http, $catalog);
```

Add `Capability::THINKING` for models that support reasoning. This tells the client to request encrypted reasoning for later replay. Add `Capability::TOOL_CALLING` when your application supplies local tools.

Registration does not establish account entitlement. Consult OpenAI's [models and inference guide](https://developers.openai.com/siwc/token-sharing-open-source/models-and-inference) for the account's available models.

## Send a message

The package requires a non-empty string `prompt_cache_key`; the OpenAI API makes this field optional. Generate the key once when starting a conversation, not before each request:

```php
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;

$conversationKey = bin2hex(random_bytes(16));
$result = $provider->invoke(
	$modelName,
	new MessageBag(Message::ofUser('Hello')),
	['prompt_cache_key' => $conversationKey],
);
foreach ($result->asTextStream() as $text) {
	echo $text;
}
```

You can also pass a `ResponsesModel` instance directly, as the README does. In that case, the instance supplies its own capabilities.

## Continue a conversation

Every HTTP request sends the full history. Include earlier assistant output and tool results in the next `MessageBag`; there is no server-side conversation or `previous_response_id` continuation.

For durable sessions, keep native message boundaries, phases, encrypted reasoning signatures, and tool identities. Do not reduce an assistant turn to its concatenated visible text. See [history and reasoning](replay.md) before implementing persistence.

Reuse the same `prompt_cache_key` for all requests and retries in the conversation. Persist it with the history when continuing across processes. The package sends the key in the body and matching `session-id` and `x-client-request-id` headers to aid routing to the same cache shard.

Cache reuse depends on identical prompt prefixes, not just a matching key. Measurements on the ChatGPT OAuth endpoint showed better reuse with `session-id` than with the body key alone; this is observed routing behavior, not a cache-hit guarantee. Set the key through invocation options rather than configuring headers separately.

Changing `reasoning.effort` between requests can reduce prompt-cache reuse. See [change reasoning effort](replay.md#change-reasoning-effort).

## Execute local tools

Supply Symfony AI function tools through invocation options. The adapter places them in a developer `additional_tools` item and keeps their dispatch names unchanged.

Your application executes completed tool calls and appends their results to history. Do not execute a call until its arguments are complete. Interrupted streams raise an error rather than turning partial arguments into a dispatchable call.

Function schemas default to `strict: false`. Hosted tools and tool-search items are rejected. See [request limits](reference.md#request-limits) for supported history types.

## Cancel a stream

If your application stops consuming a response early, cancel its underlying HTTP response:

```php
use Symfony\AI\Platform\Result\RawHttpResult;

try {
	foreach ($result->asTextStream() as $text) {
		echo $text;
		// Break here when your application's cancellation signal is set.
	}
} finally {
	$raw = $result->getRawResult();
	if ($raw instanceof RawHttpResult) {
		$raw->getObject()->cancel();
	}
}
```

The generation request has a 300-second idle timeout and no total duration cap. Token requests have a separate 30-second cap.

## Enable request compression

Install `ext-zstd` in the PHP runtime that sends requests. The package detects it automatically; no invocation option is needed. Confirm it is loaded with `php --ri zstd`.

Without compression, requests exceeding roughly 1 MiB can fail with HTTP 503 because of an observed OpenAI gateway upload-limit bug. This is not a documented universal API limit. Compression reduces uploaded bytes without removing history, but does not guarantee that the model's full context window will fit.

See [request transport](reference.md#request-transport) for compression guards and headers.

## Handle failures

The package does not retry inference or reconnect an interrupted stream. Your application owns retries, including what to do with partially displayed output. An HTTP 401 does not trigger an automatic refresh-and-replay attempt.

Treat `SubscriptionLimitException` and `SubscriptionPolicyException` as non-retryable. Show the usage link for quota limits; do not clear credentials automatically for a subscription policy restriction. See the [error reference](reference.md#errors).
