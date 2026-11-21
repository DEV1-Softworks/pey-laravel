<?php

declare(strict_types=1);

namespace Dev1\Pey\Laravel\Tests\Console;

use Dev1\Pey\Dto\Request\CreateQrRequest;
use Dev1\Pey\Exception\AuthenticationException;
use Dev1\Pey\Exception\NetworkException;
use Dev1\Pey\Http\ApiError;
use Dev1\Pey\Idempotency\IdempotencyStore;
use Dev1\Pey\Idempotency\InMemoryIdempotencyStore;
use Dev1\Pey\Laravel\Clock\LaravelClock;
use Dev1\Pey\Laravel\Console\CheckCommand;
use Dev1\Pey\Laravel\Facades\Pey;
use Dev1\Pey\Laravel\Idempotency\CacheIdempotencyStore;
use Dev1\Pey\Laravel\PeyServiceProvider;
use Dev1\Pey\Laravel\Support\CoreFactory;
use Dev1\Pey\Laravel\Tests\Support\TestSecrets;
use Dev1\Pey\Laravel\Tests\TestCase;
use Dev1\Pey\Laravel\Webhooks\WebhookDeduplicator;
use Dev1\Pey\Testing\FakePeyClient;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(CheckCommand::class)]
#[UsesClass(CoreFactory::class)]
#[UsesClass(PeyServiceProvider::class)]
#[UsesClass(LaravelClock::class)]
#[UsesClass(Pey::class)]
#[UsesClass(CacheIdempotencyStore::class)]
#[UsesClass(WebhookDeduplicator::class)]
final class CheckCommandTest extends TestCase
{
    public function testItPassesAndReadsTheFirstPageOnce(): void
    {
        config()->set('pey.api_key', TestSecrets::API_KEY);
        self::pay(Pey::fake(), 1);

        [$exit, $output] = self::check();

        self::assertSame(0, $exit);
        self::assertStringContainsString('API key', $output);
        self::assertStringContainsString('configured', $output);
        self::assertStringContainsString('OK, HTTP 2xx, 1 transaction on the first page', $output);
        self::assertStringContainsString('not configured (no PEY_WEBHOOK_SECRET)', $output);
        self::assertStringContainsString('OK, cache store default', $output);
        self::assertStringContainsString('The Pey check passed.', $output);
        self::assertSame([[null]], array_map(static fn($call): array => $call->arguments, Pey::callsTo('transactions.listForApiKey')));
    }

    public function testItSaysWhenThereAreMorePages(): void
    {
        config()->set('pey.api_key', TestSecrets::API_KEY);
        self::pay(Pey::fake(), 81);

        [$exit, $output] = self::check();

        self::assertSame(0, $exit);
        self::assertStringContainsString('80 transactions on the first page, more pages available', $output);
    }

    public function testItFailsWithoutAnApiKeyAndSkipsTheApi(): void
    {
        Pey::fake();

        [$exit, $output] = self::check();

        self::assertSame(1, $exit);
        self::assertStringContainsString('Set PEY_API_KEY in your environment', $output);
        self::assertStringContainsString('SKIPPED', $output);
        self::assertStringContainsString('The Pey check failed.', $output);
        self::assertSame([], Pey::callsTo('transactions.listForApiKey'));
    }

    public function testItFailsWhenPeyRejectsTheKeyWithoutPrintingPeysMessage(): void
    {
        config()->set('pey.api_key', TestSecrets::API_KEY);
        Pey::fake()->failNext(AuthenticationException::fromResponse('GET', '/transaction/api-key', 401, new ApiError('authentication_error', 'From the response body: key rejected', 'req_check_1')));

        [$exit, $output] = self::check();

        self::assertSame(1, $exit);
        self::assertStringContainsString('AuthenticationException, HTTP 401, error type authentication_error, request id req_check_1', $output);
        self::assertStringNotContainsString('From the response body', $output);
    }

    public function testItFailsOnANetworkError(): void
    {
        config()->set('pey.api_key', TestSecrets::API_KEY);
        Pey::fake()->failNext(NetworkException::create('GET', '/transaction/api-key', false, 'ConnectException', 'cURL error 6: Could not resolve host'));

        [$exit, $output] = self::check();

        self::assertSame(1, $exit);
        self::assertStringContainsString('cURL error 6: Could not resolve host', $output);
    }

    public function testItReportsAnUnexpectedErrorByTypeOnly(): void
    {
        config()->set('pey.api_key', TestSecrets::API_KEY);
        Pey::fake()->failNext(new \RuntimeException('An unexpected detail.'));

        [$exit, $output] = self::check();

        self::assertSame(1, $exit);
        self::assertStringContainsString('RuntimeException', $output);
        self::assertStringNotContainsString('An unexpected detail.', $output);
    }

    public function testItChecksTheWebhookVerifierAndDedupStoreWhenASecretIsConfigured(): void
    {
        config()->set('pey.api_key', TestSecrets::API_KEY);
        config()->set('pey.webhooks.secrets', [TestSecrets::webhookSecret(), null]);
        Pey::fake();

        [$exit, $output] = self::check();

        self::assertSame(0, $exit);
        self::assertMatchesRegularExpression('/Webhook verifier\W+OK/', $output);
        self::assertMatchesRegularExpression('/Webhook dedup store\W+OK/', $output);
    }

    public function testItFailsOnAnInvalidWebhookSecretWithoutPrintingIt(): void
    {
        config()->set('pey.api_key', TestSecrets::API_KEY);
        config()->set('pey.webhooks.secrets', ['whsec_this is not base64']);
        Pey::fake();

        [$exit, $output] = self::check();

        self::assertSame(1, $exit);
        self::assertMatchesRegularExpression('/Webhook verifier\W+FAILED/', $output);
        self::assertStringNotContainsString('this is not base64', $output);
    }

    public function testItFailsOnWebhookSecretsOfTheWrongType(): void
    {
        config()->set('pey.api_key', TestSecrets::API_KEY);
        config()->set('pey.webhooks.secrets', 42);
        Pey::fake();

        [$exit, $output] = self::check();

        self::assertSame(1, $exit);
        // bulletList() drops the trailing period.
        self::assertStringContainsString('The pey.webhooks.secrets setting (PEY_WEBHOOK_SECRET) must be a list of strings', $output);
    }

    public function testItWarnsAboutADedupStoreThatCannotRecognizeDuplicates(): void
    {
        $this->application()->instance('env', 'local');
        config()->set('pey.api_key', TestSecrets::API_KEY);
        config()->set('pey.webhooks.secrets', [TestSecrets::webhookSecret()]);
        config()->set('pey.idempotency.store', 'shared');
        config()->set('cache.stores.shared', ['driver' => 'file', 'path' => sys_get_temp_dir() . '/pey-laravel-not-written']);
        Pey::fake();

        [$exit, $output] = self::check();

        self::assertSame(0, $exit);
        self::assertStringContainsString('uses the array cache driver', $output);
        self::assertStringContainsString('OK, cache store shared', $output);
    }

    public function testItFailsWhenTheIdempotencyStoreCannotLockAcrossProcesses(): void
    {
        $this->application()->instance('env', 'local');
        config()->set('pey.api_key', TestSecrets::API_KEY);
        Pey::fake();

        [$exit, $output] = self::check();

        self::assertSame(1, $exit);
        self::assertMatchesRegularExpression('/Idempotency store\W+FAILED/', $output);
        self::assertStringContainsString('Set PEY_IDEMPOTENCY_STORE', $output);
    }

    public function testItNamesAnApplicationIdempotencyStore(): void
    {
        config()->set('pey.api_key', TestSecrets::API_KEY);
        $this->application()->instance(IdempotencyStore::class, new InMemoryIdempotencyStore());
        Pey::fake();

        [, $output] = self::check();

        self::assertStringContainsString('OK, ' . InMemoryIdempotencyStore::class, $output);
    }

    public function testItNeverPrintsSecretsClabesOrResponseBodies(): void
    {
        config()->set('pey.api_key', TestSecrets::API_KEY);
        config()->set('pey.webhooks.secrets', [TestSecrets::webhookSecret(), TestSecrets::previousWebhookSecret()]);
        config()->set('pey.allowed_clabes', TestSecrets::CLABE . ',' . TestSecrets::OTHER_CLABE);
        $fake = Pey::fake();
        self::pay($fake, 2);
        $passed = self::check()[1];

        $fake->failNext(AuthenticationException::fromResponse('GET', '/transaction/api-key', 401, new ApiError('authentication_error', 'Body text ' . TestSecrets::API_KEY, null)));
        $failed = self::check()[1];

        foreach ([$passed, $failed] as $output) {
            foreach ([TestSecrets::API_KEY, TestSecrets::webhookSecret(), TestSecrets::previousWebhookSecret(), TestSecrets::CLABE, TestSecrets::OTHER_CLABE, 'Body text'] as $forbidden) {
                self::assertStringNotContainsString($forbidden, $output);
            }
        }
        self::assertStringContainsString('Allowlisted CLABEs', $passed);
    }

    /**
     * @return array{int, string}
     */
    private static function check(): array
    {
        $exit = Artisan::call('pey:check');

        return [$exit, Artisan::output()];
    }

    private static function pay(FakePeyClient $fake, int $payments): void
    {
        for ($i = 0; $i < $payments; ++$i) {
            $fake->simulateQrPayment(Pey::codi()->createQr(new CreateQrRequest('150.00'))->id);
        }
    }

    private function application(): Application
    {
        $app = $this->app;
        self::assertNotNull($app);

        return $app;
    }
}
