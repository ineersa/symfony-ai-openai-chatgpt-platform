# Preserve history and reasoning

Use this guide when your application saves conversations or reconstructs them in another worker. For a single text response, use the [README example](../README.md).

## Keep native assistant messages

The stream exposes two package deltas under `Result\Stream\Delta`:

| Delta | Data |
| --- | --- |
| `MessageStart` | `getItem()` returns the message header, including its ID and phase when supplied. `getOutputIndex()` returns its native output index or `null`. |
| `MessageComplete` | `getContent()` returns a framework `Text` whose signature contains the complete native message. `getOutputIndex()` returns its native output index or `null`. |

Use `MessageStart` to distinguish commentary from the final answer in your UI. Keep both phases in history. The package retains an announced phase if completion omits it, but never invents a phase.

Messages that appear only in terminal output still emit `MessageComplete`. The same message ID is not completed twice when both item-done and terminal frames contain it.

Persist the text and signature for each completed message:

```php
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Result\Stream\Delta\MessageComplete;

$messages = [];
foreach ($result->asStream() as $delta) {
	if ($delta instanceof MessageComplete) {
		$text = $delta->getContent();
		$messages[] = [
			'output_index' => $delta->getOutputIndex(),
			'text' => $text->getText(),
			'signature' => $text->getSignature(),
		];
	}
}
```

This captures message items only, not the whole assistant turn. Also retain reasoning and tool slots as described below.

Rebuild each saved message as a separate `new Text($saved['text'], $saved['signature'])`. The default `ChatGPTContract` replays its native ID, phase, name, annotations, and content-part boundaries.

`MessageItem::toText($item)` and `MessageItem::fromText($text)` convert between a native message and its signed `Text`. If you edit the visible text, construct a new `Text` without the old signature. Replay rejects text that disagrees with the signed item.

## Preserve reasoning and tool order

The framework's `StreamResult::getAssistantMessage()` merges adjacent text and ignores the package's message deltas. Do not use it alone for exact ChatGPT replay.

Combine completed native messages with the framework listener's reasoning and tool slots in original output order. Preserve encrypted reasoning signatures and every tool identity. The paired internal ID `call_id|item_id` is split back into its wire fields when replayed.

A native reasoning item can contain several summary fragments. Its `output_index` counts the native item, not each framework content part. Group the fragments of one reasoning item together when positioning messages; do not use the index as a raw offset into a flat content array.

For example, preserve `[reasoning, tool call, reasoning, message]` even if the first reasoning item produces two summaries. Flattening the summaries or appending all tools at the end changes the request history.

## Change reasoning effort

Live subscription-route acceptance of `configuration_update` remains unverified. Serialization tests prove the request shape, not endpoint support or a prompt-cache hit.

For models that accept native configuration updates, insert `ReasoningConfiguration` at the transition's position in assistant content:

```php
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\ReasoningConfiguration;
use Symfony\AI\Platform\Message\AssistantMessage;
use Symfony\AI\Platform\Message\Content\Text;

$message = new AssistantMessage(
	new ReasoningConfiguration('high'),
	new Text('Continue with the new effort.'),
);
```

The contract emits:

```json
{"type":"configuration_update","reasoning":{"effort":"high"}}
```

To insert the control before an existing assistant message, use its metadata instead:

```php
$message->getMetadata()->add(ReasoningConfiguration::METADATA_KEY, 'high');
```

These are alternative placements. Do not apply both to the same transition. Adjacent controls are rejected.

Keep the initial request-level `reasoning.effort` and conversation `prompt_cache_key` unchanged. The control changes neither field. Allowed efforts are `none`, `minimal`, `low`, `medium`, `high`, `xhigh`, and `max`; the host must check which efforts the selected model supports. `max` is sent unchanged.

Validate a candidate control before recording it in your session's durable transition ledger. Replay rejects malformed controls and unknown effort values.

Your application owns transition anchors, persistence, model baselines, fork scope, and history changes after compaction. The package does not reset or relocate controls automatically, truncate history, or fall back to a request-level effort override.

## Customize serialization

`Factory::createProvider()` uses `ChatGPTContract` by default. Keep its message and control adaptations when adding your own normalizers:

```php
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Contract\ChatGPTContract;

$contract = ChatGPTContract::create($normalizers);
$provider = Factory::createProvider($oauth, $http, $catalog, contract: $contract);
```

See the [API reference](reference.md) for factory arguments and supported request items.
