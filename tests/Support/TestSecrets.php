<?php

declare(strict_types=1);

namespace Dev1\Pey\Laravel\Tests\Support;

/**
 * Obviously fake credentials and CLABEs for tests. Never put real ones here.
 *
 * Webhook secrets are built as `whsec_` + base64 of a readable test string,
 * so the repository holds no secret-looking literal for scanners to flag.
 */
final class TestSecrets
{
    public const API_KEY = 'pey_test_not_a_real_value';

    /**
     * Valid check digits, but not real accounts.
     */
    public const CLABE = '123456789012345673';

    public const OTHER_CLABE = '111111111111111115';

    /**
     * Wrong check digit.
     */
    public const INVALID_CLABE = '123456789012345670';

    public static function webhookSecret(): string
    {
        return self::fakeWebhookSecret('pey-laravel test webhook secret, not real');
    }

    public static function previousWebhookSecret(): string
    {
        return self::fakeWebhookSecret('pey-laravel previous test webhook secret, not real');
    }

    public static function unknownWebhookSecret(): string
    {
        return self::fakeWebhookSecret('pey-laravel unknown test webhook secret, not real');
    }

    private static function fakeWebhookSecret(string $readable): string
    {
        return 'whsec_' . base64_encode($readable);
    }
}
