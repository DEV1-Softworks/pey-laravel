<?php

declare(strict_types=1);

namespace Dev1\Pey\Laravel\Tests\Support;

use Dev1\Pey\Laravel\Clock\LaravelClock;
use Dev1\Pey\Testing\SignedWebhook;
use Dev1\Pey\Testing\WebhookSigner;
use Dev1\Pey\Webhooks\VerifiedWebhook;
use Dev1\Pey\Webhooks\WebhookVerifier;

/**
 * VerifiedWebhooks built the only way the SDK allows: signing a body and
 * verifying it, on the Laravel clock.
 */
final class VerifiedWebhooks
{
    /**
     * @return array{VerifiedWebhook, SignedWebhook}
     */
    public static function verify(string $body, string $id = 'msg_test'): array
    {
        $clock = new LaravelClock();
        $signed = WebhookSigner::sign(TestSecrets::webhookSecret(), $body, $id, $clock->now()->getTimestamp());
        $verifier = new WebhookVerifier(TestSecrets::webhookSecret(), WebhookVerifier::DEFAULT_TOLERANCE_SECONDS, $clock);

        return [$verifier->verify($signed->body, $signed->headers), $signed];
    }
}
