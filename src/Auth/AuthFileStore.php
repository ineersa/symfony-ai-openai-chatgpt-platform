<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAIChatGPT\Auth;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Uid\Uuid;

final class AuthFileStore implements AuthStorageInterface
{
    private readonly string $path;

    public function __construct(string $path, private readonly LockFactory $lockFactory, private readonly Filesystem $filesystem = new Filesystem())
    {
        if ('' === $path) {
            throw new \InvalidArgumentException('An auth storage path is required.');
        }
        $cwd = getcwd();
        if (false === $cwd) {
            throw new \RuntimeException('Cannot resolve the auth storage directory.');
        }
        $this->path = Path::makeAbsolute($path, $cwd);
    }

    public function installationId(): string
    {
        return $this->locked(function (): string {
            $data = $this->readState();
            $id = $data['chatgpt']['host_id'] ?? null;
            if (null !== $id) {
                if (!\is_string($id) || !str_starts_with($id, 'urn:uuid:') || !Uuid::isValid(substr($id, 9))) {
                    throw new AuthException('Invalid ChatGPT installation identity.');
                }

                return $id;
            }
            $id = 'urn:uuid:'.Uuid::v4()->toRfc4122();
            $data['chatgpt']['host_id'] = $id;
            $this->writeState($data);

            return $id;
        });
    }

    /** @phpstan-impure Reads external state that another worker can rotate. */
    public function load(): ?AuthRecord
    {
        return $this->locked(fn (): ?AuthRecord => $this->record($this->readState()));
    }

    public function update(callable $update): AuthRecord
    {
        return $this->locked(function () use ($update): AuthRecord {
            $data = $this->readState();
            $previous = $this->record($data);
            $record = $update($previous);
            if ($record->toArray() === $previous?->toArray()) {
                return $record;
            }
            $data['chatgpt']['credentials'] = $record->toArray();
            $this->writeState($data);

            return $record;
        });
    }

    /**
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     */
    private function locked(callable $operation): mixed
    {
        $lock = $this->lockFactory->createLock('chatgpt.auth.'.hash('sha256', $this->path), 120.0);
        $lock->acquire(true);
        try {
            return $operation();
        } finally {
            $lock->release();
        }
    }

    /** @return array<string, mixed> */
    private function readState(): array
    {
        if (is_link($this->path)) {
            throw new AuthException('Auth storage must not be a symbolic link.');
        }
        if (!file_exists($this->path)) {
            return [];
        }
        $this->filesystem->chmod($this->path, 0600);
        try {
            $content = $this->filesystem->readFile($this->path);
            $data = json_decode($content, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new AuthException('Auth storage contains invalid JSON.');
        }
        if (!str_starts_with(ltrim($content), '{') || !\is_array($data) || (isset($data['chatgpt']) && !\is_array($data['chatgpt']))) {
            throw new AuthException('Auth storage must contain an object.');
        }

        return $data;
    }

    /** @param array<string, mixed> $data */
    private function record(array $data): ?AuthRecord
    {
        $record = $data['chatgpt']['credentials'] ?? null;
        if (null === $record) {
            return null;
        }
        if (!\is_array($record)) {
            throw new AuthException('Invalid saved ChatGPT registration.');
        }

        return AuthRecord::fromArray($record);
    }

    /** @param array<string, mixed> $data */
    private function writeState(array $data): void
    {
        $this->filesystem->mkdir(\dirname($this->path), 0700);
        // dumpFile inherits a target's mode. A 0600 temporary target avoids a world-readable rename window.
        $temporary = $this->filesystem->tempnam(\dirname($this->path), '.chatgpt-');
        try {
            $this->filesystem->chmod($temporary, 0600);
            $this->filesystem->dumpFile($temporary, json_encode($data, \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT)."\n");
            $this->filesystem->rename($temporary, $this->path, true);
        } finally {
            $this->filesystem->remove($temporary);
        }
    }
}
