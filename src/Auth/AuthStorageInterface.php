<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAIChatGPT\Auth;

interface AuthStorageInterface
{
    public function installationId(): string;

    public function load(): ?AuthRecord;

    /**
     * Re-read, mutate and persist the registration under one cross-worker lock.
     *
     * @param callable(?AuthRecord): AuthRecord $update
     */
    public function update(callable $update): AuthRecord;
}
