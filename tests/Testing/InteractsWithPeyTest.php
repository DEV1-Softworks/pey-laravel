<?php

declare(strict_types=1);

namespace Dev1\Pey\Laravel\Tests\Testing;

use Dev1\Pey\Dto\Request\CreateQrRequest;
use Dev1\Pey\Dto\Request\RegisterBankAccountRequest;
use Dev1\Pey\Enum\Entity;
use Dev1\Pey\Laravel\Clock\LaravelClock;
use Dev1\Pey\Laravel\Events\BankAccountUpdated;
use Dev1\Pey\Laravel\Events\CodiQrPaymentReceived;
use Dev1\Pey\Laravel\Events\PeyWebhookReceived;
use Dev1\Pey\Laravel\Events\UnknownPeyEvent;
use Dev1\Pey\Laravel\Facades\Pey;
use Dev1\Pey\Laravel\Http\Controllers\PeyWebhookController;
use Dev1\Pey\Laravel\Http\Middleware\VerifyPeyWebhook;
use Dev1\Pey\Laravel\PeyServiceProvider;
use Dev1\Pey\Laravel\Support\CoreFactory;
use Dev1\Pey\Laravel\Testing\InteractsWithPey;
use Dev1\Pey\Laravel\Tests\Support\TestSecrets;
use Dev1\Pey\Laravel\Tests\TestCase;
use Dev1\Pey\Laravel\Webhooks\WebhookDeduplicator;
use Dev1\Pey\Laravel\Webhooks\WebhookEvents;
use Dev1\Pey\Security\Secret;
use Dev1\Pey\Testing\FakePeyClient;
use Dev1\Pey\Testing\SimulatedPayment;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversTrait(InteractsWithPey::class)]
#[UsesClass(Pey::class)]
#[UsesClass(CoreFactory::class)]
#[UsesClass(PeyServiceProvider::class)]
#[UsesClass(LaravelClock::class)]
#[UsesClass(VerifyPeyWebhook::class)]
#[UsesClass(PeyWebhookController::class)]
#[UsesClass(WebhookDeduplicator::class)]
#[UsesClass(WebhookEvents::class)]
#[UsesClass(PeyWebhookReceived::class)]
#[UsesClass(CodiQrPaymentReceived::class)]
#[UsesClass(BankAccountUpdated::class)]
#[UsesClass(UnknownPeyEvent::class)]
final class InteractsWithPeyTest extends TestCase
{
    use InteractsWithPey;

    public function testFakePeyReplacesTheClient(): void
    {
        $fake = $this->fakePey([TestSecrets::CLABE]);

        self::assertSame($fake, Pey::getFacadeRoot());
        $fake->bankAccounts()->register(new RegisterBankAccountRequest('Main', 'Pey Laravel Test', TestSecrets::CLABE, Entity::Business));
        self::assertCount(1, Pey::callsTo('bankAccounts.register'));
    }

    public function testAPaymentWebhookReachesTheListenersThatConfirmIt(): void
    {
        $confirmed = [];
        Event::listen(CodiQrPaymentReceived::class, static function (CodiQrPaymentReceived $received) use (&$confirmed): void {
            // What a real listener does before fulfilling: ask Pey.
            $confirmed[] = Pey::transactions()->get($received->event->transactionId)->isPaid();
        });
        $payment = $this->paidQr($this->fakePey());

        $this->postPeyWebhook($payment)->assertOk()->assertExactJson(['status' => 'received']);

        self::assertSame([true], $confirmed);
        $this->assertPeyWebhookDispatched(PeyWebhookReceived::class);
        $this->assertPeyWebhookDispatched(CodiQrPaymentReceived::class, static fn(object $event): bool => $event instanceof CodiQrPaymentReceived && $event->event->transactionId === $payment->transaction->id);
        $this->assertPeyWebhookNotDispatched(BankAccountUpdated::class);
    }

    public function testTheSameWebhookSentTwiceIsDispatchedOnce(): void
    {
        $payment = $this->paidQr($this->fakePey());

        $this->postPeyWebhook($payment, id: 'msg_twice')->assertExactJson(['status' => 'received']);
        $this->postPeyWebhook($payment, id: 'msg_twice')->assertExactJson(['status' => 'duplicate']);

        $this->assertPeyWebhookDispatched(CodiQrPaymentReceived::class, times: 1);
    }

    public function testItPostsABody(): void
    {
        $fake = $this->fakePey([TestSecrets::CLABE]);
        $account = $fake->bankAccounts()->register(new RegisterBankAccountRequest('Main', 'Pey Laravel Test', TestSecrets::CLABE, Entity::Business));

        $this->postPeyWebhook($fake->activateBankAccount($account->id))->assertOk();
        $this->postPeyWebhook('{"type":"pey_laravel.test"}')->assertOk();

        $this->assertPeyWebhookDispatched(BankAccountUpdated::class);
        $this->assertPeyWebhookDispatched(UnknownPeyEvent::class);
        $this->assertPeyWebhookDispatched(PeyWebhookReceived::class, times: 2);
    }

    public function testItConfiguresARandomSecretWhenNoneIsConfigured(): void
    {
        // The shipped default: PEY_WEBHOOK_SECRET and PEY_WEBHOOK_SECRET_PREVIOUS unset.
        self::assertSame([null, null], config('pey.webhooks.secrets'));

        $this->postPeyWebhook($this->paidQr($this->fakePey()))->assertOk();

        $secret = $this->currentWebhookSecret();
        self::assertNotNull($secret);
        self::assertStringStartsWith('whsec_', $secret->reveal());
    }

    public function testItSignsWithTheConfiguredSecret(): void
    {
        config()->set('pey.webhooks.secrets', [TestSecrets::webhookSecret()]);

        $this->postPeyWebhook($this->paidQr($this->fakePey()))->assertOk();

        self::assertSame([TestSecrets::webhookSecret()], config('pey.webhooks.secrets'));
    }

    public function testItSignsOnTheLaravelClock(): void
    {
        // Far from the real time: a signature on time() would be "too new".
        $this->travelTo(new \DateTimeImmutable('2026-03-04 05:06:07 UTC'));

        $this->postPeyWebhook($this->paidQr($this->fakePey()))->assertOk();
    }

    public function testItPostsToAnotherUri(): void
    {
        Route::post('/pey-laravel-test/custom', PeyWebhookController::class)->middleware(VerifyPeyWebhook::class);

        $this->postPeyWebhook($this->paidQr($this->fakePey()), '/pey-laravel-test/custom')->assertOk();

        $this->assertPeyWebhookDispatched(CodiQrPaymentReceived::class);
    }

    public function testTheAssertionsSeeFakedEvents(): void
    {
        Event::fake();
        $payment = $this->paidQr($this->fakePey());

        $this->postPeyWebhook($payment)->assertOk();

        $this->assertPeyWebhookDispatched(CodiQrPaymentReceived::class);
        $this->assertPeyWebhookDispatched(PeyWebhookReceived::class);
        Event::assertDispatched(CodiQrPaymentReceived::class);
    }

    public function testTheDispatchedAssertionFailsWithAClearMessage(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage(CodiQrPaymentReceived::class . ' was dispatched 0 time(s), expected 1.');

        $this->assertPeyWebhookDispatched(CodiQrPaymentReceived::class);
    }

    public function testTheNotDispatchedAssertionFailsWhenItWasDispatched(): void
    {
        $this->postPeyWebhook($this->paidQr($this->fakePey()))->assertOk();

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage(CodiQrPaymentReceived::class . ' was dispatched 1 time(s), expected 0.');

        $this->assertPeyWebhookNotDispatched(CodiQrPaymentReceived::class);
    }

    public function testOnlyThePackagesEventsAreRecorded(): void
    {
        Event::dispatch(new \stdClass());

        $this->assertPeyWebhookNotDispatched(\stdClass::class);
    }

    /**
     * @param \Illuminate\Routing\Router $router
     */
    protected function defineRoutes($router): void
    {
        Route::peyWebhooks();
    }

    private function currentWebhookSecret(): ?Secret
    {
        $app = $this->app;
        self::assertNotNull($app);

        return CoreFactory::currentWebhookSecret($app->make(Repository::class));
    }

    private function paidQr(FakePeyClient $fake): SimulatedPayment
    {
        return $fake->simulateQrPayment(Pey::codi()->createQr(new CreateQrRequest('150.00', concept: 'Order 1'))->id);
    }
}
