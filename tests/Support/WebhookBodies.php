<?php

declare(strict_types=1);

namespace Dev1\Pey\Laravel\Tests\Support;

use Dev1\Pey\Dto\Request\CreatePaymentLinkRequest;
use Dev1\Pey\Dto\Request\CreatePushNotificationRequest;
use Dev1\Pey\Dto\Request\CreateQrRequest;
use Dev1\Pey\Dto\Request\RegisterBankAccountRequest;
use Dev1\Pey\Enum\Entity;
use Dev1\Pey\Testing\FakePeyClient;

/**
 * Webhook bodies in the documented shapes, produced by the SDK's own
 * FakePeyClient simulations so this package never writes a payload shape.
 */
final class WebhookBodies
{
    /**
     * A type the SDK does not model.
     */
    public const UNKNOWN = '{"type":"pey_laravel.test","note":"not a real event"}';

    public static function qrPayment(): string
    {
        $pey = new FakePeyClient();
        $qr = $pey->codi()->createQr(new CreateQrRequest('150.00', concept: 'Order 1'));

        return $pey->simulateQrPayment($qr->id)->webhookBody;
    }

    public static function pushNotificationPayment(): string
    {
        $pey = new FakePeyClient();
        $notification = $pey->codi()->createPushNotification(new CreatePushNotificationRequest('150.00', '5512345678', concept: 'Order 1'));

        return $pey->simulatePushNotificationPayment($notification->transactionId)->webhookBody;
    }

    public static function paymentLinkPaid(): string
    {
        $pey = new FakePeyClient();
        $link = $pey->paymentLinks()->create(new CreatePaymentLinkRequest('150.00', 'https://shop.example/ok', 'https://shop.example/back', clientReferenceId: 'order-1'));

        return $pey->simulatePaymentLinkPayment($link->id)->webhookBody;
    }

    public static function bankAccountActivated(): string
    {
        $pey = new FakePeyClient([TestSecrets::CLABE]);
        $registration = $pey->bankAccounts()->register(new RegisterBankAccountRequest('Main', 'Pey Laravel Test', TestSecrets::CLABE, Entity::Business));

        return $pey->activateBankAccount($registration->id);
    }
}
