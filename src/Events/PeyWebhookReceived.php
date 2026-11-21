<?php

declare(strict_types=1);

namespace Dev1\Pey\Laravel\Events;

use Dev1\Pey\Webhooks\VerifiedWebhook;

/**
 * A Pey webhook arrived, its signature was verified, and its id had not been
 * seen before. It is dispatched for every event type, before the typed event
 * (for example CodiQrPaymentReceived).
 *
 * Do not fulfil an order on a webhook alone. Confirm the payment with
 * `Pey::transactions()->get($id)` first.
 *
 * Listeners should be queued (ShouldQueue), so the webhook is answered
 * quickly, and idempotent: a delivery whose listener failed is accepted again
 * when Pey retries it.
 *
 * It holds the SDK's verified webhook only: never the raw body, the headers
 * or a secret. A queued listener serializes it whole, so personal data in the
 * event (for example the CLABE of a BankAccountUpdated) reaches the queue.
 */
final readonly class PeyWebhookReceived
{
    public function __construct(
        public VerifiedWebhook $webhook,
    ) {}
}
