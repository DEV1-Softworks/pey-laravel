<?php

declare(strict_types=1);

namespace Dev1\Pey\Laravel\Tests\Events;

use Dev1\Pey\Laravel\Clock\LaravelClock;
use Dev1\Pey\Laravel\Events\BankAccountUpdated;
use Dev1\Pey\Laravel\Events\CodiPushNotificationPaymentReceived;
use Dev1\Pey\Laravel\Events\CodiQrPaymentReceived;
use Dev1\Pey\Laravel\Events\PaymentLinkStatusUpdated;
use Dev1\Pey\Laravel\Events\PeyWebhookReceived;
use Dev1\Pey\Laravel\Events\UnknownPeyEvent;
use Dev1\Pey\Laravel\PeyServiceProvider;
use Dev1\Pey\Laravel\Support\CoreFactory;
use Dev1\Pey\Laravel\Tests\Support\QueuedListener;
use Dev1\Pey\Laravel\Tests\Support\TestSecrets;
use Dev1\Pey\Laravel\Tests\Support\VerifiedWebhooks;
use Dev1\Pey\Laravel\Tests\Support\WebhookBodies;
use Dev1\Pey\Laravel\Tests\TestCase;
use Dev1\Pey\Laravel\Webhooks\WebhookEvents;
use Illuminate\Events\CallQueuedListener;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * Every event is serialized as Laravel's queue does for a queued listener,
 * and must carry no secret, no raw body and no header.
 */
#[CoversClass(PeyWebhookReceived::class)]
#[CoversClass(CodiQrPaymentReceived::class)]
#[CoversClass(CodiPushNotificationPaymentReceived::class)]
#[CoversClass(PaymentLinkStatusUpdated::class)]
#[CoversClass(BankAccountUpdated::class)]
#[CoversClass(UnknownPeyEvent::class)]
#[UsesClass(WebhookEvents::class)]
#[UsesClass(PeyServiceProvider::class)]
#[UsesClass(LaravelClock::class)]
#[UsesClass(CoreFactory::class)]
final class QueuedEventsTest extends TestCase
{
    #[DataProvider('provideBodies')]
    public function testQueuedEventsCarryNoSecretRawBodyOrHeader(string $body): void
    {
        config()->set('pey.api_key', TestSecrets::API_KEY);
        config()->set('pey.webhooks.secrets', [TestSecrets::webhookSecret(), TestSecrets::previousWebhookSecret()]);
        [$webhook, $signed] = VerifiedWebhooks::verify($body, 'msg_queued');

        $events = WebhookEvents::for($webhook);
        self::assertCount(2, $events);

        foreach ($events as $event) {
            $payload = serialize(new CallQueuedListener(QueuedListener::class, 'handle', [$event]));

            foreach ([$signed->body, $signed->headers['webhook-signature'], TestSecrets::API_KEY, TestSecrets::webhookSecret(), TestSecrets::previousWebhookSecret()] as $forbidden) {
                self::assertStringNotContainsString($forbidden, $payload, $event::class . ' must not carry it.');
            }

            $job = unserialize($payload);
            self::assertInstanceOf(CallQueuedListener::class, $job);
            self::assertEquals([$event], $job->data);
        }
    }

    /**
     * Documented on the event: queued listeners receive the full CLABE. If the
     * SDK starts masking it, update the docs and this test.
     */
    public function testBankAccountUpdatedCarriesTheFullClabeAsDocumented(): void
    {
        [$webhook] = VerifiedWebhooks::verify(WebhookBodies::bankAccountActivated());
        $event = WebhookEvents::for($webhook)[1] ?? null;
        self::assertInstanceOf(BankAccountUpdated::class, $event);

        self::assertStringContainsString(TestSecrets::CLABE, serialize(new CallQueuedListener(QueuedListener::class, 'handle', [$event])));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideBodies(): iterable
    {
        yield 'QR payment' => [WebhookBodies::qrPayment()];
        yield 'push notification payment' => [WebhookBodies::pushNotificationPayment()];
        yield 'payment link paid' => [WebhookBodies::paymentLinkPaid()];
        yield 'bank account activated' => [WebhookBodies::bankAccountActivated()];
        yield 'unknown type' => [WebhookBodies::UNKNOWN];
    }
}
