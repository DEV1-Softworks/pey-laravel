<?php

declare(strict_types=1);

namespace Dev1\Pey\Laravel\Console;

use Dev1\Pey\Config;
use Dev1\Pey\Exception\ConfigurationException;
use Dev1\Pey\Exception\NetworkException;
use Dev1\Pey\Exception\PeyException;
use Dev1\Pey\Exception\UnexpectedResponseException;
use Dev1\Pey\Idempotency\IdempotencyStore;
use Dev1\Pey\Laravel\Idempotency\CacheIdempotencyStore;
use Dev1\Pey\Laravel\Support\CoreFactory;
use Dev1\Pey\Laravel\Webhooks\WebhookDeduplicator;
use Dev1\Pey\PeyClientInterface;
use Dev1\Pey\Webhooks\WebhookVerifier;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;

/**
 * `php artisan pey:check`: validates the Pey configuration, then calls
 * `GET /transaction/api-key` once (first page only), so a deploy or a CI job
 * fails early. The exit code is 1 when anything fails.
 *
 * It prints whether the API key is configured and counts, never a secret, a
 * CLABE or a response body. On an API error it prints the HTTP status, the
 * exception type, Pey's error type and the request id, never Pey's message.
 *
 * @internal
 */
final class CheckCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'pey:check';

    /**
     * @var string
     */
    protected $description = 'Validate the Pey configuration and call the Pey API once (GET /transaction/api-key, first page)';

    private bool $failed = false;

    public function handle(Container $container, Repository $config): int
    {
        foreach (CoreFactory::about($config) as $label => $value) {
            $this->components->twoColumnDetail($label, $value);
        }
        $this->newLine();

        $configured = $this->check('Client configuration', static function () use ($container): string {
            $container->make(Config::class);

            return 'OK';
        });

        $this->checkWebhooks($container, $config);

        $this->check('Idempotency store', static function () use ($container, $config): string {
            $store = $container->make(IdempotencyStore::class);
            $name = $config->get('pey.idempotency.store');

            return $store instanceof CacheIdempotencyStore
                ? 'OK, cache store ' . (\is_string($name) && $name !== '' ? $name : 'default')
                : 'OK, ' . $store::class;
        });

        if ($configured) {
            $this->checkApi($container);
        } else {
            $this->components->twoColumnDetail('Pey API', '<fg=yellow;options=bold>SKIPPED</> fix the client configuration first');
        }

        $this->newLine();
        if ($this->failed) {
            $this->components->error('The Pey check failed.');

            return self::FAILURE;
        }

        $this->components->info('The Pey check passed.');

        return self::SUCCESS;
    }

    private function checkWebhooks(Container $container, Repository $config): void
    {
        try {
            $configured = CoreFactory::currentWebhookSecret($config) !== null;
        } catch (ConfigurationException) {
            // Invalid, so the verifier check below reports it.
            $configured = true;
        }

        if (!$configured) {
            $this->components->twoColumnDetail('Webhooks', 'not configured (no PEY_WEBHOOK_SECRET)');

            return;
        }

        $this->check('Webhook verifier', static function () use ($container): string {
            $container->make(WebhookVerifier::class);

            return 'OK';
        });

        $this->check('Webhook dedup store', static function () use ($container): string {
            $container->make(WebhookDeduplicator::class);

            return 'OK';
        });

        $warning = CoreFactory::dedupStoreWarning($config, $this->laravel->environment('production') === true, $this->laravel->runningUnitTests());
        if ($warning !== null) {
            $this->components->warn($warning);
        }
    }

    private function checkApi(Container $container): void
    {
        $this->check('Pey API (GET /transaction/api-key)', static function () use ($container): string {
            $page = $container->make(PeyClientInterface::class)->transactions()->listForApiKey();
            $count = \count($page->items);

            // The SDK accepts any 2xx and does not expose which one it was.
            return \sprintf(
                'OK, HTTP 2xx, %d %s on the first page%s',
                $count,
                $count === 1 ? 'transaction' : 'transactions',
                $page->nextToken === null ? '' : ', more pages available',
            );
        });
    }

    /**
     * Runs one check and prints its line. Messages are printed only for
     * exceptions whose messages never carry secrets or response bodies.
     *
     * @param \Closure(): string $check
     */
    private function check(string $label, \Closure $check): bool
    {
        try {
            $this->components->twoColumnDetail($label, '<fg=green;options=bold>' . $check() . '</>');

            return true;
        } catch (ConfigurationException|NetworkException|UnexpectedResponseException $e) {
            $this->reportFailure($label, $e->getMessage());
        } catch (PeyException $e) {
            $this->reportFailure($label, self::describe($e));
        } catch (\Throwable $e) {
            $this->reportFailure($label, (new \ReflectionClass($e))->getShortName());
        }

        return false;
    }

    private function reportFailure(string $label, string $reason): void
    {
        $this->failed = true;
        $this->components->twoColumnDetail($label, '<fg=red;options=bold>FAILED</>');
        $this->components->bulletList([$reason]);
    }

    /**
     * An API error without Pey's message, which comes from the response body.
     */
    private static function describe(PeyException $e): string
    {
        $parts = [(new \ReflectionClass($e))->getShortName()];
        $parts[] = $e->httpStatus() === null ? 'no HTTP status' : 'HTTP ' . $e->httpStatus();

        if ($e->errorType() !== null) {
            $parts[] = 'error type ' . $e->errorType();
        }

        if ($e->requestId() !== null) {
            $parts[] = 'request id ' . $e->requestId();
        }

        return implode(', ', $parts);
    }
}
