<?php

declare(strict_types=1);

namespace Dev1\Pey\Laravel\Tests\Idempotency;

use Dev1\Pey\Laravel\Idempotency\CacheIdempotencyStore;
use Dev1\Pey\Laravel\PeyServiceProvider;
use Dev1\Pey\Laravel\Support\CoreFactory;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * The file driver, in a temporary directory: its locks hold across processes
 * on one server.
 */
#[CoversClass(CacheIdempotencyStore::class)]
#[UsesClass(PeyServiceProvider::class)]
#[UsesClass(CoreFactory::class)]
final class FileCacheIdempotencyStoreTest extends CacheIdempotencyStoreContractTestCase
{
    private string $directory = '';

    public function testRecordsAreFilesInTheConfiguredDirectory(): void
    {
        $this->newProcessStore()->acquire('k', 'started', 60);

        self::assertNotSame([], (new Filesystem())->allFiles($this->directory));
    }

    protected function storeName(): string
    {
        return 'pey_file';
    }

    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        $this->directory = sys_get_temp_dir() . '/pey-laravel-idempotency-' . bin2hex(random_bytes(6));
        $app->make('config')->set('cache.stores.pey_file', [
            'driver' => 'file',
            'path' => $this->directory,
            'lock_path' => $this->directory . '/locks',
        ]);

        $this->beforeApplicationDestroyed(function (): void {
            (new Filesystem())->deleteDirectory($this->directory);
        });
    }
}
