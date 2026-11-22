<?php

declare(strict_types=1);

namespace Dev1\Pey\Laravel\Tests\Testing;

use Dev1\Pey\Dto\Request\CreateQrRequest;
use Dev1\Pey\Laravel\Clock\LaravelClock;
use Dev1\Pey\Laravel\Events\CodiQrPaymentReceived;
use Dev1\Pey\Laravel\Events\PeyWebhookReceived;
use Dev1\Pey\Laravel\Facades\Pey;
use Dev1\Pey\Laravel\Http\Controllers\PeyWebhookController;
use Dev1\Pey\Laravel\Http\Middleware\VerifyPeyWebhook;
use Dev1\Pey\Laravel\PeyServiceProvider;
use Dev1\Pey\Laravel\Support\CoreFactory;
use Dev1\Pey\Laravel\Testing\InteractsWithPey;
use Dev1\Pey\Laravel\Tests\TestCase;
use Dev1\Pey\Laravel\Webhooks\WebhookDeduplicator;
use Dev1\Pey\Laravel\Webhooks\WebhookEvents;
use PHPUnit\Framework\Attributes\CoversTrait;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * An application that routes Pey to the controller itself, without the
 * pey.webhooks route name: webhooks go to /webhooks/pey.
 */
#[CoversTrait(InteractsWithPey::class)]
#[UsesClass(Pey::class)]
#[UsesClass(CoreFactory::class)]
#[UsesClass(PeyServiceProvider::class)]
#[UsesClass(LaravelClock::class)]
#[UsesClass(VerifyPeyWebhook::class)]
#[UsesClass(PeyWebhookController::class)]
#[UsesClass(WebhookDeduplicator::class)]
#[UsesClass(WebhookEvents::class)]
#[UsesClass(PeyWebhookReceived::class)]
#[UsesClass(CodiQrPaymentReceived::class)]
final class InteractsWithPeyWithoutNamedRouteTest extends TestCase
{
    use InteractsWithPey;

    public function testItPostsToTheDefaultUri(): void
    {
        $fake = $this->fakePey();
        $payment = $fake->simulateQrPayment(Pey::codi()->createQr(new CreateQrRequest('150.00'))->id);

        $this->postPeyWebhook($payment)->assertOk();

        $this->assertPeyWebhookDispatched(CodiQrPaymentReceived::class);
    }

    /**
     * @param \Illuminate\Routing\Router $router
     */
    protected function defineRoutes($router): void
    {
        $router->post(PeyServiceProvider::WEBHOOK_URI, PeyWebhookController::class)->middleware(VerifyPeyWebhook::class);
    }
}
