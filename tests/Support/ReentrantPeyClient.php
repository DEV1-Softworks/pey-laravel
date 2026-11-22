<?php

declare(strict_types=1);

namespace Dev1\Pey\Laravel\Tests\Support;

use Dev1\Pey\Dto\Request\CreatePushNotificationRequest;
use Dev1\Pey\Dto\Request\CreateQrRequest;
use Dev1\Pey\Dto\Response\PushNotification;
use Dev1\Pey\Dto\Response\QrCode;
use Dev1\Pey\PeyClientInterface;
use Dev1\Pey\Resources\BankAccountsInterface;
use Dev1\Pey\Resources\CodiInterface;
use Dev1\Pey\Resources\PaymentLinksInterface;
use Dev1\Pey\Resources\TransactionsInterface;

/**
 * A client whose createQr() runs a callback, so a test can act while a charge
 * is "in flight" (for example, call IdempotentRequests again with the key).
 */
final class ReentrantPeyClient implements PeyClientInterface
{
    /**
     * @param \Closure(CreateQrRequest): QrCode $createQr
     */
    public function __construct(
        private readonly \Closure $createQr,
    ) {}

    public function codi(): CodiInterface
    {
        return new class ($this->createQr) implements CodiInterface {
            /**
             * @param \Closure(CreateQrRequest): QrCode $createQr
             */
            public function __construct(
                private readonly \Closure $createQr,
            ) {}

            public function createQr(CreateQrRequest $request): QrCode
            {
                return ($this->createQr)($request);
            }

            public function createPushNotification(CreatePushNotificationRequest $request): PushNotification
            {
                throw new \LogicException('Not used by these tests.');
            }
        };
    }

    public function bankAccounts(): BankAccountsInterface
    {
        throw new \LogicException('Not used by these tests.');
    }

    public function paymentLinks(): PaymentLinksInterface
    {
        throw new \LogicException('Not used by these tests.');
    }

    public function transactions(): TransactionsInterface
    {
        throw new \LogicException('Not used by these tests.');
    }
}
