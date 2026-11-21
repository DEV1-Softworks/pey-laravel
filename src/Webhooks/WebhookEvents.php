<?php

declare(strict_types=1);

namespace Dev1\Pey\Laravel\Webhooks;

use Dev1\Pey\Laravel\Events\BankAccountUpdated;
use Dev1\Pey\Laravel\Events\CodiPushNotificationPaymentReceived;
use Dev1\Pey\Laravel\Events\CodiQrPaymentReceived;
use Dev1\Pey\Laravel\Events\PaymentLinkStatusUpdated;
use Dev1\Pey\Laravel\Events\PeyWebhookReceived;
use Dev1\Pey\Laravel\Events\UnknownPeyEvent;
use Dev1\Pey\Webhooks\Event;
use Dev1\Pey\Webhooks\VerifiedWebhook;

/**
 * The Laravel events dispatched for a verified webhook: the generic
 * PeyWebhookReceived, then the typed event that wraps the SDK's event.
 *
 * An SDK event type this bridge does not know yet (from a newer SDK) gets the
 * generic event only.
 *
 * @internal
 */
final class WebhookEvents
{
    /**
     * @return list<PeyWebhookReceived|CodiQrPaymentReceived|CodiPushNotificationPaymentReceived|PaymentLinkStatusUpdated|BankAccountUpdated|UnknownPeyEvent>
     */
    public static function for(VerifiedWebhook $webhook): array
    {
        $event = $webhook->event;
        $typed = match (true) {
            $event instanceof Event\CodiQrPaymentReceived => new CodiQrPaymentReceived($event, $webhook),
            $event instanceof Event\CodiPushNotificationPaymentReceived => new CodiPushNotificationPaymentReceived($event, $webhook),
            $event instanceof Event\PaymentLinkStatusUpdated => new PaymentLinkStatusUpdated($event, $webhook),
            $event instanceof Event\BankAccountUpdated => new BankAccountUpdated($event, $webhook),
            $event instanceof Event\UnknownEvent => new UnknownPeyEvent($event, $webhook),
            default => null,
        };

        return $typed === null ? [new PeyWebhookReceived($webhook)] : [new PeyWebhookReceived($webhook), $typed];
    }
}
