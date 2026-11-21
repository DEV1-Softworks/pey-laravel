<?php

declare(strict_types=1);

namespace Dev1\Pey\Laravel\Events;

use Dev1\Pey\Webhooks\Event\BankAccountUpdated as CoreEvent;
use Dev1\Pey\Webhooks\VerifiedWebhook;

/**
 * `bank.account.updated`: a bank account registered through the API became
 * ACTIVE and can receive CoDi payments.
 *
 * **Personal data.** The SDK event carries the full CLABE, the holder's name
 * and, when Pey has it, their RFC (`taxId`). A queued listener serializes the
 * event into the queue payload, so they are stored by the queue driver (and
 * in failed_jobs if the listener fails). Mask the CLABE with
 * `Dev1\Pey\Validation\Clabe::mask()` before logging it, and keep the queue
 * storage as protected as your database.
 *
 * Queue the listener (ShouldQueue) and make it idempotent. The event holds
 * the SDK's verified objects only: never the raw body, the headers or a secret.
 */
final readonly class BankAccountUpdated
{
    public function __construct(
        public CoreEvent $event,
        public VerifiedWebhook $webhook,
    ) {}
}
