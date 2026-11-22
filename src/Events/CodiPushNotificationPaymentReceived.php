<?php

declare(strict_types=1);

namespace Dev1\Pey\Laravel\Events;

use Dev1\Pey\Webhooks\Event\CodiPushNotificationPaymentReceived as CoreEvent;
use Dev1\Pey\Webhooks\VerifiedWebhook;

/**
 * `codi.pn.payment_received`: a CoDi push notification was paid.
 *
 * Do not fulfil the order on this event alone. Confirm the payment with
 * `Pey::transactions()->get($received->event->transactionId)`: status PAID,
 * payableType API_CODI_PN, and `amountBreakdown->base` equal to the order
 * total. The transactionId is the one createPushNotification() returned.
 *
 * Queue the listener (ShouldQueue) and make it idempotent. The event holds
 * the SDK's verified objects only: never the raw body, the headers or a secret.
 */
final readonly class CodiPushNotificationPaymentReceived
{
    public function __construct(
        public CoreEvent $event,
        public VerifiedWebhook $webhook,
    ) {}
}
