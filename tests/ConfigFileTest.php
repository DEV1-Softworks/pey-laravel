<?php

declare(strict_types=1);

namespace Dev1\Pey\Laravel\Tests;

use Dev1\Pey\Config;
use Dev1\Pey\Http\RetryPolicy;
use Dev1\Pey\Laravel\Webhooks\WebhookDeduplicator;
use Dev1\Pey\Webhooks\WebhookVerifier;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;

/**
 * config/pey.php must survive `php artisan config:cache` and keep the SDK's
 * defaults.
 */
#[CoversNothing]
final class ConfigFileTest extends PHPUnitTestCase
{
    private const PATH = __DIR__ . '/../config/pey.php';

    public function testItHoldsOnlyScalarsAndArrays(): void
    {
        self::assertOnlyScalarsAndArrays(self::load(), 'pey');
    }

    public function testItSurvivesTheConfigCacheRoundTrip(): void
    {
        $config = self::load();

        // config:cache writes the merged configuration with var_export() and
        // the application then requires that file.
        $cached = tempnam(sys_get_temp_dir(), 'pey-config-');
        self::assertIsString($cached);

        try {
            file_put_contents($cached, '<?php return ' . var_export($config, true) . ';' . \PHP_EOL);

            self::assertSame($config, require $cached);
        } finally {
            unlink($cached);
        }
    }

    public function testItDefaultsToTheSdkDefaults(): void
    {
        $config = self::load();
        $webhooks = $config['webhooks'] ?? null;
        $retry = $config['retry'] ?? null;
        self::assertIsArray($webhooks);
        self::assertIsArray($retry);

        foreach (['api_key', 'log_channel'] as $key) {
            self::assertArrayHasKey($key, $config);
            self::assertNull($config[$key]);
        }
        self::assertArrayHasKey('dedup_store', $webhooks);
        self::assertNull($webhooks['dedup_store']);

        self::assertSame(Config::DEFAULT_BASE_URL, $config['base_url'] ?? null);
        self::assertSame(Config::DEFAULT_CONNECT_TIMEOUT, $config['connect_timeout'] ?? null);
        self::assertSame(Config::DEFAULT_TIMEOUT, $config['timeout'] ?? null);
        self::assertSame(RetryPolicy::DEFAULT_MAX_RETRIES, $retry['max_retries'] ?? null);
        self::assertSame('', $config['allowed_clabes'] ?? null);
        self::assertSame([null, null], $webhooks['secrets'] ?? null);
        self::assertSame(WebhookVerifier::DEFAULT_TOLERANCE_SECONDS, $webhooks['tolerance'] ?? null);
        self::assertSame(WebhookDeduplicator::DEFAULT_TTL_SECONDS, $webhooks['dedup_ttl'] ?? null);
    }

    public function testItReadsEveryValueFromTheEnvironment(): void
    {
        $source = file_get_contents(self::PATH);
        self::assertIsString($source);

        foreach ([
            'PEY_API_KEY', 'PEY_BASE_URL', 'PEY_CONNECT_TIMEOUT', 'PEY_TIMEOUT', 'PEY_MAX_RETRIES',
            'PEY_ALLOWED_CLABES', 'PEY_WEBHOOK_SECRET', 'PEY_WEBHOOK_SECRET_PREVIOUS', 'PEY_WEBHOOK_TOLERANCE',
            'PEY_WEBHOOK_DEDUP_STORE', 'PEY_WEBHOOK_DEDUP_TTL', 'PEY_IDEMPOTENCY_STORE', 'PEY_LOG_CHANNEL',
        ] as $variable) {
            self::assertStringContainsString("env('{$variable}'", $source);
        }
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function load(): array
    {
        $config = require self::PATH;
        self::assertIsArray($config);

        return $config;
    }

    private static function assertOnlyScalarsAndArrays(mixed $value, string $key): void
    {
        if (\is_array($value)) {
            foreach ($value as $name => $item) {
                self::assertOnlyScalarsAndArrays($item, $key . '.' . $name);
            }

            return;
        }

        self::assertTrue($value === null || \is_scalar($value), $key . ' must be a scalar, an array or null, not ' . get_debug_type($value) . '.');
    }
}
