<?php

declare(strict_types=1);

namespace Dev1\Pey\Laravel\Tests\Console;

use Dev1\Pey\Laravel\Clock\LaravelClock;
use Dev1\Pey\Laravel\Console\SignWebhookCommand;
use Dev1\Pey\Laravel\Console\WebhookSamples;
use Dev1\Pey\Laravel\Events\BankAccountUpdated;
use Dev1\Pey\Laravel\Events\CodiPushNotificationPaymentReceived;
use Dev1\Pey\Laravel\Events\CodiQrPaymentReceived;
use Dev1\Pey\Laravel\Events\PaymentLinkStatusUpdated;
use Dev1\Pey\Laravel\Events\PeyWebhookReceived;
use Dev1\Pey\Laravel\Http\Controllers\PeyWebhookController;
use Dev1\Pey\Laravel\Http\Middleware\VerifyPeyWebhook;
use Dev1\Pey\Laravel\PeyServiceProvider;
use Dev1\Pey\Laravel\Support\CoreFactory;
use Dev1\Pey\Laravel\Tests\Support\TestSecrets;
use Dev1\Pey\Laravel\Tests\TestCase;
use Dev1\Pey\Laravel\Webhooks\WebhookDeduplicator;
use Dev1\Pey\Laravel\Webhooks\WebhookEvents;
use Dev1\Pey\Validation\Clabe;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(SignWebhookCommand::class)]
#[CoversClass(WebhookSamples::class)]
#[UsesClass(CoreFactory::class)]
#[UsesClass(PeyServiceProvider::class)]
#[UsesClass(LaravelClock::class)]
#[UsesClass(VerifyPeyWebhook::class)]
#[UsesClass(PeyWebhookController::class)]
#[UsesClass(WebhookDeduplicator::class)]
#[UsesClass(WebhookEvents::class)]
#[UsesClass(PeyWebhookReceived::class)]
#[UsesClass(CodiQrPaymentReceived::class)]
#[UsesClass(CodiPushNotificationPaymentReceived::class)]
#[UsesClass(PaymentLinkStatusUpdated::class)]
#[UsesClass(BankAccountUpdated::class)]
final class SignWebhookCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // A fixed time in the past: signing with time() instead of the
        // Laravel clock would fail the timestamp assertion and the tolerance.
        $this->travelTo(new \DateTimeImmutable('2026-03-04 05:06:07 UTC'));
        config()->set('pey.webhooks.secrets', [TestSecrets::webhookSecret(), TestSecrets::previousWebhookSecret()]);
    }

    /**
     * @param class-string $event
     */
    #[DataProvider('provideTypes')]
    public function testItPrintsASampleThatTheWebhookRouteAccepts(string $type, string $event): void
    {
        Event::fake([$event]);

        [$exit, $output] = self::sign(['event' => $type, '--id' => 'msg_cli']);
        [$headers, $body] = self::parse($output);

        self::assertSame(0, $exit);
        self::assertSame('msg_cli', $headers['webhook-id'] ?? null);
        self::assertSame((string) Date::now()->getTimestamp(), $headers['webhook-timestamp'] ?? null);
        self::assertStringContainsString('"type":"' . $type . '"', $body);

        $server = $this->transformHeadersToServerVars($headers + ['Content-Type' => 'application/json']);
        $this->call('POST', '/webhooks/pey', [], [], [], $server, $body)->assertOk()->assertExactJson(['status' => 'received']);
        Event::assertDispatchedTimes($event, 1);
    }

    public function testItPrintsACurlCommandForTheWebhookRoute(): void
    {
        [, $output] = self::sign(['event' => 'codi.qr.payment_received', '--id' => 'msg_curl']);

        self::assertStringContainsString("curl -X POST 'http://localhost/webhooks/pey'", $output);
        self::assertStringContainsString("-H 'webhook-id: msg_curl'", $output);
        self::assertStringContainsString("-H 'Content-Type: application/json'", $output);
        self::assertStringContainsString('--data-binary \'{"amount":', $output);
    }

    public function testTheCurlUrlCanBeGiven(): void
    {
        [, $output] = self::sign(['event' => 'codi.qr.payment_received', '--url' => 'https://shop.test/hooks/pey']);

        self::assertStringContainsString("curl -X POST 'https://shop.test/hooks/pey'", $output);
    }

    public function testItSignsWithTheGivenSecretAndNeverPrintsASecret(): void
    {
        [$exit, $output] = self::sign(['event' => 'codi.qr.payment_received', '--secret' => TestSecrets::unknownWebhookSecret()]);
        [$headers, $body] = self::parse($output);

        self::assertSame(0, $exit);
        foreach ([TestSecrets::webhookSecret(), TestSecrets::previousWebhookSecret(), TestSecrets::unknownWebhookSecret()] as $secret) {
            self::assertStringNotContainsString($secret, $output);
        }

        // Signed with a secret the application does not accept.
        $server = $this->transformHeadersToServerVars($headers + ['Content-Type' => 'application/json']);
        $this->call('POST', '/webhooks/pey', [], [], [], $server, $body)->assertStatus(400);
    }

    public function testTheBankAccountSampleNeverPrintsAFullClabe(): void
    {
        [, $output] = self::sign(['event' => 'bank.account.updated']);

        self::assertStringNotContainsString('123456789012345673', $output);
        self::assertStringContainsString(Clabe::mask('123456789012345673'), $output);
    }

    public function testItRefusesAnUnknownType(): void
    {
        [$exit, $output] = self::sign(['event' => 'codi.unknown']);

        self::assertSame(1, $exit);
        self::assertStringContainsString('There is no sample for "codi.unknown". Use one of: codi.qr.payment_received, codi.pn.payment_received, payment_link.status_updated, bank.account.updated', $output);
    }

    public function testItNeedsASecret(): void
    {
        config()->set('pey.webhooks.secrets', [null, null]);

        [$exit, $output] = self::sign(['event' => 'codi.qr.payment_received']);

        self::assertSame(1, $exit);
        self::assertStringContainsString('Set PEY_WEBHOOK_SECRET (config key pey.webhooks.secrets) or pass --secret', $output);
    }

    public function testItReportsSecretsOfTheWrongType(): void
    {
        config()->set('pey.webhooks.secrets', 42);

        [$exit, $output] = self::sign(['event' => 'codi.qr.payment_received']);

        self::assertSame(1, $exit);
        self::assertStringContainsString('must be a list of strings', $output);
    }

    public function testItRefusesAnInvalidSecretWithoutPrintingIt(): void
    {
        [$exit, $output] = self::sign(['event' => 'codi.qr.payment_received', '--secret' => 'whsec_not valid base64!']);

        self::assertSame(1, $exit);
        self::assertStringContainsString('webhook secret', $output);
        self::assertStringNotContainsString('not valid base64', $output);
    }

    public function testItRefusesToRunOutsideLocalAndTesting(): void
    {
        $this->application()->instance('env', 'staging');

        [$exit, $output] = self::sign(['event' => 'codi.qr.payment_received']);

        self::assertSame(1, $exit);
        self::assertStringContainsString('pey:webhook:sign runs only in the local and testing environments', $output);
    }

    #[DefineEnvironment('useProduction')]
    public function testItIsNotRegisteredInProduction(): void
    {
        $commands = Artisan::all();

        self::assertArrayHasKey('pey:check', $commands);
        self::assertArrayNotHasKey('pey:webhook:sign', $commands);
    }

    /**
     * @return iterable<string, array{string, class-string}>
     */
    public static function provideTypes(): iterable
    {
        yield 'QR payment' => ['codi.qr.payment_received', CodiQrPaymentReceived::class];
        yield 'push notification payment' => ['codi.pn.payment_received', CodiPushNotificationPaymentReceived::class];
        yield 'payment link' => ['payment_link.status_updated', PaymentLinkStatusUpdated::class];
        yield 'bank account' => ['bank.account.updated', BankAccountUpdated::class];
    }

    protected function useProduction(Application $app): void
    {
        $app->detectEnvironment(static fn(): string => 'production');
    }

    /**
     * @param \Illuminate\Routing\Router $router
     */
    protected function defineRoutes($router): void
    {
        Route::peyWebhooks();
    }

    /**
     * @param array<string, string> $arguments
     *
     * @return array{int, string}
     */
    private static function sign(array $arguments): array
    {
        $exit = Artisan::call('pey:webhook:sign', $arguments);

        return [$exit, Artisan::output()];
    }

    /**
     * The printed headers and body.
     *
     * @return array{array<string, string>, string}
     */
    private static function parse(string $output): array
    {
        preg_match_all('/^(webhook-[a-z]+): (\S+)$/m', $output, $matches, \PREG_SET_ORDER);
        $headers = [];
        foreach ($matches as $match) {
            $headers[$match[1]] = $match[2];
        }

        preg_match('/^(\{.*\})$/m', $output, $body);
        self::assertCount(3, $headers);
        self::assertArrayHasKey(1, $body);

        return [$headers, $body[1]];
    }

    private function application(): Application
    {
        $app = $this->app;
        self::assertNotNull($app);

        return $app;
    }
}
