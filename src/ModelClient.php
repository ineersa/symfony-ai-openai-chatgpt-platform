<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAIChatGPT;

use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Auth\OAuthService;
use Symfony\AI\Platform\Bridge\OpenResponses\ModelClient as OpenResponsesModelClient;
use Symfony\AI\Platform\Capability;
use Symfony\AI\Platform\Exception\InvalidArgumentException;
use Symfony\AI\Platform\JsonBodyEncodingTrait;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\Result\RawHttpResult;
use Symfony\Component\HttpClient\Response\AsyncResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class ModelClient extends OpenResponsesModelClient
{
    use JsonBodyEncodingTrait;

    public const float IDLE_TIMEOUT = 300.0;
    private const array ALLOWED_FIELDS = ['model', 'input', 'instructions', 'reasoning', 'text', 'tool_choice', 'parallel_tool_calls', 'prompt_cache_key', 'include', 'service_tier'];

    public function __construct(private readonly HttpClientInterface $client, private readonly OAuthService $auth)
    {
        parent::__construct($client, 'https://api.openai.com');
    }

    public function request(Model $model, array|string $payload, array $options = []): RawHttpResult
    {
        if (\is_string($payload)) {
            throw new InvalidArgumentException('ChatGPT requests require a full input array.');
        }
        foreach (array_keys($payload) as $key) {
            if (!\is_string($key)) {
                throw new InvalidArgumentException('ChatGPT request payload must be an object.');
            }
        }
        /** @var array<string, mixed> $payload */
        $body = $this->encodeJsonBody($this->createBody($model, $payload, $options));
        // AsyncResponse makes the framework stream parser usable without EventSourceHttpClient's reconnect policy.
        $response = new AsyncResponse($this->client, 'POST', 'https://api.openai.com/v1/responses', [
            'headers' => ['Content-Type' => 'application/json', 'Accept' => 'text/event-stream'],
            'auth_bearer' => $this->auth->accessToken(),
            'body' => $body,
            'timeout' => self::IDLE_TIMEOUT,
            'max_duration' => 0.0,
            'max_redirects' => 0,
            'buffer' => false,
        ]);

        return new RawHttpResult($response, new ResponsesStream());
    }

    protected function createBody(Model $model, array $payload, array $options): array
    {
        $body = parent::createBody($model, $payload, $options);
        $input = $body['input'] ?? null;
        if (!\is_array($input) || !array_is_list($input)) {
            throw new InvalidArgumentException('ChatGPT input must be an array of history items.');
        }
        foreach ($input as &$item) {
            if (!\is_array($item)) {
                throw new InvalidArgumentException('ChatGPT history items must be objects.');
            }
            if ('system' === ($item['role'] ?? null)) {
                $item['role'] = 'developer';
            }
            if (!\in_array($item['type'] ?? null, [null, 'message', 'reasoning', 'function_call', 'function_call_output', 'additional_tools'], true)) {
                throw new InvalidArgumentException('ChatGPT history contains an unsupported output or hosted tool item.');
            }
            if (\in_array($item['type'] ?? null, ['function_call', 'function_call_output'], true) && \is_string($item['call_id'] ?? null) && str_contains($item['call_id'], '|')) {
                [$item['call_id'], $itemId] = explode('|', $item['call_id'], 2);
                if ('function_call' === $item['type']) {
                    $item['id'] = $itemId;
                }
            }
            if ('additional_tools' === ($item['type'] ?? null)) {
                $item['tools'] = $this->normalizeTools($item['tools'] ?? []);
            }
        }
        unset($item);
        $tools = $body['tools'] ?? [];
        if ([] !== $tools) {
            $tools = $this->normalizeTools($tools);
            // additional_tools exposes local functions without introducing a namespace into local dispatch names.
            array_unshift($input, ['type' => 'additional_tools', 'role' => 'developer', 'tools' => $tools]);
        }
        $body = array_intersect_key($body, array_flip(self::ALLOWED_FIELDS));
        $body['input'] = $input;
        $body['model'] = $model->getName();
        $body['store'] = false;
        $body['stream'] = true;
        if ($model->supports(Capability::THINKING)) {
            $include = $body['include'] ?? [];
            if (!\is_array($include)) {
                throw new InvalidArgumentException('ChatGPT include must be an array.');
            }
            $body['include'] = array_values(array_unique([...$include, 'reasoning.encrypted_content']));
        }

        return $body;
    }

    /** @return list<array<string, mixed>> */
    private function normalizeTools(mixed $tools): array
    {
        if (!\is_array($tools) || !array_is_list($tools)) {
            throw new InvalidArgumentException('ChatGPT tools must be a list.');
        }
        foreach ($tools as &$tool) {
            if (!\is_array($tool) || 'function' !== ($tool['type'] ?? null) || !\is_string($tool['name'] ?? null)) {
                throw new InvalidArgumentException('This ChatGPT bridge supports locally executed function tools only.');
            }
            // Responses defaults to strict schemas; Hatfield/Pi tools may intentionally have optional fields.
            $tool['strict'] ??= false;
            $tool['parameters'] ??= ['type' => 'object', 'properties' => new \stdClass()];
            if ([] === ($tool['parameters']['properties'] ?? null)) {
                $tool['parameters']['properties'] = new \stdClass();
            }
        }
        unset($tool);

        return $tools;
    }
}
