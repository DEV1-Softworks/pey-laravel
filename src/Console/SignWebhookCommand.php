<?php

declare(strict_types=1);

namespace Dev1\Pey\Laravel\Console;

use Dev1\Pey\Exception\ConfigurationException;
use Dev1\Pey\Laravel\PeyServiceProvider;
use Dev1\Pey\Laravel\Support\CoreFactory;
use Dev1\Pey\Security\Secret;
use Dev1\Pey\Testing\WebhookSigner;
use Dev1\Pey\Webhooks\WebhookVerifier;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Routing\UrlGenerator;
use Psr\Clock\ClockInterface;
use Symfony\Component\Routing\Exception\RouteNotFoundException;

/**
 * `php artisan pey:webhook:sign {event}`: prints a sample webhook of that
 * type, signed with the SDK's WebhookSigner, and a curl command that posts
 * it, to test a webhook endpoint locally. Sending the same output twice
 * sends the same webhook-id, so it also exercises deduplication.
 *
 * Only the local and testing environments register it, and it refuses to run
 * anywhere else. It signs with the configured PEY_WEBHOOK_SECRET unless
 * --secret is given, and never prints the secret.
 *
 * @internal
 */
final class SignWebhookCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'pey:webhook:sign
        {event : The event type: codi.qr.payment_received, codi.pn.payment_received, payment_link.status_updated or bank.account.updated}
        {--secret= : A whsec_ secret to sign with instead of PEY_WEBHOOK_SECRET}
        {--id= : The webhook-id (random by default)}
        {--url= : The URL in the curl command (the pey.webhooks route by default)}';

    /**
     * @var string
     */
    protected $description = 'Print a signed sample Pey webhook and a curl command to send it (local and testing only)';

    public function handle(Repository $config, ClockInterface $clock, UrlGenerator $urls): int
    {
        if ($this->laravel->environment('local', 'testing') !== true) {
            $this->components->error('pey:webhook:sign runs only in the local and testing environments.');

            return self::FAILURE;
        }

        $type = $this->stringArgument('event');
        $body = WebhookSamples::body($type, $clock);
        if ($body === null) {
            $this->components->error(\sprintf('There is no sample for "%s". Use one of: %s.', $type, implode(', ', WebhookSamples::types())));

            return self::FAILURE;
        }

        try {
            $option = $this->stringOption('secret');
            $secret = $option === null ? CoreFactory::currentWebhookSecret($config) : new Secret($option);
        } catch (ConfigurationException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        if ($secret === null) {
            $this->components->error('No Pey webhook secret is configured. Set PEY_WEBHOOK_SECRET (config key pey.webhooks.secrets) or pass --secret.');

            return self::FAILURE;
        }

        try {
            // The signer accepts malformed secrets; the verifier checks the
            // whsec_ format, as the webhook route will, without echoing it.
            new WebhookVerifier($secret);
        } catch (ConfigurationException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        try {
            $signed = WebhookSigner::sign($secret->reveal(), $body, $this->stringOption('id'), $clock->now()->getTimestamp());
        } catch (\Throwable) {
            // The library does not mark its secret parameter as sensitive, so
            // neither its message nor its trace is printed.
            $this->components->error('The webhook secret is not a valid whsec_ secret.');

            return self::FAILURE;
        }

        foreach ($signed->headers as $name => $value) {
            $this->line($name . ': ' . $value);
        }
        $this->newLine();
        $this->line($signed->body);
        $this->newLine();

        $command = ['curl -X POST ' . escapeshellarg($this->stringOption('url') ?? $this->webhookUrl($urls)), "-H 'Content-Type: application/json'"];
        foreach ($signed->headers as $name => $value) {
            $command[] = '-H ' . escapeshellarg($name . ': ' . $value);
        }
        $command[] = '--data-binary ' . escapeshellarg($signed->body);
        $this->line(implode(" \\\n  ", $command));

        return self::SUCCESS;
    }

    private function webhookUrl(UrlGenerator $urls): string
    {
        try {
            return $urls->route(PeyServiceProvider::WEBHOOK_ROUTE);
        } catch (RouteNotFoundException) {
            return $urls->to(PeyServiceProvider::WEBHOOK_URI);
        }
    }

    private function stringArgument(string $name): string
    {
        $value = $this->argument($name);

        return \is_string($value) ? $value : '';
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return \is_string($value) && $value !== '' ? $value : null;
    }
}
