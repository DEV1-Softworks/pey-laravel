<?php

declare(strict_types=1);

namespace Dev1\Pey\Laravel\Events;

use Dev1\Pey\Webhooks\Event\PaymentLinkStatusUpdated as CoreEvent;
use Dev1\Pey\Webhooks\VerifiedWebhook;

/**
 * `payment_link.status_updated`: a payment link changed status.
 *
 * Do not fulfil the order on this event alone. Confirm each payment with
 * `Pey::transactions()->get($transaction->transactionId)` for the
 * transactions in `$received->event->transactions`, or the link with
 * `Pey::paymentLinks()->get($received->event->paymentLinkId)`.
 *
 * Queue the listener (ShouldQueue) and make it idempotent. The event holds
 * the SDK's verified objects only: never the raw body, the headers or a secret.
 */
final readonly class PaymentLinkStatusUpdated
{
    public function __construct(
        public CoreEvent $event,
        public VerifiedWebhook $webhook,
    ) {}
}
