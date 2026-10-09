<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAIChatGPT\Tests\Auth;

use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Auth\AuthException;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Auth\AuthFileStore;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Auth\AuthRecord;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Tests\Support\AuthFixture;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Tests\Support\IsolatedTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

final class AuthFileStoreTest extends IsolatedTestCase
{
    public function testProtectsAtomicStorageAndPreservesOtherRecordsAndInstallation(): void
    {
        $path = $this->directory.'/auth.json';
        (new Filesystem())->dumpFile($path, '{"grok":{"value":"unrelated"}}');
        $store = new AuthFileStore($path, new LockFactory(new FlockStore($this->directory)));
        $hostId = $store->installationId();
        self::assertStringStartsWith('urn:uuid:', $hostId);
        self::assertNull($store->load());
        $record = AuthFixture::record();
        $store->update(static fn (): AuthRecord => $record);
        $store->update(static function (?AuthRecord $current): AuthRecord {
            self::assertNotNull($current);

            return $current->disconnected();
        });
        $data = json_decode((new Filesystem())->readFile($path), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(['value' => 'unrelated'], $data['grok']);
        self::assertSame($hostId, $store->installationId());
        self::assertSame('issued-client', $store->load()?->clientId);
        self::assertNull($store->load()?->access);
        clearstatcache(true, $path);
        self::assertSame(0600, fileperms($path) & 0777);
        self::assertSame([], glob($this->directory.'/.chatgpt-*'));
    }

    public function testUpdateOwnsRealLockAndReReadsAnotherStoresRotation(): void
    {
        $path = $this->directory.'/auth.json';
        $factory = new LockFactory(new FlockStore($this->directory));
        $first = new AuthFileStore($path, $factory);
        $second = new AuthFileStore($path, $factory);
        $first->update(function (?AuthRecord $current) use ($path, $factory): AuthRecord {
            self::assertNull($current);
            $contender = $factory->createLock('chatgpt.auth.'.hash('sha256', $path), 120.0);
            self::assertFalse($contender->acquire());

            return AuthFixture::record(1);
        });
        $second->update(static function (?AuthRecord $current): AuthRecord {
            self::assertNotNull($current);
            self::assertSame(1, $current->expires);

            return AuthFixture::record(2000000000);
        });
        self::assertSame(2000000000, $first->load()?->expires);
    }

    public function testMalformedStateFailsWithoutClobberingIt(): void
    {
        $path = $this->directory.'/auth.json';
        (new Filesystem())->dumpFile($path, 'broken-state');
        $store = new AuthFileStore($path, new LockFactory(new FlockStore($this->directory)));
        $this->expectException(AuthException::class);
        $store->installationId();
    }
}
