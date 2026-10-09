<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAIChatGPT\Tests\Support;

use Symfony\AI\Platform\Bridge\OpenResponses\ResponsesModel;
use Symfony\AI\Platform\Capability;
use Symfony\AI\Platform\ModelCatalog\AbstractModelCatalog;

final class TestModelCatalog extends AbstractModelCatalog
{
    public function __construct()
    {
        $this->models = ['test-model' => ['class' => ResponsesModel::class, 'capabilities' => [Capability::INPUT_MESSAGES, Capability::OUTPUT_TEXT, Capability::THINKING, Capability::TOOL_CALLING]]];
    }
}
