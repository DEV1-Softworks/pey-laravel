<?php

declare(strict_types=1);

namespace Dev1\Pey\Laravel\Http\Controllers;

use Dev1\Pey\Laravel\Http\Middleware\VerifyPeyWebhook;
use Dev1\Pey\Laravel\Webhooks\WebhookDeduplicator;
use Dev1\Pey\Laravel\Webhooks\WebhookEvents;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Receives a webhook that VerifyPeyWebhook verified, and dispatches its
 * events once per webhook id.
 *
 * - A new id dispatches PeyWebhookReceived and then the typed event, and is
 *   answered `200 {"status":"received"}`.
 * - An id already seen within `pey.webhooks.dedup_ttl` dispatches nothing and
 *   is answered `200 {"status":"duplicate"}`, so Pey stops retrying.
 * - If a listener throws, the id is forgotten and the error propagates (500),
 *   so Pey delivers the webhook again.
 *
 * Queue the listeners, so the answer is quick.
 */
final class PeyWebhookController
{
    public function __construct(
        private readonly WebhookDeduplicator $deduplicator,
        private readonly Dispatcher $events,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $webhook = VerifyPeyWebhook::verifiedWebhook($request);

        if (!$this->deduplicator->claim($webhook->id)) {
            return new JsonResponse(['status' => 'duplicate']);
        }

        try {
            foreach (WebhookEvents::for($webhook) as $event) {
                $this->events->dispatch($event);
            }
        } catch (\Throwable $e) {
            $this->deduplicator->release($webhook->id);

            throw $e;
        }

        return new JsonResponse(['status' => 'received']);
    }
}
