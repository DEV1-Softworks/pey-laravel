<?php

declare(strict_types=1);

namespace Dev1\Pey\Laravel\Tests\Support;

use Dev1\Pey\Config;
use Dev1\Pey\Exception\ConfigurationException;
use Dev1\Pey\Exception\SecretExposureException;
use Dev1\Pey\Exception\WebhookVerificationException;
use Dev1\Pey\Http\RetryPolicy;
use Dev1\Pey\Laravel\Clock\LaravelClock;
use Dev1\Pey\Laravel\PeyServiceProvider;
use Dev1\Pey\Laravel\Support\CoreFactory;
use Dev1\Pey\Laravel\Tests\TestCase;
use Dev1\Pey\Testing\WebhookSigner;
use Dev1\Pey\Version;
use Dev1\Pey\Webhooks\VerificationFailure;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Config\Repository as RepositoryContract;
use Illuminate\Log\LogManager;
use Illuminate\Support\Facades\Date;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(CoreFactory::class)]
#[UsesClass(PeyServiceProvider::class)]
#[UsesClass(LaravelClock::class)]
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
     * The shipped config/pey.php (all env variables unset), with overrides.
     *
     * @param array<string, mixed> $pey
     */
    private static function repository(array $pey = []): RepositoryContract
    {
        $defaults = require \dirname(__DIR__, 2) . '/config/pey.php';
        self::assertIsArray($defaults);

        return new Repository(['pey' => array_replace_recursive($defaults, $pey)]);
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
