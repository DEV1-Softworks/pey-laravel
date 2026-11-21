<?php

declare(strict_types=1);

namespace Dev1\Pey\Laravel\Tests\Support;

use Dev1\Pey\Config;
use Dev1\Pey\Dto\Request\CreateQrRequest;
use Dev1\Pey\Dto\Request\RegisterBankAccountRequest;
use Dev1\Pey\Enum\Entity;
use Dev1\Pey\Exception\BankAccountNotAllowedException;
use Dev1\Pey\Exception\ConfigurationException;
use Dev1\Pey\Exception\SecretExposureException;
use Dev1\Pey\Exception\WebhookVerificationException;
use Dev1\Pey\Http\RetryPolicy;
use Dev1\Pey\Laravel\Clock\LaravelClock;
use Dev1\Pey\Laravel\Idempotency\CacheIdempotencyStore;
use Dev1\Pey\Laravel\PeyServiceProvider;
use Dev1\Pey\Laravel\Support\CoreFactory;
use Dev1\Pey\Laravel\Tests\TestCase;
use Dev1\Pey\Laravel\Webhooks\WebhookDeduplicator;
use Dev1\Pey\Testing\FakePeyClient;
use Dev1\Pey\Testing\WebhookSigner;
use Dev1\Pey\Version;
use Dev1\Pey\Webhooks\VerificationFailure;
use Illuminate\Cache\ApcStore;
use Illuminate\Cache\ApcWrapper;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository as RepositoryContract;
use Illuminate\Log\LogManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use Psr\Log\NullLogger;

#[CoversClass(CoreFactory::class)]
#[UsesClass(PeyServiceProvider::class)]
#[UsesClass(LaravelClock::class)]
#[UsesClass(WebhookDeduplicator::class)]
#[UsesClass(CacheIdempotencyStore::class)]
final class CoreFactoryTest extends TestCase
{
    /**
     * A verified body of a type the SDK does not model becomes an UnknownEvent.
     */
    private const WEBHOOK_BODY = '{"type":"pey_laravel.test"}';

    // ---------------------------------------------------------------- client

    #[DataProvider('provideUnsetValues')]
    public function testItRefusesAMissingApiKey(mixed $apiKey): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('The Pey API key is not configured. Set PEY_API_KEY in your environment (config key pey.api_key).');

        CoreFactory::config(self::repository(['api_key' => $apiKey]));
    }

    #[DataProvider('provideNonStrings')]
    public function testItRefusesANonStringApiKey(mixed $apiKey): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('The pey.api_key setting (PEY_API_KEY) must be a string.');

        CoreFactory::config(self::repository(['api_key' => $apiKey]));
    }

    public function testItBuildsTheSdkConfigWithTheSdkDefaults(): void
    {
        $config = CoreFactory::config(self::repository(['api_key' => TestSecrets::API_KEY]));

        self::assertSame(TestSecrets::API_KEY, $config->apiKey()->reveal());
        self::assertSame(Config::DEFAULT_BASE_URL, $config->baseUrl());
        self::assertSame(Config::DEFAULT_CONNECT_TIMEOUT, $config->connectTimeout());
        self::assertSame(Config::DEFAULT_TIMEOUT, $config->timeout());
        self::assertSame([], $config->allowedClabes());
        self::assertEquals(new RetryPolicy(), $config->retryPolicy());
    }

    public function testItMapsEveryClientSetting(): void
    {
        $config = CoreFactory::config(self::repository([
            'api_key' => TestSecrets::API_KEY,
            'base_url' => 'https://Pey.Example.test/v1/',
            'connect_timeout' => '2.5',
            'timeout' => 30,
            'retry' => ['max_retries' => '0'],
            'allowed_clabes' => ' ' . TestSecrets::CLABE . ' ,, ' . TestSecrets::OTHER_CLABE . ',' . TestSecrets::CLABE,
        ]));

        self::assertSame('https://pey.example.test/v1', $config->baseUrl());
        self::assertSame(2.5, $config->connectTimeout());
        self::assertSame(30.0, $config->timeout());
        self::assertSame(0, $config->retryPolicy()->maxRetries);
        self::assertSame([TestSecrets::CLABE, TestSecrets::OTHER_CLABE], $config->allowedClabes());
    }

    public function testItAcceptsAllowlistedClabesAsAList(): void
    {
        $config = CoreFactory::config(self::repository([
            'api_key' => TestSecrets::API_KEY,
            'allowed_clabes' => [TestSecrets::CLABE, ' ' . TestSecrets::OTHER_CLABE . ' ', ''],
        ]));

        self::assertSame([TestSecrets::CLABE, TestSecrets::OTHER_CLABE], $config->allowedClabes());
    }

    public function testItTreatsEmptyValuesAsUnset(): void
    {
        $config = CoreFactory::config(self::repository([
            'api_key' => TestSecrets::API_KEY,
            'base_url' => '',
            'connect_timeout' => '',
            'timeout' => null,
            'retry' => ['max_retries' => ''],
            'allowed_clabes' => null,
        ]));

        self::assertSame(Config::DEFAULT_BASE_URL, $config->baseUrl());
        self::assertSame(Config::DEFAULT_CONNECT_TIMEOUT, $config->connectTimeout());
        self::assertSame(Config::DEFAULT_TIMEOUT, $config->timeout());
        self::assertSame(RetryPolicy::DEFAULT_MAX_RETRIES, $config->retryPolicy()->maxRetries);
        self::assertSame([], $config->allowedClabes());
    }

    /**
     * @param array<string, mixed> $pey
     */
    #[DataProvider('provideClientSettingsOfTheWrongType')]
    public function testItRefusesClientSettingsOfTheWrongType(array $pey, string $message): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage($message);

        CoreFactory::config(self::repository(['api_key' => TestSecrets::API_KEY] + $pey));
    }

    /**
     * @param array<string, mixed> $pey
     */
    #[DataProvider('provideValuesTheSdkRejects')]
    public function testTheSdkStillValidatesEveryValue(array $pey, string $message): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage($message);

        CoreFactory::config(self::repository($pey + ['api_key' => TestSecrets::API_KEY]));
    }

    public function testAnInvalidAllowlistedClabeIsReportedMasked(): void
    {
        try {
            CoreFactory::config(self::repository(['api_key' => TestSecrets::API_KEY, 'allowed_clabes' => TestSecrets::INVALID_CLABE]));
            self::fail('Expected a ConfigurationException.');
        } catch (ConfigurationException $e) {
            self::assertStringContainsString(substr(TestSecrets::INVALID_CLABE, -4), $e->getMessage());
            self::assertStringNotContainsString(TestSecrets::INVALID_CLABE, $e->getMessage());
        }
    }

    public function testTheApiKeyNeverAppearsInExceptionsOrTheirTraces(): void
    {
        try {
            CoreFactory::config(self::repository(['api_key' => TestSecrets::API_KEY, 'base_url' => 'http://api.pey.mx']));
            self::fail('Expected a ConfigurationException.');
        } catch (ConfigurationException $e) {
            self::assertStringNotContainsString(TestSecrets::API_KEY, $e->getMessage());
            self::assertNoSecretInTrace($e, TestSecrets::API_KEY);
        }
    }

    public function testTheBuiltConfigNeverExposesTheApiKey(): void
    {
        $config = CoreFactory::config(self::repository(['api_key' => TestSecrets::API_KEY]));

        self::assertStringNotContainsString(TestSecrets::API_KEY, print_r($config, true));
        self::assertStringNotContainsString(TestSecrets::API_KEY, var_export($config, true));
        self::assertStringNotContainsString(TestSecrets::API_KEY, (string) json_encode($config));

        $this->expectException(SecretExposureException::class);
        serialize($config);
    }

    // -------------------------------------------------------------- webhooks

    /**
     * @param array<string, mixed> $webhooks
     */
    #[DataProvider('provideMissingWebhookSecrets')]
    public function testItRefusesMissingWebhookSecrets(array $webhooks): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('No Pey webhook secret is configured. Set PEY_WEBHOOK_SECRET in your environment (config key pey.webhooks.secrets).');

        CoreFactory::webhookVerifier(self::repository(['webhooks' => $webhooks]), new LaravelClock());
    }

    /**
     * @param array<string, mixed> $webhooks
     */
    #[DataProvider('provideWebhookSettingsOfTheWrongType')]
    public function testItRefusesWebhookSettingsOfTheWrongType(array $webhooks, string $message): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage($message);

        CoreFactory::webhookVerifier(self::repository(['webhooks' => $webhooks]), new LaravelClock());
    }

    public function testTheSdkValidatesTheWebhookSecretWithoutEchoingIt(): void
    {
        $notASecret = 'whsec_not base64 at all';

        try {
            CoreFactory::webhookVerifier(self::repository(['webhooks' => ['secrets' => [$notASecret]]]), new LaravelClock());
            self::fail('Expected a ConfigurationException.');
        } catch (ConfigurationException $e) {
            self::assertStringContainsString('The webhook secret must be the whsec_', $e->getMessage());
            self::assertStringNotContainsString('not base64', $e->getMessage());
            self::assertNoSecretInTrace($e, 'not base64');
        }
    }

    public function testItAcceptsWebhooksSignedWithTheCurrentOrThePreviousSecret(): void
    {
        $this->freezeTime();
        $verifier = CoreFactory::webhookVerifier(self::repository(['webhooks' => [
            'secrets' => [TestSecrets::webhookSecret(), TestSecrets::previousWebhookSecret()],
        ]]), new LaravelClock());

        foreach ([TestSecrets::webhookSecret(), TestSecrets::previousWebhookSecret()] as $secret) {
            $signed = WebhookSigner::sign($secret, self::WEBHOOK_BODY, 'msg_rotation', Date::now()->getTimestamp());

            self::assertSame('msg_rotation', $verifier->verify($signed->body, $signed->headers)->id);
        }
    }

    public function testItAcceptsASingleSecretString(): void
    {
        $this->freezeTime();
        $verifier = CoreFactory::webhookVerifier(self::repository(['webhooks' => ['secrets' => TestSecrets::webhookSecret()]]), new LaravelClock());
        $signed = WebhookSigner::sign(TestSecrets::webhookSecret(), self::WEBHOOK_BODY, 'msg_single', Date::now()->getTimestamp());

        self::assertSame('msg_single', $verifier->verify($signed->body, $signed->headers)->id);
    }

    public function testItRejectsWebhooksSignedWithAnyOtherSecret(): void
    {
        $this->freezeTime();
        $verifier = CoreFactory::webhookVerifier(self::repository(['webhooks' => [
            'secrets' => [TestSecrets::webhookSecret(), TestSecrets::previousWebhookSecret()],
        ]]), new LaravelClock());
        $signed = WebhookSigner::sign(TestSecrets::unknownWebhookSecret(), self::WEBHOOK_BODY, 'msg_forged', Date::now()->getTimestamp());

        try {
            $verifier->verify($signed->body, $signed->headers);
            self::fail('Expected a WebhookVerificationException.');
        } catch (WebhookVerificationException $e) {
            self::assertSame(VerificationFailure::InvalidSignature, $e->failure());
        }
    }

    public function testTheToleranceComesFromTheConfigAndTimeFromLaravel(): void
    {
        $this->freezeTime();
        $verifier = CoreFactory::webhookVerifier(self::repository(['webhooks' => [
            'secrets' => [TestSecrets::webhookSecret()],
            'tolerance' => '60',
        ]]), new LaravelClock());
        $signed = WebhookSigner::sign(TestSecrets::webhookSecret(), self::WEBHOOK_BODY, 'msg_tolerance', Date::now()->getTimestamp());

        $this->travel(60)->seconds();
        self::assertSame('msg_tolerance', $verifier->verify($signed->body, $signed->headers)->id);

        $this->travel(1)->seconds();
        try {
            $verifier->verify($signed->body, $signed->headers);
            self::fail('Expected a WebhookVerificationException.');
        } catch (WebhookVerificationException $e) {
            self::assertSame(VerificationFailure::TimestampTooOld, $e->failure());
        }
    }

    // ------------------------------------------------------------------ fake

    public function testItBuildsAFakeClientWithoutAnyPeySetting(): void
    {
        $fake = CoreFactory::fakeClient(self::repository(), new LaravelClock());

        // No allowlist: bank account registration is blocked, as in production.
        $this->expectException(BankAccountNotAllowedException::class);
        self::registerBankAccount($fake, TestSecrets::CLABE);
    }

    public function testTheFakeIsGuardedByTheConfiguredAllowlist(): void
    {
        $fake = CoreFactory::fakeClient(self::repository(['allowed_clabes' => ' ' . TestSecrets::CLABE . ', ']), new LaravelClock());

        self::registerBankAccount($fake, TestSecrets::CLABE);
        self::assertCount(1, $fake->callsTo('bankAccounts.register'));

        $this->expectException(BankAccountNotAllowedException::class);
        self::registerBankAccount($fake, TestSecrets::OTHER_CLABE);
    }

    public function testAGivenAllowlistReplacesTheConfiguredOne(): void
    {
        $fake = CoreFactory::fakeClient(
            self::repository(['allowed_clabes' => TestSecrets::CLABE]),
            new LaravelClock(),
            [TestSecrets::OTHER_CLABE],
        );

        self::registerBankAccount($fake, TestSecrets::OTHER_CLABE);

        $this->expectException(BankAccountNotAllowedException::class);
        self::registerBankAccount($fake, TestSecrets::CLABE);
    }

    /**
     * @param list<string>|null $given
     */
    #[DataProvider('provideInvalidFakeAllowlists')]
    public function testTheFakeReportsAnInvalidClabeMasked(string $configured, ?array $given): void
    {
        try {
            CoreFactory::fakeClient(self::repository(['allowed_clabes' => $configured]), new LaravelClock(), $given);
            self::fail('Expected a ConfigurationException.');
        } catch (ConfigurationException $e) {
            self::assertStringContainsString(substr(TestSecrets::INVALID_CLABE, -4), $e->getMessage());
            self::assertStringNotContainsString(TestSecrets::INVALID_CLABE, $e->getMessage());
        }
    }

    public function testTheFakeRefusesAnAllowlistOfTheWrongType(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('The pey.allowed_clabes setting (PEY_ALLOWED_CLABES) must be a comma-separated string or a list of strings.');

        CoreFactory::fakeClient(self::repository(['allowed_clabes' => 42]), new LaravelClock());
    }

    public function testTheFakeUsesTheGivenClock(): void
    {
        $this->travelTo(new \DateTimeImmutable('2026-03-04 05:06:07 UTC'));
        $fake = CoreFactory::fakeClient(self::repository(), new LaravelClock());

        $qr = $fake->codi()->createQr(new CreateQrRequest('150.00', concept: 'Order 1'));
        $payment = $fake->simulateQrPayment($qr->id);

        self::assertSame('2026-03-04T05:06:07+00:00', $payment->transaction->dateCreated->format(\DATE_ATOM));
    }

    // ---------------------------------------------------- webhook dedup store

    public function testTheDedupStoreIsTheDefaultCacheStore(): void
    {
        $deduplicator = CoreFactory::webhookDeduplicator(self::repository(), $this->cacheFactory());

        self::assertTrue($deduplicator->claim('msg_default'));
        self::assertFalse($deduplicator->claim('msg_default'));
        self::assertTrue($this->cacheFactory()->store()->has(self::dedupKey('msg_default')));
    }

    public function testTheDedupStoreIsTheConfiguredStore(): void
    {
        config()->set('cache.stores.webhooks', ['driver' => 'array']);
        $repository = self::repository(['webhooks' => ['dedup_store' => 'webhooks']], ['stores' => ['webhooks' => ['driver' => 'array']]]);

        CoreFactory::webhookDeduplicator($repository, $this->cacheFactory())->claim('msg_named');

        self::assertTrue($this->cacheFactory()->store('webhooks')->has(self::dedupKey('msg_named')));
        self::assertFalse($this->cacheFactory()->store()->has(self::dedupKey('msg_named')));
    }

    public function testEmptyDedupSettingsMeanTheDefaults(): void
    {
        $this->freezeTime();
        $deduplicator = CoreFactory::webhookDeduplicator(self::repository(['webhooks' => ['dedup_store' => '', 'dedup_ttl' => '']]), $this->cacheFactory());
        $deduplicator->claim('msg_defaults');

        $this->travel(WebhookDeduplicator::DEFAULT_TTL_SECONDS - 1)->seconds();
        self::assertFalse($deduplicator->claim('msg_defaults'));

        $this->travel(1)->seconds();
        self::assertTrue($deduplicator->claim('msg_defaults'));
    }

    public function testItRefusesAnUndefinedDedupStore(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('The pey.webhooks.dedup_store setting (PEY_WEBHOOK_DEDUP_STORE) names a cache store that config/cache.php does not define.');

        CoreFactory::webhookDeduplicator(self::repository(['webhooks' => ['dedup_store' => 'missing']]), $this->cacheFactory());
    }

    public function testItRefusesADedupStoreOfTheWrongType(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('The pey.webhooks.dedup_store setting (PEY_WEBHOOK_DEDUP_STORE) must be a string.');

        CoreFactory::webhookDeduplicator(self::repository(['webhooks' => ['dedup_store' => 42]]), $this->cacheFactory());
    }

    /**
     * @param array<string, mixed> $pey
     * @param array<string, mixed> $cache
     */
    #[DataProvider('provideStoresThatStoreNothing')]
    public function testItRefusesADedupStoreThatStoresNothing(array $pey, array $cache): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('The Pey webhook dedup store uses the null cache driver, which stores nothing, so every webhook would be ignored as a duplicate.');

        CoreFactory::webhookDeduplicator(self::repository($pey, $cache), $this->cacheFactory());
    }

    #[DataProvider('provideInvalidDedupTtls')]
    public function testItRefusesAnInvalidDedupTtl(mixed $ttl, string $message): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage($message);

        CoreFactory::webhookDeduplicator(self::repository(['webhooks' => ['dedup_ttl' => $ttl]]), $this->cacheFactory());
    }

    public function testTheDedupTtlMustCoverTheReplayWindow(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('The pey.webhooks.dedup_ttl setting (PEY_WEBHOOK_DEDUP_TTL) must be at least twice pey.webhooks.tolerance, so a replayed webhook is still recognized as a duplicate.');

        CoreFactory::webhookDeduplicator(self::repository(['webhooks' => ['dedup_ttl' => 599]]), $this->cacheFactory());
    }

    public function testADedupTtlOfTwiceTheToleranceIsAccepted(): void
    {
        $this->freezeTime();
        $deduplicator = CoreFactory::webhookDeduplicator(self::repository(['webhooks' => ['dedup_ttl' => '60', 'tolerance' => 30]]), $this->cacheFactory());
        $deduplicator->claim('msg_short');

        $this->travel(60)->seconds();

        self::assertTrue($deduplicator->claim('msg_short'));
    }

    #[DataProvider('provideDedupStoresThatCannotRecognizeDuplicates')]
    public function testItWarnsAboutADedupStoreThatCannotRecognizeDuplicates(string $driver, bool $production, bool $testing, string $problem): void
    {
        $warning = CoreFactory::dedupStoreWarning(self::webhooksOn($driver), $production, $testing);

        self::assertIsString($warning);
        self::assertStringStartsWith('The Pey webhook dedup store ' . $problem, $warning);
        self::assertStringEndsWith('Set PEY_WEBHOOK_DEDUP_STORE (config key pey.webhooks.dedup_store) to a shared cache store such as redis or database.', $warning);
        self::assertStringNotContainsString(TestSecrets::webhookSecret(), $warning);
    }

    #[DataProvider('provideDedupStoresThatRecognizeDuplicates')]
    public function testItDoesNotWarnAboutADedupStoreThatRecognizesDuplicates(string $driver, bool $production, bool $testing): void
    {
        self::assertNull(CoreFactory::dedupStoreWarning(self::webhooksOn($driver), $production, $testing));
    }

    public function testItDoesNotWarnWhenNoWebhookSecretIsConfigured(): void
    {
        self::assertNull(CoreFactory::dedupStoreWarning(self::repository(), true, false));
    }

    public function testTheWarningIsAboutTheConfiguredDedupStore(): void
    {
        $repository = self::repository(
            ['webhooks' => ['secrets' => [TestSecrets::webhookSecret(), null], 'dedup_store' => 'local']],
            ['default' => 'shared', 'stores' => ['shared' => ['driver' => 'redis'], 'local' => ['driver' => 'array']]],
        );

        self::assertStringContainsString('array cache driver', (string) CoreFactory::dedupStoreWarning($repository, true, false));
    }

    /**
     * @param array<string, mixed> $pey
     * @param array<string, mixed> $cache
     */
    #[DataProvider('provideUnreadableDedupSettings')]
    public function testTheWarningNeverThrows(array $pey, array $cache): void
    {
        self::assertNull(CoreFactory::dedupStoreWarning(self::repository($pey, $cache), true, false));
    }

    // ----------------------------------------------------- idempotency store

    public function testTheIdempotencyStoreIsTheDefaultCacheStoreInTests(): void
    {
        $store = CoreFactory::idempotencyStore(self::repository(), $this->cacheFactory(), true, false, new NullLogger());

        self::assertTrue($store->acquire('k', 'started', 60));
        self::assertTrue($this->cacheFactory()->store()->has('pey:idempotency:k'));
    }

    public function testTheIdempotencyStoreIsTheConfiguredStore(): void
    {
        config()->set('cache.stores.charges', ['driver' => 'array']);
        $repository = self::repository(['idempotency' => ['store' => 'charges']], ['stores' => ['charges' => ['driver' => 'array']]]);

        CoreFactory::idempotencyStore($repository, $this->cacheFactory(), true, false, new NullLogger())->acquire('k', 'started', 60);

        self::assertTrue($this->cacheFactory()->store('charges')->has('pey:idempotency:k'));
        self::assertFalse($this->cacheFactory()->store()->has('pey:idempotency:k'));
    }

    public function testItRefusesAnUndefinedIdempotencyStore(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('The pey.idempotency.store setting (PEY_IDEMPOTENCY_STORE) names a cache store that config/cache.php does not define.');

        CoreFactory::idempotencyStore(self::repository(['idempotency' => ['store' => 'missing']]), $this->cacheFactory(), true, false, new NullLogger());
    }

    public function testItRefusesAnIdempotencyStoreOfTheWrongType(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('The pey.idempotency.store setting (PEY_IDEMPOTENCY_STORE) must be a string.');

        CoreFactory::idempotencyStore(self::repository(['idempotency' => ['store' => ['redis']]]), $this->cacheFactory(), true, false, new NullLogger());
    }

    #[DataProvider('provideUnsafeIdempotencyStores')]
    public function testItRefusesAnIdempotencyStoreThatCannotLockAcrossProcesses(string $driver, bool $testing, string $problem): void
    {
        // Cache::extend() binds the callback to the manager, so it cannot be static.
        Cache::extend('pey_no_locks', fn(): CacheRepository => new CacheRepository(new ApcStore(new ApcWrapper())));
        config()->set('cache.stores.unsafe', ['driver' => $driver]);
        $repository = self::repository(['idempotency' => ['store' => 'unsafe']], ['stores' => ['unsafe' => ['driver' => $driver]]]);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('The Pey idempotency store ' . $problem . '. Set PEY_IDEMPOTENCY_STORE (config key pey.idempotency.store) to a shared cache store with atomic locks, such as redis or database.');

        CoreFactory::idempotencyStore($repository, $this->cacheFactory(), $testing, false, new NullLogger());
    }

    #[DataProvider('provideFileStoreEnvironments')]
    public function testItWarnsAboutAFileIdempotencyStoreInProductionOnly(bool $production, int $warnings): void
    {
        config()->set('cache.stores.charges', ['driver' => 'file', 'path' => sys_get_temp_dir() . '/pey-laravel-not-written']);
        $repository = self::repository(['idempotency' => ['store' => 'charges']], ['stores' => ['charges' => ['driver' => 'file']]]);
        $logger = new RecordingLogger();

        CoreFactory::idempotencyStore($repository, $this->cacheFactory(), false, $production, $logger);

        self::assertCount($warnings, $logger->records);
        foreach ($logger->records as $record) {
            self::assertSame('warning', $record['level']);
            self::assertStringContainsString('uses the file cache driver, whose locks hold on one server only', $record['message']);
            self::assertStringContainsString('Set PEY_IDEMPOTENCY_STORE (config key pey.idempotency.store)', $record['message']);
        }
    }

    public function testItDoesNotWarnAboutOtherIdempotencyStoresInProduction(): void
    {
        $logger = new RecordingLogger();

        CoreFactory::idempotencyStore(self::repository(), $this->cacheFactory(), true, true, $logger);

        self::assertSame([], $logger->records);
    }

    // ---------------------------------------------------------------- logger

    public function testItUsesTheDefaultLogChannelWhenNoneIsConfigured(): void
    {
        $log = $this->logManager();

        self::assertSame($log->channel(), CoreFactory::logger(self::repository(), $log));
    }

    public function testItUsesTheConfiguredLogChannel(): void
    {
        $log = $this->logManager();

        self::assertSame($log->channel('single'), CoreFactory::logger(self::repository(['log_channel' => 'single']), $log));
    }

    public function testItRefusesALogChannelOfTheWrongType(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('The pey.log_channel setting (PEY_LOG_CHANNEL) must be a string.');

        CoreFactory::logger(self::repository(['log_channel' => ['single']]), $this->logManager());
    }

    // ----------------------------------------------------------------- about

    public function testAboutReportsMissingSettingsWithoutThrowing(): void
    {
        self::assertSame([
            'SDK version' => Version::VERSION,
            'Base URL' => Config::DEFAULT_BASE_URL,
            'API key' => 'missing',
            'Allowlisted CLABEs' => '0',
            'Webhook secrets' => '0',
        ], CoreFactory::about(self::repository()));
    }

    public function testAboutReportsConfiguredSettingsWithoutShowingThem(): void
    {
        $about = CoreFactory::about(self::repository([
            'api_key' => TestSecrets::API_KEY,
            'allowed_clabes' => TestSecrets::CLABE . ',' . TestSecrets::OTHER_CLABE . ',' . TestSecrets::CLABE,
            'webhooks' => ['secrets' => [TestSecrets::webhookSecret(), TestSecrets::previousWebhookSecret()]],
        ]));

        self::assertSame('configured', $about['API key'] ?? null);
        self::assertSame('2', $about['Allowlisted CLABEs'] ?? null);
        self::assertSame('2', $about['Webhook secrets'] ?? null);

        $shown = implode("\n", $about);
        foreach ([TestSecrets::API_KEY, TestSecrets::webhookSecret(), TestSecrets::previousWebhookSecret(), TestSecrets::CLABE, TestSecrets::OTHER_CLABE] as $hidden) {
            self::assertStringNotContainsString($hidden, $shown);
        }
    }

    public function testAboutNeverShowsCredentialsOrQueriesOfTheBaseUrl(): void
    {
        $about = CoreFactory::about(self::repository(['base_url' => 'https://user:hunter2@Pey.Example.test:8443/v1/?token=abc#top']));

        self::assertSame('https://pey.example.test:8443/v1', $about['Base URL'] ?? null);
    }

    /**
     * @param array<string, mixed> $pey
     */
    #[DataProvider('provideSettingsAboutShowsAsInvalid')]
    public function testAboutShowsUnreadableSettingsAsInvalid(array $pey, string $field): void
    {
        self::assertSame('invalid', CoreFactory::about(self::repository($pey))[$field] ?? null);
    }

    // ------------------------------------------------------------- providers

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function provideUnsetValues(): iterable
    {
        yield 'null' => [null];
        yield 'empty string' => [''];
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function provideNonStrings(): iterable
    {
        yield 'integer' => [123];
        yield 'boolean' => [true];
        yield 'list' => [['pey_test_not_a_real_value']];
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideClientSettingsOfTheWrongType(): iterable
    {
        yield 'base URL' => [['base_url' => 123], 'The pey.base_url setting (PEY_BASE_URL) must be a string.'];
        yield 'connect timeout' => [['connect_timeout' => 'fast'], 'The pey.connect_timeout setting (PEY_CONNECT_TIMEOUT) must be a number of seconds.'];
        yield 'timeout' => [['timeout' => [15]], 'The pey.timeout setting (PEY_TIMEOUT) must be a number of seconds.'];
        yield 'decimal retries' => [['retry' => ['max_retries' => '1.5']], 'The pey.retry.max_retries setting (PEY_MAX_RETRIES) must be an integer.'];
        yield 'float retries' => [['retry' => ['max_retries' => 2.0]], 'The pey.retry.max_retries setting (PEY_MAX_RETRIES) must be an integer.'];
        yield 'retries with a line break' => [['retry' => ['max_retries' => "2\n"]], 'The pey.retry.max_retries setting (PEY_MAX_RETRIES) must be an integer.'];
        yield 'CLABEs as a number' => [['allowed_clabes' => 123], 'The pey.allowed_clabes setting (PEY_ALLOWED_CLABES) must be a comma-separated string or a list of strings.'];
        yield 'CLABE list with a number' => [['allowed_clabes' => [123]], 'The pey.allowed_clabes setting (PEY_ALLOWED_CLABES) must be a comma-separated string or a list of strings.'];
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideValuesTheSdkRejects(): iterable
    {
        yield 'plain HTTP' => [['base_url' => 'http://api.pey.mx'], 'The base URL must use HTTPS.'];
        yield 'zero timeout' => [['timeout' => '0'], 'timeout must be greater than 0'];
        yield 'connect timeout above timeout' => [['connect_timeout' => 20, 'timeout' => 10], 'connectTimeout must not be greater than timeout.'];
        yield 'negative retries' => [['retry' => ['max_retries' => '-1']], 'maxRetries must be between 0 and 10.'];
        yield 'too many retries' => [['retry' => ['max_retries' => 11]], 'maxRetries must be between 0 and 10.'];
        yield 'API key with spaces' => [['api_key' => 'pey test'], 'The Pey API key must be a non-empty string of visible ASCII characters'];
    }

    /**
     * @return iterable<string, array{bool, int}>
     */
    public static function provideFileStoreEnvironments(): iterable
    {
        yield 'production' => [true, 1];
        yield 'elsewhere' => [false, 0];
    }

    /**
     * @return iterable<string, array{string, bool, string}>
     */
    public static function provideUnsafeIdempotencyStores(): iterable
    {
        yield 'array outside tests' => ['array', false, 'uses the array cache driver, which keeps records and locks in the memory of one process, so two workers could both send the same charge'];
        yield 'null in tests' => ['null', true, 'uses the null cache driver, which stores nothing, so every charge could be sent twice'];
        yield 'null outside tests' => ['null', false, 'uses the null cache driver, which stores nothing, so every charge could be sent twice'];
        yield 'no locks' => ['pey_no_locks', true, 'does not support atomic locks, so two processes could both send the same charge'];
    }

    /**
     * @return iterable<string, array{array<string, mixed>, array<string, mixed>}>
     */
    public static function provideStoresThatStoreNothing(): iterable
    {
        yield 'configured store' => [['webhooks' => ['dedup_store' => 'nothing']], ['stores' => ['nothing' => ['driver' => 'null']]]];
        yield 'default store' => [[], ['default' => 'nothing', 'stores' => ['nothing' => ['driver' => 'null']]]];
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function provideInvalidDedupTtls(): iterable
    {
        yield 'zero' => [0, 'The pey.webhooks.dedup_ttl setting (PEY_WEBHOOK_DEDUP_TTL) must be a positive number of seconds.'];
        yield 'negative' => ['-5', 'The pey.webhooks.dedup_ttl setting (PEY_WEBHOOK_DEDUP_TTL) must be a positive number of seconds.'];
        yield 'not a number' => ['three days', 'The pey.webhooks.dedup_ttl setting (PEY_WEBHOOK_DEDUP_TTL) must be an integer.'];
        yield 'fractional' => [1.5, 'The pey.webhooks.dedup_ttl setting (PEY_WEBHOOK_DEDUP_TTL) must be an integer.'];
    }

    /**
     * @return iterable<string, array{string, bool, bool, string}>
     */
    public static function provideDedupStoresThatCannotRecognizeDuplicates(): iterable
    {
        yield 'array outside tests' => ['array', false, false, 'uses the array cache driver'];
        yield 'array in production' => ['array', true, false, 'uses the array cache driver'];
        yield 'file in production' => ['file', true, false, 'uses the file cache driver'];
        yield 'null in tests' => ['null', false, true, 'uses the null cache driver'];
        yield 'null in production' => ['null', true, false, 'uses the null cache driver'];
    }

    /**
     * @return iterable<string, array{string, bool, bool}>
     */
    public static function provideDedupStoresThatRecognizeDuplicates(): iterable
    {
        yield 'array in tests' => ['array', false, true];
        yield 'file outside production' => ['file', false, false];
        yield 'file in tests' => ['file', false, true];
        yield 'redis in production' => ['redis', true, false];
        yield 'database in production' => ['database', true, false];
        yield 'memcached in production' => ['memcached', true, false];
    }

    /**
     * @return iterable<string, array{array<string, mixed>, array<string, mixed>}>
     */
    public static function provideUnreadableDedupSettings(): iterable
    {
        $array = ['stores' => ['array' => ['driver' => 'array']]];

        yield 'secrets of the wrong type' => [['webhooks' => ['secrets' => 42]], $array];
        yield 'invalid secret entry' => [['webhooks' => ['secrets' => [42]]], $array];
        yield 'dedup store of the wrong type' => [['webhooks' => ['secrets' => [TestSecrets::webhookSecret()], 'dedup_store' => 42]], $array];
        yield 'default store of the wrong type' => [['webhooks' => ['secrets' => [TestSecrets::webhookSecret()]]], ['default' => 42]];
    }

    /**
     * @return iterable<string, array{string, list<string>|null}>
     */
    public static function provideInvalidFakeAllowlists(): iterable
    {
        yield 'configured' => [TestSecrets::CLABE . ',' . TestSecrets::INVALID_CLABE, null];
        yield 'given' => [TestSecrets::CLABE, [TestSecrets::INVALID_CLABE]];
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function provideMissingWebhookSecrets(): iterable
    {
        yield 'unset' => [['secrets' => [null, null]]];
        yield 'empty strings' => [['secrets' => ['', '']]];
        yield 'null' => [['secrets' => null]];
        yield 'empty string' => [['secrets' => '']];
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideWebhookSettingsOfTheWrongType(): iterable
    {
        yield 'secrets as a number' => [['secrets' => 123], 'The pey.webhooks.secrets setting (PEY_WEBHOOK_SECRET) must be a list of strings.'];
        yield 'secret list with a number' => [['secrets' => [123]], 'The pey.webhooks.secrets setting (PEY_WEBHOOK_SECRET) must be a list of strings.'];
        yield 'tolerance' => [['secrets' => [TestSecrets::webhookSecret()], 'tolerance' => 'soon'], 'The pey.webhooks.tolerance setting (PEY_WEBHOOK_TOLERANCE) must be an integer.'];
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideSettingsAboutShowsAsInvalid(): iterable
    {
        yield 'base URL of the wrong type' => [['base_url' => 123], 'Base URL'];
        yield 'base URL that is not a URL' => [['base_url' => 'not a url'], 'Base URL'];
        yield 'base URL with spaces' => [['base_url' => 'https://api.pey.mx/a b'], 'Base URL'];
        yield 'API key of the wrong type' => [['api_key' => 123], 'API key'];
        yield 'CLABEs of the wrong type' => [['allowed_clabes' => 123], 'Allowlisted CLABEs'];
        yield 'secrets of the wrong type' => [['webhooks' => ['secrets' => 123]], 'Webhook secrets'];
    }

    // --------------------------------------------------------------- helpers

    /**
     * The shipped config/pey.php (all env variables unset), with overrides,
     * and a cache config whose default store uses the array driver.
     *
     * @param array<string, mixed> $pey
     * @param array<string, mixed> $cache
     */
    private static function repository(array $pey = [], array $cache = []): RepositoryContract
    {
        $defaults = require \dirname(__DIR__, 2) . '/config/pey.php';
        self::assertIsArray($defaults);

        return new Repository([
            'pey' => array_replace_recursive($defaults, $pey),
            'cache' => array_replace_recursive(['default' => 'array', 'stores' => ['array' => ['driver' => 'array']]], $cache),
        ]);
    }

    /**
     * The repository with a webhook secret, so webhooks are in use, and one
     * cache store of the given driver as the default.
     */
    private static function webhooksOn(string $driver): RepositoryContract
    {
        return self::repository(
            ['webhooks' => ['secrets' => [TestSecrets::webhookSecret(), null]]],
            ['default' => 'webhooks', 'stores' => ['webhooks' => ['driver' => $driver]]],
        );
    }

    private static function dedupKey(string $webhookId): string
    {
        return 'pey:webhook:' . hash('sha256', $webhookId);
    }

    private function cacheFactory(): CacheFactory
    {
        $app = $this->app;
        self::assertNotNull($app);

        return $app->make(CacheFactory::class);
    }

    private static function registerBankAccount(FakePeyClient $fake, string $clabe): void
    {
        $fake->bankAccounts()->register(new RegisterBankAccountRequest('Main', 'Pey Laravel Test', $clabe, Entity::Business));
    }

    private static function assertNoSecretInTrace(\Throwable $e, string $secret): void
    {
        self::assertStringNotContainsString(substr($secret, 0, 15), $e->getTraceAsString());

        foreach ($e->getTrace() as $frame) {
            foreach ($frame['args'] ?? [] as $argument) {
                self::assertNotInstanceOf(RepositoryContract::class, $argument, 'The config repository must be a sensitive parameter.');
                if (\is_string($argument)) {
                    self::assertStringNotContainsString($secret, $argument);
                }
            }
        }
    }

    private function logManager(): LogManager
    {
        $app = $this->app;
        self::assertNotNull($app);

        return $app->make(LogManager::class);
    }
}
