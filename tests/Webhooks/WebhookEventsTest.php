<?php

declare(strict_types=1);

namespace Dev1\Pey\Laravel\Tests\Webhooks;

use Dev1\Pey\Laravel\Clock\LaravelClock;
use Dev1\Pey\Laravel\Events\BankAccountUpdated;
use Dev1\Pey\Laravel\Events\CodiPushNotificationPaymentReceived;
use Dev1\Pey\Laravel\Events\CodiQrPaymentReceived;
use Dev1\Pey\Laravel\Events\PaymentLinkStatusUpdated;
use Dev1\Pey\Laravel\Events\PeyWebhookReceived;
use Dev1\Pey\Laravel\Events\UnknownPeyEvent;
use Dev1\Pey\Laravel\PeyServiceProvider;
use Dev1\Pey\Laravel\Support\CoreFactory;
use Dev1\Pey\Laravel\Tests\Support\VerifiedWebhooks;
use Dev1\Pey\Laravel\Tests\Support\WebhookBodies;
use Dev1\Pey\Laravel\Tests\TestCase;
use Dev1\Pey\Laravel\Webhooks\WebhookEvents;
use Dev1\Pey\Webhooks\Event;
use Dev1\Pey\Webhooks\Event\WebhookEvent;
use Dev1\Pey\Webhooks\VerifiedWebhook;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(WebhookEvents::class)]
#[UsesClass(PeyServiceProvider::class)]
#[UsesClass(LaravelClock::class)]
#[UsesClass(PeyWebhookReceived::class)]
#[UsesClass(CodiQrPaymentReceived::class)]
#[UsesClass(CodiPushNotificationPaymentReceived::class)]
#[UsesClass(PaymentLinkStatusUpdated::class)]
#[UsesClass(BankAccountUpdated::class)]
#[UsesClass(UnknownPeyEvent::class)]
#[UsesClass(CoreFactory::class)]
final class WebhookEventsTest extends TestCase
{
    /**
     * @param class-string $laravelEvent
     * @param class-string $coreEvent
     */
    #[DataProvider('provideWebhooks')]
    public function testEachSdkEventIsWrappedInItsLaravelEvent(string $body, string $laravelEvent, string $coreEvent): void
    {
        [$webhook] = VerifiedWebhooks::verify($body);

        $events = WebhookEvents::for($webhook);

        self::assertCount(2, $events);
        self::assertEquals(new PeyWebhookReceived($webhook), $events[0]);
        $typed = $events[1];
        self::assertInstanceOf($laravelEvent, $typed);
        self::assertTrue(property_exists($typed, 'event') && property_exists($typed, 'webhook'));
        self::assertInstanceOf($coreEvent, $typed->event);
        self::assertSame($webhook->event, $typed->event);
        self::assertSame($webhook, $typed->webhook);
    }

    public function testAnSdkEventThisPackageDoesNotKnowGetsTheGenericEventOnly(): void
    {
        $webhook = new VerifiedWebhook('msg_newer_sdk', new \DateTimeImmutable(), new class implements WebhookEvent {
            public function type(): string
            {
                return 'pey_laravel.from_a_newer_sdk';
            }
        });

        self::assertEquals([new PeyWebhookReceived($webhook)], WebhookEvents::for($webhook));
    }

    /**
     * @return iterable<string, array{string, class-string, class-string}>
     */
    public static function provideWebhooks(): iterable
    {
        yield 'QR payment' => [WebhookBodies::qrPayment(), CodiQrPaymentReceived::class, Event\CodiQrPaymentReceived::class];
        yield 'push notification payment' => [WebhookBodies::pushNotificationPayment(), CodiPushNotificationPaymentReceived::class, Event\CodiPushNotificationPaymentReceived::class];
        yield 'payment link paid' => [WebhookBodies::paymentLinkPaid(), PaymentLinkStatusUpdated::class, Event\PaymentLinkStatusUpdated::class];
        yield 'bank account activated' => [WebhookBodies::bankAccountActivated(), BankAccountUpdated::class, Event\BankAccountUpdated::class];
        yield 'unknown type' => [WebhookBodies::UNKNOWN, UnknownPeyEvent::class, Event\UnknownEvent::class];
    }
}
