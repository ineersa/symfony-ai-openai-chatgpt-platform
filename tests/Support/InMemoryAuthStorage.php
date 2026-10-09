<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAIChatGPT\Tests\Support;

use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Auth\AuthRecord;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Auth\AuthStorageInterface;

final class InMemoryAuthStorage implements AuthStorageInterface
{
    public function __construct(public ?AuthRecord $record = null)
    {
    }

    public function installationId(): string
    {
        return 'urn:uuid:00000000-0000-4000-8000-000000000001';
    }

    public function load(): ?AuthRecord
    {
        return $this->record;
    }

    public function update(callable $update): AuthRecord
    {
        return $this->record = $update($this->record);
    }
}
