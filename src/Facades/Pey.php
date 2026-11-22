<?php

declare(strict_types=1);

namespace Dev1\Pey\Laravel\Facades;

use Dev1\Pey\Exception\ConfigurationException;
use Dev1\Pey\Laravel\Support\CoreFactory;
use Dev1\Pey\PeyClientInterface;
use Dev1\Pey\Resources\BankAccountsInterface;
use Dev1\Pey\Resources\CodiInterface;
use Dev1\Pey\Resources\PaymentLinksInterface;
use Dev1\Pey\Resources\TransactionsInterface;
use Dev1\Pey\Testing\FakePeyClient;
use Dev1\Pey\Testing\RecordedCall;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use Psr\Clock\ClockInterface;

/**
 * The client of the unofficial Pey PHP SDK, as bound to PeyClientInterface.
 *
 * ```php
 * $qr = Pey::codi()->createQr(new CreateQrRequest('150.00', concept: 'Order 1042'));
 * ```
 *
 * Before fulfilling an order, confirm its payment with
 * `Pey::transactions()->get($id)`. A webhook alone is not proof of payment.
 *
 * @method static CodiInterface         codi()
 * @method static BankAccountsInterface bankAccounts()
 * @method static PaymentLinksInterface paymentLinks()
 * @method static TransactionsInterface transactions()
 *
 * @see PeyClientInterface
 */
final class Pey extends Facade
{
    /**
     * Replaces the client with the SDK's FakePeyClient and returns it, like
     * `Http::fake()`. The fake never talks to the network.
     *
     * - It replaces the PeyClientInterface binding and this facade's root.
     *   Code that type-hints the concrete PeyClient still gets the real one.
     * - IdempotentRequests resolved after faking wraps the fake, with the
     *   configured idempotency store. Services you resolved before faking
     *   keep the client they were given.
     * - Bank account registration is guarded by `pey.allowed_clabes`, as in
     *   production, unless you pass a list.
     * - It uses the container's clock, so `$this->travelTo()` moves its
     *   timestamps.
     * - Every call returns a new, empty fake.
     *
     * The fake records every call (see recordedCalls() and callsTo()) and
     * simulates payments and failures: `simulateQrPayment()`,
     * `failNextAfterProcessing()`, and so on.
     *
     * @param list<string>|null $allowedClabes the CLABEs the fake lets you register; null for `pey.allowed_clabes`
     *
     * @throws ConfigurationException when an allowlisted CLABE is invalid
     */
    public static function fake(?array $allowedClabes = null): FakePeyClient
    {
        $app = self::application();
        $fake = CoreFactory::fakeClient($app->make(Repository::class), $app->make(ClockInterface::class), $allowedClabes);

        self::swap($fake);

        return $fake;
    }

    /**
     * Every call made through the fake, in order. Call fake() first.
     *
     * @return list<RecordedCall>
     *
     * @throws \LogicException when the client is not faked
     */
    public static function recordedCalls(): array
    {
        return self::faked()->recordedCalls();
    }

    /**
     * The calls made through the fake to one method. Call fake() first.
     *
     * @param string $method for example "codi.createQr" or "transactions.get"
     *
     * @return list<RecordedCall>
     *
     * @throws \LogicException when the client is not faked
     */
    public static function callsTo(string $method): array
    {
        return self::faked()->callsTo($method);
    }

    protected static function getFacadeAccessor(): string
    {
        return PeyClientInterface::class;
    }

    private static function faked(): FakePeyClient
    {
        $app = self::application();

        // Only an instance that already exists is inspected: when the client
        // is not faked, building the real one here would be a side effect.
        $client = $app->resolved(PeyClientInterface::class) ? $app->make(PeyClientInterface::class) : null;

        return $client instanceof FakePeyClient
            ? $client
            : throw new \LogicException('The Pey client is not faked. Call Pey::fake() before reading its recorded calls.');
    }

    private static function application(): Application
    {
        return self::getFacadeApplication()
            ?? throw new \LogicException('The Pey facade has no application. Use it inside a booted Laravel application.');
    }
}
