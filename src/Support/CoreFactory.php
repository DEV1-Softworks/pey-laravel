<?php

declare(strict_types=1);

namespace Dev1\Pey\Laravel\Support;

use Dev1\Pey\Config;
use Dev1\Pey\Exception\ConfigurationException;
use Dev1\Pey\Http\RetryPolicy;
use Dev1\Pey\Laravel\Webhooks\WebhookDeduplicator;
use Dev1\Pey\Security\Secret;
use Dev1\Pey\Testing\FakePeyClient;
use Dev1\Pey\Version;
use Dev1\Pey\Webhooks\WebhookVerifier;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Log\LogManager;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Builds the SDK's objects from Laravel's `pey` configuration.
 *
 * It only reads values and narrows their types. Every rule (HTTPS, timeout
 * bounds, CLABE check digits, the webhook secret format) is enforced by the
 * SDK's own constructors. The container calls it lazily, so a missing or
 * invalid value surfaces as a ConfigurationException when an object is first
 * resolved, never while the application boots. Messages name the setting and
 * its env variable, never the value.
 *
 * Secrets are wrapped in the SDK's Secret as soon as they are read. The
 * configuration repository holds every credential of the application, so
 * parameters that receive it are marked sensitive.
 *
 * @internal
 */
final class CoreFactory
{
    private const ENV = [
        'pey.api_key' => 'PEY_API_KEY',
        'pey.base_url' => 'PEY_BASE_URL',
        'pey.connect_timeout' => 'PEY_CONNECT_TIMEOUT',
        'pey.timeout' => 'PEY_TIMEOUT',
        'pey.retry.max_retries' => 'PEY_MAX_RETRIES',
        'pey.allowed_clabes' => 'PEY_ALLOWED_CLABES',
        'pey.webhooks.secrets' => 'PEY_WEBHOOK_SECRET',
        'pey.webhooks.tolerance' => 'PEY_WEBHOOK_TOLERANCE',
        'pey.webhooks.dedup_store' => 'PEY_WEBHOOK_DEDUP_STORE',
        'pey.webhooks.dedup_ttl' => 'PEY_WEBHOOK_DEDUP_TTL',
        'pey.log_channel' => 'PEY_LOG_CHANNEL',
    ];

    /**
     * @throws ConfigurationException
     */
    public static function config(#[\SensitiveParameter] Repository $config): Config
    {
        $apiKey = self::optionalSecret($config, 'pey.api_key')
            ?? throw ConfigurationException::because('The Pey API key is not configured. Set PEY_API_KEY in your environment (config key pey.api_key).');

        return new Config(
            apiKey: $apiKey,
            baseUrl: self::optionalString($config, 'pey.base_url') ?? Config::DEFAULT_BASE_URL,
            connectTimeout: self::seconds($config, 'pey.connect_timeout', Config::DEFAULT_CONNECT_TIMEOUT),
            timeout: self::seconds($config, 'pey.timeout', Config::DEFAULT_TIMEOUT),
            allowedClabes: self::allowedClabes($config),
            retryPolicy: new RetryPolicy(self::integer($config, 'pey.retry.max_retries', RetryPolicy::DEFAULT_MAX_RETRIES)),
        );
    }

    /**
     * @throws ConfigurationException
     */
    public static function webhookVerifier(#[\SensitiveParameter] Repository $config, ClockInterface $clock): WebhookVerifier
    {
        $secrets = self::webhookSecrets($config);
        if ($secrets === []) {
            throw ConfigurationException::because('No Pey webhook secret is configured. Set PEY_WEBHOOK_SECRET in your environment (config key pey.webhooks.secrets).');
        }

        return new WebhookVerifier(
            $secrets,
            self::integer($config, 'pey.webhooks.tolerance', WebhookVerifier::DEFAULT_TOLERANCE_SECONDS),
            $clock,
        );
    }

    /**
     * The store that remembers webhook ids: `pey.webhooks.dedup_store`, or
     * the default cache store.
     *
     * @throws ConfigurationException when the store is undefined or uses the null driver, or the TTL is invalid
     */
    public static function webhookDeduplicator(#[\SensitiveParameter] Repository $config, CacheFactory $cache): WebhookDeduplicator
    {
        $name = self::optionalString($config, 'pey.webhooks.dedup_store');
        if ($name !== null && !\is_array($config->get('cache.stores.' . $name))) {
            throw ConfigurationException::because('The pey.webhooks.dedup_store setting (PEY_WEBHOOK_DEDUP_STORE) names a cache store that config/cache.php does not define.');
        }

        if (self::dedupDriver($config, $name) === 'null') {
            throw ConfigurationException::because('The Pey webhook dedup store uses the null cache driver, which stores nothing, so every webhook would be ignored as a duplicate. Set PEY_WEBHOOK_DEDUP_STORE (config key pey.webhooks.dedup_store) to a shared cache store.');
        }

        $ttl = self::integer($config, 'pey.webhooks.dedup_ttl', WebhookDeduplicator::DEFAULT_TTL_SECONDS);
        if ($ttl < 1) {
            throw self::invalid('pey.webhooks.dedup_ttl', 'a positive number of seconds');
        }

        // A replay is accepted while its timestamp is within the tolerance of
        // now, in either direction, so its id must be remembered that long.
        if ($ttl < 2 * self::integer($config, 'pey.webhooks.tolerance', WebhookVerifier::DEFAULT_TOLERANCE_SECONDS)) {
            throw self::invalid('pey.webhooks.dedup_ttl', 'at least twice pey.webhooks.tolerance, so a replayed webhook is still recognized as a duplicate');
        }

        return new WebhookDeduplicator($cache->store($name), $ttl);
    }

    /**
     * Why the webhook dedup store cannot recognize a repeated delivery, or
     * null. The provider logs it at boot, so it reads config only: it never
     * builds the store and never throws. Without a webhook secret, webhooks
     * are not in use and there is nothing to report.
     *
     * - `array` keeps ids in one process's memory. It is fine in tests only.
     * - `file` is local to one server, which production rarely is.
     * - `null` stores nothing: every webhook would be ignored as a duplicate.
     */
    public static function dedupStoreWarning(#[\SensitiveParameter] Repository $config, bool $production, bool $testing): ?string
    {
        try {
            if (self::webhookSecrets($config) === []) {
                return null;
            }

            $driver = self::dedupDriver($config, self::optionalString($config, 'pey.webhooks.dedup_store'));
        } catch (ConfigurationException) {
            // Reported when a webhook arrives and the store is resolved.
            return null;
        }

        $problem = match (true) {
            $driver === 'array' && !$testing => 'uses the array cache driver, which keeps ids in the memory of one process, so a webhook Pey delivers twice can be dispatched twice',
            $driver === 'file' && $production => 'uses the file cache driver, which is local to one server, so a webhook Pey delivers twice can be dispatched twice behind a load balancer',
            $driver === 'null' => 'uses the null cache driver, which stores nothing, so every webhook would be ignored as a duplicate',
            default => null,
        };

        return $problem === null
            ? null
            : 'The Pey webhook dedup store ' . $problem . '. Set PEY_WEBHOOK_DEDUP_STORE (config key pey.webhooks.dedup_store) to a shared cache store such as redis or database.';
    }

    /**
     * The SDK's FakePeyClient, guarded by the configured CLABE allowlist
     * (unless a list is given) and driven by the given clock. It never reads
     * the API key or the webhook secrets, so it works without any Pey setting.
     *
     * @param list<string>|null $allowedClabes null for the configured `pey.allowed_clabes`
     *
     * @throws ConfigurationException when a setting has the wrong type or a CLABE is invalid
     */
    public static function fakeClient(#[\SensitiveParameter] Repository $config, ClockInterface $clock, ?array $allowedClabes = null): FakePeyClient
    {
        return new FakePeyClient($allowedClabes ?? self::allowedClabes($config), $clock);
    }

    /**
     * The configured log channel, or the default channel.
     *
     * @throws ConfigurationException
     */
    public static function logger(#[\SensitiveParameter] Repository $config, LogManager $log): LoggerInterface
    {
        return $log->channel(self::optionalString($config, 'pey.log_channel'));
    }

    /**
     * What `php artisan about` shows. It never throws and never shows a
     * secret or a CLABE: only whether the key is set, and counts.
     *
     * @return array<string, string>
     */
    public static function about(#[\SensitiveParameter] Repository $config): array
    {
        return [
            'SDK version' => Version::VERSION,
            'Base URL' => self::describe(static fn(): string => self::displayBaseUrl($config)),
            'API key' => self::describe(static fn(): string => self::optionalSecret($config, 'pey.api_key') === null ? 'missing' : 'configured'),
            'Allowlisted CLABEs' => self::describe(static fn(): string => (string) \count(array_unique(self::allowedClabes($config)))),
            'Webhook secrets' => self::describe(static fn(): string => (string) \count(self::webhookSecrets($config))),
        ];
    }

    /**
     * @throws ConfigurationException
     */
    private static function optionalSecret(#[\SensitiveParameter] Repository $config, string $key): ?Secret
    {
        $value = $config->get($key);

        return match (true) {
            $value === null, $value === '' => null,
            \is_string($value) => new Secret($value),
            default => throw self::invalid($key, 'a string'),
        };
    }

    /**
     * @throws ConfigurationException
     */
    private static function optionalString(#[\SensitiveParameter] Repository $config, string $key): ?string
    {
        $value = $config->get($key);

        return match (true) {
            $value === null, $value === '' => null,
            \is_string($value) => $value,
            default => throw self::invalid($key, 'a string'),
        };
    }

    /**
     * @throws ConfigurationException
     */
    private static function seconds(#[\SensitiveParameter] Repository $config, string $key, float $default): float
    {
        $value = $config->get($key);

        return match (true) {
            $value === null, $value === '' => $default,
            \is_int($value), \is_float($value) => (float) $value,
            \is_string($value) && is_numeric($value) => (float) $value,
            default => throw self::invalid($key, 'a number of seconds'),
        };
    }

    /**
     * @throws ConfigurationException
     */
    private static function integer(#[\SensitiveParameter] Repository $config, string $key, int $default): int
    {
        $value = $config->get($key);

        return match (true) {
            $value === null, $value === '' => $default,
            \is_int($value) => $value,
            \is_string($value) && preg_match('/^-?\d{1,9}$/D', $value) === 1 => (int) $value,
            default => throw self::invalid($key, 'an integer'),
        };
    }

    /**
     * A comma-separated string (from the environment) or a list of strings
     * (in a published config file). Surrounding whitespace and empty entries
     * are dropped; the SDK validates each CLABE.
     *
     * @return list<string>
     *
     * @throws ConfigurationException
     */
    private static function allowedClabes(#[\SensitiveParameter] Repository $config): array
    {
        $value = $config->get('pey.allowed_clabes');
        $entries = match (true) {
            $value === null => [],
            \is_string($value) => explode(',', $value),
            \is_array($value) => $value,
            default => throw self::invalid('pey.allowed_clabes', 'a comma-separated string or a list of strings'),
        };

        $clabes = [];
        foreach ($entries as $entry) {
            if (!\is_string($entry)) {
                throw self::invalid('pey.allowed_clabes', 'a comma-separated string or a list of strings');
            }

            $entry = trim($entry);
            if ($entry !== '') {
                $clabes[] = $entry;
            }
        }

        return $clabes;
    }

    /**
     * The current secret and, during a rotation, the previous one. Unset
     * entries are skipped; the SDK validates the `whsec_` format.
     *
     * @return list<Secret>
     *
     * @throws ConfigurationException
     */
    private static function webhookSecrets(#[\SensitiveParameter] Repository $config): array
    {
        $value = $config->get('pey.webhooks.secrets');
        $entries = match (true) {
            $value === null => [],
            \is_string($value) => [$value],
            \is_array($value) => $value,
            default => throw self::invalid('pey.webhooks.secrets', 'a list of strings'),
        };

        $secrets = [];
        foreach ($entries as $entry) {
            if ($entry === null || $entry === '') {
                continue;
            }

            if (!\is_string($entry)) {
                throw self::invalid('pey.webhooks.secrets', 'a list of strings');
            }

            $secrets[] = new Secret($entry);
        }

        return $secrets;
    }

    /**
     * The driver of the named cache store, or of the default store.
     */
    private static function dedupDriver(#[\SensitiveParameter] Repository $config, ?string $store): mixed
    {
        $store ??= $config->get('cache.default');

        return \is_string($store) ? $config->get('cache.stores.' . $store . '.driver') : null;
    }

    /**
     * The base URL without credentials, query or fragment, which the SDK
     * rejects anyway and which must never be printed.
     *
     * @throws ConfigurationException
     */
    private static function displayBaseUrl(#[\SensitiveParameter] Repository $config): string
    {
        $url = self::optionalString($config, 'pey.base_url') ?? Config::DEFAULT_BASE_URL;
        $parts = parse_url($url);

        if (!\is_array($parts) || !isset($parts['scheme'], $parts['host']) || preg_match('/[\x00-\x20\x7F]/', $url) === 1) {
            return 'invalid';
        }

        return strtolower($parts['scheme']) . '://' . strtolower($parts['host'])
            . (isset($parts['port']) ? ':' . $parts['port'] : '')
            . rtrim($parts['path'] ?? '', '/');
    }

    /**
     * @param \Closure(): string $value
     */
    private static function describe(\Closure $value): string
    {
        try {
            return $value();
        } catch (ConfigurationException) {
            return 'invalid';
        }
    }

    private static function invalid(string $key, string $expected): ConfigurationException
    {
        return ConfigurationException::because(\sprintf('The %s setting (%s) must be %s.', $key, self::ENV[$key] ?? 'env', $expected));
    }
}
