<?php

declare(strict_types=1);

namespace Dev1\Pey\Laravel\Tests\Facades;

use Dev1\Pey\Dto\Request\CreateQrRequest;
use Dev1\Pey\Dto\Request\RegisterBankAccountRequest;
use Dev1\Pey\Enum\Entity;
use Dev1\Pey\Exception\BankAccountNotAllowedException;
use Dev1\Pey\Exception\ConfigurationException;
use Dev1\Pey\Exception\NotFoundException;
use Dev1\Pey\Laravel\Clock\LaravelClock;
use Dev1\Pey\Laravel\Facades\Pey;
use Dev1\Pey\Laravel\PeyServiceProvider;
use Dev1\Pey\Laravel\Support\CoreFactory;
use Dev1\Pey\Laravel\Tests\Support\TestSecrets;
use Dev1\Pey\Laravel\Tests\TestCase;
use Dev1\Pey\PeyClient;
use Dev1\Pey\PeyClientInterface;
use Dev1\Pey\Testing\RecordedCall;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use Psr\Clock\ClockInterface;

#[CoversClass(Pey::class)]
#[UsesClass(CoreFactory::class)]
#[UsesClass(PeyServiceProvider::class)]
#[UsesClass(LaravelClock::class)]
final class PeyTest extends TestCase
{
    private const QR_ID = '01ARZ3NDEKTSV4RRFFQ69G5FAV';

    // ------------------------------------------------------------- the client

    public function testItProxiesTheBoundClient(): void
    {
        config()->set('pey.api_key', TestSecrets::API_KEY);
        $client = $this->application()->make(PeyClientInterface::class);

        self::assertInstanceOf(PeyClient::class, Pey::getFacadeRoot());
        self::assertSame($client, Pey::getFacadeRoot());
        self::assertSame($client->codi(), Pey::codi());
        self::assertSame($client->bankAccounts(), Pey::bankAccounts());
        self::assertSame($client->paymentLinks(), Pey::paymentLinks());
        self::assertSame($client->transactions(), Pey::transactions());
    }

    public function testUsingItWithoutAnApiKeyThrowsAClearError(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Set PEY_API_KEY in your environment');

        Pey::codi();
    }

    // -------------------------------------------------------------- the fake

    public function testFakeSwapsTheBindingWithoutAnyPeySetting(): void
    {
        $app = $this->application();

        $fake = Pey::fake();

        self::assertSame($fake, Pey::getFacadeRoot());
        self::assertSame($fake, $app->make(PeyClientInterface::class));
        self::assertSame($fake, $app->call(static fn(PeyClientInterface $pey): PeyClientInterface => $pey));
    }

    public function testTheConcreteClientStaysReal(): void
    {
        config()->set('pey.api_key', TestSecrets::API_KEY);
        $app = $this->application();
        $real = $app->make(PeyClient::class);

        Pey::fake();

        self::assertSame($real, $app->make(PeyClient::class));
        self::assertNotSame($real, Pey::getFacadeRoot());
    }

    public function testTheFakeRecordsEveryCallForAssertions(): void
    {
        $fake = Pey::fake();
        $request = new CreateQrRequest('150.00', concept: 'Order 1');

        $qr = Pey::codi()->createQr($request);
        $this->application()->call(static function (PeyClientInterface $pey): void {
            try {
                $pey->transactions()->get(self::QR_ID);
            } catch (NotFoundException) {
                // The fake knows no such transaction; the call is still recorded.
            }
        });

        self::assertSame($fake->recordedCalls(), Pey::recordedCalls());
        self::assertSame(['codi.createQr', 'transactions.get'], array_map(static fn(RecordedCall $call): string => $call->method, Pey::recordedCalls()));

        $creates = Pey::callsTo('codi.createQr');
        self::assertCount(1, $creates);
        self::assertSame([$request], $creates[0]->arguments);
        $reads = Pey::callsTo('transactions.get');
        self::assertCount(1, $reads);
        self::assertSame([self::QR_ID], $reads[0]->arguments);
        self::assertSame([], Pey::callsTo('paymentLinks.create'));
        self::assertNotSame('', $qr->id);
    }

    public function testEveryFakeStartsEmpty(): void
    {
        $first = Pey::fake();
        Pey::codi()->createQr(new CreateQrRequest('150.00'));

        $second = Pey::fake();

        self::assertNotSame($first, $second);
        self::assertSame([], Pey::recordedCalls());
        self::assertCount(1, $first->recordedCalls());
    }

    public function testTheFakeIsGuardedByTheConfiguredAllowlist(): void
    {
        config()->set('pey.allowed_clabes', TestSecrets::CLABE);
        Pey::fake();

        self::registerBankAccount(TestSecrets::CLABE);

        $this->expectException(BankAccountNotAllowedException::class);
        self::registerBankAccount(TestSecrets::OTHER_CLABE);
    }

    public function testAGivenAllowlistReplacesTheConfiguredOne(): void
    {
        config()->set('pey.allowed_clabes', TestSecrets::CLABE);
        Pey::fake([TestSecrets::OTHER_CLABE]);

        self::registerBankAccount(TestSecrets::OTHER_CLABE);

        $this->expectException(BankAccountNotAllowedException::class);
        self::registerBankAccount(TestSecrets::CLABE);
    }

    public function testAnInvalidAllowlistedClabeIsReportedMasked(): void
    {
        config()->set('pey.allowed_clabes', TestSecrets::INVALID_CLABE);

        try {
            Pey::fake();
            self::fail('Expected a ConfigurationException.');
        } catch (ConfigurationException $e) {
            self::assertStringNotContainsString(TestSecrets::INVALID_CLABE, $e->getMessage());
        }
    }

    public function testTheFakeFollowsTheTestClock(): void
    {
        $this->travelTo(new \DateTimeImmutable('2026-03-04 05:06:07 UTC'));
        $fake = Pey::fake();

        $payment = $fake->simulateQrPayment(Pey::codi()->createQr(new CreateQrRequest('150.00'))->id);

        self::assertSame('2026-03-04T05:06:07+00:00', Pey::transactions()->get($payment->transaction->id)->dateCreated->format(\DATE_ATOM));
    }

    public function testTheFakeUsesTheApplicationClock(): void
    {
        $this->application()->instance(ClockInterface::class, new class (new \DateTimeImmutable('2030-01-02 03:04:05 UTC')) implements ClockInterface {
            public function __construct(private readonly \DateTimeImmutable $now) {}

            public function now(): \DateTimeImmutable
            {
                return $this->now;
            }
        });
        $fake = Pey::fake();

        $payment = $fake->simulateQrPayment(Pey::codi()->createQr(new CreateQrRequest('150.00'))->id);

        self::assertSame('2030-01-02T03:04:05+00:00', $payment->transaction->dateCreated->format(\DATE_ATOM));
    }

    public function testTheFakeNeverCarriesTheConfiguredSecrets(): void
    {
        config()->set('pey.api_key', TestSecrets::API_KEY);
        config()->set('pey.webhooks.secrets', [TestSecrets::webhookSecret(), TestSecrets::previousWebhookSecret()]);
        $fake = Pey::fake();
        Pey::codi()->createQr(new CreateQrRequest('150.00', concept: 'Order 1'));

        foreach ([$fake, Pey::recordedCalls()] as $value) {
            $dumps = [print_r($value, true), var_export($value, true), (string) json_encode($value), serialize($value)];

            foreach ([TestSecrets::API_KEY, TestSecrets::webhookSecret(), TestSecrets::previousWebhookSecret()] as $secret) {
                foreach ($dumps as $dump) {
                    self::assertStringNotContainsString($secret, $dump);
                }
            }
        }
    }

    // ------------------------------------------------------ misuse is explicit

    #[DataProvider('provideCallReaders')]
    public function testReadingCallsWithoutAFakeFailsWithoutBuildingTheClient(string $reader): void
    {
        try {
            self::readCalls($reader);
            self::fail('Expected a LogicException.');
        } catch (\LogicException $e) {
            self::assertSame('The Pey client is not faked. Call Pey::fake() before reading its recorded calls.', $e->getMessage());
        }

        self::assertFalse($this->application()->resolved(PeyClientInterface::class));
    }

    #[DataProvider('provideCallReaders')]
    public function testReadingCallsOfTheRealClientFails(string $reader): void
    {
        config()->set('pey.api_key', TestSecrets::API_KEY);
        Pey::codi();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Call Pey::fake() before reading its recorded calls.');

        self::readCalls($reader);
    }

    public function testItNeedsAnApplication(): void
    {
        $app = Facade::getFacadeApplication();
        Facade::setFacadeApplication(null);

        try {
            $this->expectException(\LogicException::class);
            $this->expectExceptionMessage('The Pey facade has no application.');

            Pey::fake();
        } finally {
            Facade::setFacadeApplication($app);
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideCallReaders(): iterable
    {
        yield 'recordedCalls' => ['recordedCalls'];
        yield 'callsTo' => ['callsTo'];
    }

    private static function readCalls(string $reader): void
    {
        $reader === 'recordedCalls' ? Pey::recordedCalls() : Pey::callsTo('codi.createQr');
    }

    private static function registerBankAccount(string $clabe): void
    {
        Pey::bankAccounts()->register(new RegisterBankAccountRequest('Main', 'Pey Laravel Test', $clabe, Entity::Business));
    }

    private function application(): Application
    {
        $app = $this->app;
        self::assertNotNull($app);

        return $app;
    }
}
