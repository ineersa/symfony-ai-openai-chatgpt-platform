<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAIChatGPT\Tests\Support;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Uid\Uuid;

/** Package-local isolation: the host's TestDirectoryIsolation is not a dependency of this standalone library. */
abstract class IsolatedTestCase extends TestCase
{
    protected string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = \dirname(__DIR__, 2).'/var/tmp/test-'.Uuid::v4()->toRfc4122();
        (new Filesystem())->mkdir($this->directory, 0700);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
        parent::tearDown();
    }
}
