<?php

declare(strict_types=1);

namespace Dev1\Pey\Laravel\Events;

use Dev1\Pey\Webhooks\Event\CodiQrPaymentReceived as CoreEvent;
use Dev1\Pey\Webhooks\VerifiedWebhook;

/**
 * `codi.qr.payment_received`: a CoDi QR created through the API was paid.
 *
 * Do not fulfil the order on this event alone. Confirm the payment with
 * `Pey::transactions()->get($received->event->transactionId)`: status PAID,
 * payableType API_CODI_QR, payableId equal to the QR's id, and
 * `amountBreakdown->base` equal to the order total. A QR without expiration
 * can be paid more than once, so fulfil per transactionId.
 *
 * Queue the listener (ShouldQueue) and make it idempotent. The event holds
 * the SDK's verified objects only: never the raw body, the headers or a secret.
 */
final readonly class CodiQrPaymentReceived
{
    public function __construct(
        public CoreEvent $event,
        public VerifiedWebhook $webhook,
    ) {}
}
