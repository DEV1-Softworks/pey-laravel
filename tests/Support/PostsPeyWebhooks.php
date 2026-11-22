<?php

declare(strict_types=1);

namespace Dev1\Pey\Laravel\Tests\Support;

use Dev1\Pey\Laravel\PeyServiceProvider;
use Dev1\Pey\Testing\SignedWebhook;
use Dev1\Pey\Testing\WebhookSigner;
use Illuminate\Support\Facades\Date;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signs webhook bodies with the SDK's WebhookSigner, on the Laravel clock, and
 * posts them exactly as Pey would: the raw body and the three headers.
 *
 * @phpstan-require-extends \Dev1\Pey\Laravel\Tests\TestCase
 */
trait PostsPeyWebhooks
{
    private function configureWebhookSecrets(): void
    {
        config()->set('pey.webhooks.secrets', [TestSecrets::webhookSecret(), TestSecrets::previousWebhookSecret()]);
    }

    private static function sign(string $body, ?string $id = null, ?string $secret = null, ?int $timestamp = null): SignedWebhook
    {
        return WebhookSigner::sign($secret ?? TestSecrets::webhookSecret(), $body, $id, $timestamp ?? Date::now()->getTimestamp());
    }

    /**
     * @param array<string, string> $headers sent instead of the signed ones
     *
     * @return TestResponse<Response>
     */
    private function postWebhook(SignedWebhook $signed, ?array $headers = null, ?string $body = null, string $uri = PeyServiceProvider::WEBHOOK_URI): TestResponse
    {
        $server = $this->transformHeadersToServerVars(($headers ?? $signed->headers) + ['Content-Type' => 'application/json']);

        return $this->call('POST', $uri, [], [], [], $server, $body ?? $signed->body);
    }
}
