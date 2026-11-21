<?php

declare(strict_types=1);

namespace Dev1\Pey\Laravel\Testing;

use Dev1\Pey\Laravel\Facades\Pey;
use Dev1\Pey\Laravel\PeyServiceProvider;
use Dev1\Pey\Laravel\Support\CoreFactory;
use Dev1\Pey\Security\Secret;
use Dev1\Pey\Testing\FakePeyClient;
use Dev1\Pey\Testing\SimulatedPayment;
use Dev1\Pey\Testing\WebhookSigner;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Testing\Fakes\EventFake;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Assert;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Exception\RouteNotFoundException;

/**
 * Test helpers for applications that use this package. Use it in a test case
 * built on Laravel's TestCase (or Orchestra Testbench):
 *
 * ```php
 * $pey = $this->fakePey();
 * $qr = Pey::codi()->createQr(new CreateQrRequest('150.00'));   // or let your code create it
 * $payment = $pey->simulateQrPayment($qr->id);
 *
 * $this->postPeyWebhook($payment)->assertOk();
 * $this->assertPeyWebhookDispatched(CodiQrPaymentReceived::class);
 * ```
 *
 * Nothing here talks to Pey: the client is the SDK's FakePeyClient, and
 * webhooks are signed locally with the SDK's WebhookSigner.
 *
 * @phpstan-require-extends \PHPUnit\Framework\TestCase
 */
trait InteractsWithPey
{
    /**
     * The package's events dispatched during the test, in order.
     *
     * @var list<object>
     */
    private array $peyDispatchedEvents = [];

    /**
     * Records the package's events. Laravel calls it when the test starts.
     */
    protected function setUpInteractsWithPey(): void
    {
        $this->peyDispatchedEvents = [];
        $this->peyApplication()->make(Dispatcher::class)->listen('Dev1\Pey\Laravel\Events\*', function (string $name, array $payload): void {
            $event = $payload[0] ?? null;
            if (\is_object($event)) {
                $this->peyDispatchedEvents[] = $event;
            }
        });
    }

    /**
     * Replaces the Pey client with the SDK's FakePeyClient and returns it.
     * See Pey::fake().
     *
     * @param list<string>|null $allowedClabes the CLABEs the fake lets you register; null for `pey.allowed_clabes`
     */
    protected function fakePey(?array $allowedClabes = null): FakePeyClient
    {
        return Pey::fake($allowedClabes);
    }

    /**
     * Posts a webhook as Pey would: the raw body and the `webhook-*` headers,
     * signed with the SDK's WebhookSigner on the Laravel clock.
     *
     * Pass what a FakePeyClient simulation returned, or a body (for example
     * from `activateBankAccount()`). It is signed with the configured webhook
     * secret; when none is configured, a random one is configured for the
     * test. Pass the same $id twice to send the same webhook twice.
     *
     * @param string|null $uri the `pey.webhooks` route (or /webhooks/pey) by default
     *
     * @return TestResponse<Response>
     */
    protected function postPeyWebhook(SimulatedPayment|string $webhook, ?string $uri = null, ?string $id = null): TestResponse
    {
        $app = $this->peyApplication();
        $secret = CoreFactory::currentWebhookSecret($app->make(Repository::class)) ?? self::configureFakePeyWebhookSecret($app);
        $body = $webhook instanceof SimulatedPayment ? $webhook->webhookBody : $webhook;

        $signed = WebhookSigner::sign($secret->reveal(), $body, $id, $app->make(ClockInterface::class)->now()->getTimestamp());
        $server = $this->transformHeadersToServerVars($signed->headers + ['Content-Type' => 'application/json']);

        return $this->call('POST', $uri ?? self::peyWebhookUri($app), [], [], [], $server, $signed->body);
    }

    /**
     * Asserts that a package event (for example CodiQrPaymentReceived) was
     * dispatched exactly $times times, also when events are faked.
     *
     * @param class-string             $eventClass
     * @param (\Closure(object): bool)|null $callback only the events it accepts count
     */
    protected function assertPeyWebhookDispatched(string $eventClass, ?\Closure $callback = null, int $times = 1): void
    {
        $count = \count($this->dispatchedPeyEvents($eventClass, $callback));

        Assert::assertSame($times, $count, \sprintf('%s was dispatched %d time(s), expected %d.', $eventClass, $count, $times));
    }

    /**
     * @param class-string             $eventClass
     * @param (\Closure(object): bool)|null $callback only the events it accepts count
     */
    protected function assertPeyWebhookNotDispatched(string $eventClass, ?\Closure $callback = null): void
    {
        $this->assertPeyWebhookDispatched($eventClass, $callback, 0);
    }

    /**
     * @param class-string             $eventClass
     * @param (\Closure(object): bool)|null $callback
     *
     * @return list<object>
     */
    private function dispatchedPeyEvents(string $eventClass, ?\Closure $callback): array
    {
        $events = $this->peyDispatchedEvents;

        // Event::fake() keeps faked events from the listeners, so ask the fake.
        $dispatcher = Event::getFacadeRoot();
        if ($dispatcher instanceof EventFake) {
            foreach ($dispatcher->dispatched($eventClass) as $arguments) {
                if (\is_array($arguments) && \is_object($arguments[0] ?? null)) {
                    $events[] = $arguments[0];
                }
            }
        }

        return array_values(array_filter(
            $events,
            static fn(object $event): bool => $event instanceof $eventClass && ($callback === null || $callback($event)),
        ));
    }

    private function peyApplication(): Application
    {
        return $this->app ?? throw new \LogicException('InteractsWithPey needs a Laravel application: use it in a Laravel or Testbench test case.');
    }

    private static function configureFakePeyWebhookSecret(Application $app): Secret
    {
        $secret = new Secret('whsec_' . base64_encode(random_bytes(32)));
        // No verifier can have been resolved yet: without a secret it throws.
        $app->make(Repository::class)->set('pey.webhooks.secrets', [$secret->reveal()]);

        return $secret;
    }

    private static function peyWebhookUri(Application $app): string
    {
        try {
            return $app->make(UrlGenerator::class)->route(PeyServiceProvider::WEBHOOK_ROUTE, [], false);
        } catch (RouteNotFoundException) {
            return PeyServiceProvider::WEBHOOK_URI;
        }
    }
}
