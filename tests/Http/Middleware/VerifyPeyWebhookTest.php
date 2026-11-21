<?php

declare(strict_types=1);

namespace Dev1\Pey\Laravel\Tests\Http\Middleware;

use Dev1\Pey\Exception\ConfigurationException;
use Dev1\Pey\Laravel\Clock\LaravelClock;
use Dev1\Pey\Laravel\Http\Middleware\VerifyPeyWebhook;
use Dev1\Pey\Laravel\PeyServiceProvider;
use Dev1\Pey\Laravel\Support\CoreFactory;
use Dev1\Pey\Laravel\Tests\Support\InputTrapRequest;
use Dev1\Pey\Laravel\Tests\Support\PostsPeyWebhooks;
use Dev1\Pey\Laravel\Tests\Support\RecordingLogger;
use Dev1\Pey\Laravel\Tests\Support\TestSecrets;
use Dev1\Pey\Laravel\Tests\Support\WebhookBodies;
use Dev1\Pey\Laravel\Tests\TestCase;
use Dev1\Pey\Webhooks\VerificationFailure;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\HttpFoundation\Response;

#[CoversClass(VerifyPeyWebhook::class)]
#[UsesClass(CoreFactory::class)]
#[UsesClass(PeyServiceProvider::class)]
#[UsesClass(LaravelClock::class)]
final class VerifyPeyWebhookTest extends TestCase
{
    use PostsPeyWebhooks;

    /**
     * A route behind the middleware that echoes what it verified.
     */
    private const URI = '/pey-laravel-test/verified';

    private RecordingLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeTime();
        $this->configureWebhookSecrets();
        $this->logger = new RecordingLogger();
        $this->application()->instance(PeyServiceProvider::LOGGER, $this->logger);
    }

    // ------------------------------------------------------------- accepted

    public function testItPassesTheVerifiedWebhookOn(): void
    {
        $response = $this->postWebhook(self::sign(WebhookBodies::qrPayment(), 'msg_accepted'), uri: self::URI);

        $response->assertOk()->assertExactJson(['id' => 'msg_accepted', 'type' => 'codi.qr.payment_received']);
        self::assertSame([], $this->logger->records);
    }

    public function testItAcceptsThePreviousSecretDuringARotation(): void
    {
        $signed = self::sign(WebhookBodies::UNKNOWN, 'msg_rotation', TestSecrets::previousWebhookSecret());

        $this->postWebhook($signed, uri: self::URI)->assertOk()->assertJson(['id' => 'msg_rotation']);
    }

    public function testItVerifiesTheRawBodyAsSent(): void
    {
        // Laravel's global TrimStrings and ConvertEmptyStringsToNull change the
        // parsed input, never the raw body that is verified.
        $body = "{\"type\":\"pey_laravel.test\",\"note\":\"  padded  \",\"empty\":\"\"}\n";

        $this->postWebhook(self::sign($body, 'msg_raw'), uri: self::URI)->assertOk()->assertJson(['type' => 'pey_laravel.test']);
    }

    public function testItNeverReadsTheRequestInput(): void
    {
        $middleware = $this->application()->make(VerifyPeyWebhook::class);
        $next = static fn(Request $request): Response => new Response(VerifyPeyWebhook::verifiedWebhook($request)->id);

        $valid = self::sign(WebhookBodies::qrPayment(), 'msg_trap');
        self::assertSame('msg_trap', $middleware->handle(self::trapRequest($valid->headers, $valid->body), $next)->getContent());

        $tampered = $middleware->handle(self::trapRequest($valid->headers, $valid->body . ' '), $next);
        self::assertSame(Response::HTTP_BAD_REQUEST, $tampered->getStatusCode());
    }

    // ------------------------------------------------------------- rejected

    public function testItRejectsATamperedBody(): void
    {
        $signed = self::sign(WebhookBodies::qrPayment());

        $response = $this->postWebhook($signed, body: str_replace('150', '151', $signed->body), uri: self::URI);

        $this->assertRejected($response, VerificationFailure::InvalidSignature);
    }

    public function testItRejectsAnUnknownSecret(): void
    {
        $response = $this->postWebhook(self::sign(WebhookBodies::qrPayment(), secret: TestSecrets::unknownWebhookSecret()), uri: self::URI);

        $this->assertRejected($response, VerificationFailure::InvalidSignature);
    }

    public function testItRejectsAStaleTimestamp(): void
    {
        $signed = self::sign(WebhookBodies::qrPayment(), timestamp: Date::now()->getTimestamp() - 301);

        $this->assertRejected($this->postWebhook($signed, uri: self::URI), VerificationFailure::TimestampTooOld);
    }

    public function testItRejectsAFutureTimestamp(): void
    {
        $signed = self::sign(WebhookBodies::qrPayment(), timestamp: Date::now()->getTimestamp() + 301);

        $this->assertRejected($this->postWebhook($signed, uri: self::URI), VerificationFailure::TimestampTooNew);
    }

    public function testItAcceptsATimestampAtTheEdgeOfTheTolerance(): void
    {
        $signed = self::sign(WebhookBodies::UNKNOWN, timestamp: Date::now()->getTimestamp() - 300);

        $this->postWebhook($signed, uri: self::URI)->assertOk();
    }

    #[DataProvider('provideHeaderNames')]
    public function testItRejectsAMissingHeader(string $header): void
    {
        $signed = self::sign(WebhookBodies::qrPayment());
        $headers = $signed->headers;
        unset($headers[$header]);

        $this->assertRejected($this->postWebhook($signed, $headers, uri: self::URI), VerificationFailure::MissingHeader);
    }

    public function testItRejectsAMalformedTimestampHeader(): void
    {
        $signed = self::sign(WebhookBodies::qrPayment());

        $response = $this->postWebhook($signed, ['webhook-timestamp' => 'yesterday'] + $signed->headers, uri: self::URI);

        $this->assertRejected($response, VerificationFailure::InvalidHeader);
    }

    public function testItChecksTheSignatureBeforeTheBodyIsParsed(): void
    {
        $signed = self::sign('{"type": this is not JSON');

        $response = $this->postWebhook($signed, ['webhook-signature' => 'v1,bm90IGEgc2lnbmF0dXJl'] + $signed->headers, uri: self::URI);

        $this->assertRejected($response, VerificationFailure::InvalidSignature);
    }

    #[DataProvider('provideBodiesTheSdkCannotRead')]
    public function testItRejectsAVerifiedBodyTheSdkCannotRead(string $body): void
    {
        $response = $this->postWebhook(self::sign($body), uri: self::URI);

        $response->assertStatus(Response::HTTP_BAD_REQUEST)->assertExactJson(['error' => 'invalid_webhook']);
        self::assertCount(1, $this->logger->records);
        self::assertSame('error', $this->logger->records[0]['level']);
        self::assertSame('invalid_event', $this->logger->records[0]['context']['reason'] ?? null);
        self::assertStringNotContainsString($body, $this->logger->dump());
    }

    public function testItNeverLogsTheBodyTheHeadersOrTheSecrets(): void
    {
        $signed = self::sign(WebhookBodies::qrPayment(), 'msg_never_logged');
        $this->postWebhook($signed, body: $signed->body . ' ', uri: self::URI);
        $this->postWebhook(self::sign(WebhookBodies::qrPayment(), 'msg_never_logged', TestSecrets::unknownWebhookSecret()), uri: self::URI);
        $this->postWebhook(self::sign('{"type":"codi.qr.payment_received","transactionId":"tx_never_logged"}', 'msg_never_logged'), uri: self::URI);

        self::assertCount(3, $this->logger->records);
        $dump = $this->logger->dump();
        foreach ([$signed->body, 'tx_never_logged', 'msg_never_logged', $signed->headers['webhook-signature'], TestSecrets::webhookSecret(), TestSecrets::previousWebhookSecret(), TestSecrets::unknownWebhookSecret()] as $secret) {
            self::assertStringNotContainsString($secret, $dump);
        }
    }

    // --------------------------------------------------------- configuration

    public function testAMissingSecretFailsOnlyWhenAWebhookArrives(): void
    {
        config()->set('pey.webhooks.secrets', [null, null]);
        $this->withoutExceptionHandling();

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Set PEY_WEBHOOK_SECRET in your environment');

        $this->postWebhook(self::sign(WebhookBodies::qrPayment()), uri: self::URI);
    }

    public function testAControllerOutsideTheMiddlewareGetsNoWebhook(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Put the route behind the VerifyPeyWebhook middleware.');

        VerifyPeyWebhook::verifiedWebhook(Request::create(self::URI, 'POST', content: WebhookBodies::UNKNOWN));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideHeaderNames(): iterable
    {
        foreach (['webhook-id', 'webhook-timestamp', 'webhook-signature'] as $header) {
            yield $header => [$header];
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideBodiesTheSdkCannotRead(): iterable
    {
        yield 'not JSON' => ['this is not JSON'];
        yield 'a JSON list' => ['[]'];
        yield 'a known type without its fields' => ['{"type":"codi.qr.payment_received"}'];
    }

    /**
     * @param \Illuminate\Routing\Router $router
     */
    protected function defineRoutes($router): void
    {
        $router->post(self::URI, static function (Request $request): array {
            $webhook = VerifyPeyWebhook::verifiedWebhook($request);

            return ['id' => $webhook->id, 'type' => $webhook->event->type()];
        })->middleware(VerifyPeyWebhook::class);
    }

    /**
     * @param TestResponse<Response> $response
     */
    private function assertRejected(TestResponse $response, VerificationFailure $failure): void
    {
        $response->assertStatus(Response::HTTP_BAD_REQUEST)->assertExactJson(['error' => 'invalid_webhook']);

        self::assertSame([[
            'level' => 'warning',
            'message' => 'Pey webhook rejected: {reason}.',
            'context' => ['reason' => $failure->value],
        ]], $this->logger->records);
    }

    /**
     * @param array<string, string> $headers
     */
    private static function trapRequest(array $headers, string $body): InputTrapRequest
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        foreach ($headers as $name => $value) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return InputTrapRequest::create(self::URI, 'POST', [], [], [], $server, $body);
    }

    private function application(): Application
    {
        $app = $this->app;
        self::assertNotNull($app);

        return $app;
    }
}
