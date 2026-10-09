<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAIChatGPT\Tests\Contract;

use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Contract\ChatGPTContract;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\MessageItem;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\ReasoningConfiguration;
use Symfony\AI\Platform\Bridge\OpenResponses\ResponsesModel;
use Symfony\AI\Platform\Message\AssistantMessage;
use Symfony\AI\Platform\Message\Content\Text;
use Symfony\AI\Platform\Message\Content\Thinking;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Result\ToolCall;

final class AssistantMessageNormalizerTest extends TestCase
{
    public function testNativeMessagesStayDistinctWhileFrameworkHandlesReasoningAndTools(): void
    {
        $one = ['type' => 'message', 'id' => 'msg_one', 'role' => 'assistant', 'phase' => 'commentary', 'content' => [['type' => 'output_text', 'text' => 'One']]];
        $two = ['type' => 'message', 'id' => 'msg_two', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => 'Two'], ['type' => 'output_text', 'text' => 'Three']]];
        $reasoning = ['type' => 'reasoning', 'id' => 'rs_one', 'summary' => [], 'encrypted_content' => 'ciphertext'];
        $message = new AssistantMessage(
            MessageItem::toText($one), MessageItem::toText($two),
            new Thinking('', json_encode($reasoning, \JSON_THROW_ON_ERROR)),
            new ReasoningConfiguration('high'),
            new ToolCall('call_one|fc_one', 'lookup', ['path' => 'file']),
            new Text('Old'), new Text(' behavior'),
        );
        $payload = ChatGPTContract::create()->createRequestPayload(new ResponsesModel('test-model'), new MessageBag($message));
        self::assertIsArray($payload);
        self::assertSame([$one, $two, $reasoning, (new ReasoningConfiguration('high'))->toArray(),
            ['arguments' => '{"path":"file"}', 'call_id' => 'call_one|fc_one', 'name' => 'lookup', 'type' => 'function_call'],
            ['role' => 'assistant', 'type' => 'message', 'content' => 'Old behavior'],
        ], $payload['input']);
    }

    public function testTransitionMetadataAnchorsNativeControlBeforeAssistantContent(): void
    {
        $message = new AssistantMessage(new Text('After transition'));
        $message->getMetadata()->add(ReasoningConfiguration::METADATA_KEY, 'high');
        $payload = ChatGPTContract::create()->createRequestPayload(new ResponsesModel('test-model'), new MessageBag($message));
        self::assertIsArray($payload);
        self::assertSame([
            ['type' => 'configuration_update', 'reasoning' => ['effort' => 'high']],
            ['role' => 'assistant', 'type' => 'message', 'content' => 'After transition'],
        ], $payload['input']);
    }
}
