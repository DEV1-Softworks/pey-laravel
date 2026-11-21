<?php

declare(strict_types=1);

namespace Dev1\Pey\Laravel\Console;

use Dev1\Pey\Dto\Request\CreatePaymentLinkRequest;
use Dev1\Pey\Dto\Request\CreatePushNotificationRequest;
use Dev1\Pey\Dto\Request\CreateQrRequest;
use Dev1\Pey\Dto\Request\RegisterBankAccountRequest;
use Dev1\Pey\Enum\Entity;
use Dev1\Pey\Testing\FakePeyClient;
use Dev1\Pey\Validation\Clabe;
use Dev1\Pey\Webhooks\Event\BankAccountUpdated;
use Dev1\Pey\Webhooks\Event\CodiPushNotificationPaymentReceived;
use Dev1\Pey\Webhooks\Event\CodiQrPaymentReceived;
use Dev1\Pey\Webhooks\Event\PaymentLinkStatusUpdated;
use Psr\Clock\ClockInterface;

/**
 * Sample webhook bodies in Pey's documented shapes, for `pey:webhook:sign`.
 *
 * They come from the SDK's FakePeyClient simulations, so this package never
 * writes a payload shape. The bank account sample carries a fake CLABE,
 * masked, so no full CLABE is ever printed.
 *
 * @internal
 */
final class WebhookSamples
{
    /**
     * A fake CLABE (bank code 123 does not exist) with a valid check digit,
     * for the bank account sample only.
     */
    private const SAMPLE_CLABE = '123456789012345673';

    /**
     * @return list<string>
     */
    public static function types(): array
    {
        return [
            CodiQrPaymentReceived::TYPE,
            CodiPushNotificationPaymentReceived::TYPE,
            PaymentLinkStatusUpdated::TYPE,
            BankAccountUpdated::TYPE,
        ];
    }

    /**
     * The sample body for an event type, or null for a type with no sample.
     */
    public static function body(string $type, ClockInterface $clock): ?string
    {
        $pey = new FakePeyClient([self::SAMPLE_CLABE], $clock);

        return match ($type) {
            CodiQrPaymentReceived::TYPE => $pey->simulateQrPayment(
                $pey->codi()->createQr(new CreateQrRequest('150.00', concept: 'Sample order'))->id,
            )->webhookBody,
            CodiPushNotificationPaymentReceived::TYPE => $pey->simulatePushNotificationPayment(
                $pey->codi()->createPushNotification(new CreatePushNotificationRequest('150.00', '5500000000', concept: 'Sample order'))->transactionId,
            )->webhookBody,
            PaymentLinkStatusUpdated::TYPE => $pey->simulatePaymentLinkPayment(
                $pey->paymentLinks()->create(new CreatePaymentLinkRequest('150.00', 'https://example.com/paid', 'https://example.com/back', clientReferenceId: 'sample-order'))->id,
            )->webhookBody,
            BankAccountUpdated::TYPE => str_replace(
                '"' . self::SAMPLE_CLABE . '"',
                '"' . Clabe::mask(self::SAMPLE_CLABE) . '"',
                $pey->activateBankAccount(
                    $pey->bankAccounts()->register(new RegisterBankAccountRequest('Sample', 'Sample Account', self::SAMPLE_CLABE, Entity::Business))->id,
                ),
            ),
            default => null,
        };
    }
}
