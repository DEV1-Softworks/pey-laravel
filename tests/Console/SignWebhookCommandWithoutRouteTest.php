<?php

declare(strict_types=1);

namespace Dev1\Pey\Laravel\Tests\Console;

use Dev1\Pey\Laravel\Clock\LaravelClock;
use Dev1\Pey\Laravel\Console\SignWebhookCommand;
use Dev1\Pey\Laravel\Console\WebhookSamples;
use Dev1\Pey\Laravel\PeyServiceProvider;
use Dev1\Pey\Laravel\Support\CoreFactory;
use Dev1\Pey\Laravel\Tests\Support\TestSecrets;
use Dev1\Pey\Laravel\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * An application that routes Pey to its own controller, without
 * Route::peyWebhooks(): the curl command uses the default URI.
 */
#[CoversClass(SignWebhookCommand::class)]
#[UsesClass(WebhookSamples::class)]
#[UsesClass(CoreFactory::class)]
#[UsesClass(PeyServiceProvider::class)]
#[UsesClass(LaravelClock::class)]
final class SignWebhookCommandWithoutRouteTest extends TestCase
{
    public function testTheCurlCommandFallsBackToTheDefaultUri(): void
    {
        config()->set('pey.webhooks.secrets', [TestSecrets::webhookSecret()]);

        self::assertSame(0, Artisan::call('pey:webhook:sign', ['event' => 'codi.qr.payment_received']));
        self::assertStringContainsString("curl -X POST 'http://localhost/webhooks/pey'", Artisan::output());
    }
}
