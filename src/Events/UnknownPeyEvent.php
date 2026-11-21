<?php

declare(strict_types=1);

namespace Dev1\Pey\Laravel\Events;

use Dev1\Pey\Webhooks\Event\UnknownEvent;
use Dev1\Pey\Webhooks\VerifiedWebhook;

/**
 * A verified webhook of a type the installed SDK does not model, for example
 * one Pey added later. The body is authentic; only its shape is unknown. It is
 * acknowledged (200) so Pey stops retrying. Upgrade dev1/pey-php-sdk to
 * handle it.
 *
 * If it reports a payment, confirm it with `Pey::transactions()->get($id)`
 * before fulfilling anything.
 *
 * **Personal data.** `$received->event->payload` is the whole verified body,
 * decoded. A queued listener serializes it into the queue payload, so do not
 * log it as is.
 *
 * The event holds the SDK's verified objects only: never the raw body, the
 * headers or a secret.
 */
final readonly class UnknownPeyEvent
{
    public function __construct(
        public UnknownEvent $event,
        public VerifiedWebhook $webhook,
    ) {}
}
