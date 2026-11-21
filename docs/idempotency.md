---
title: Idempotency
description: Create each Pey charge at most once from Laravel, even with double clicks, queue retries and timeouts, on a shared cache store.
---

# Idempotency

Pey has **no idempotency keys**. If your application sends the same charge twice (a double click, a queue job that retries, a worker restarted mid-request, a retry after a timeout), Pey creates two charges. The SDK never retries a POST itself, but it cannot stop your application from calling twice.

The SDK's `IdempotentRequests` calls Pey **at most once per key**. This package binds it in the container, with its records on a Laravel cache store.

## Usage

Inject `IdempotentRequests` wherever you create charges, and derive each key from your own ids:

```php
use App\Models\Order;
use Dev1\Pey\Dto\Request\CreatePaymentLinkRequest;
use Dev1\Pey\Dto\Request\CreatePushNotificationRequest;
use Dev1\Pey\Dto\Request\CreateQrRequest;
use Dev1\Pey\Idempotency\IdempotentRequests;

final class ChargeOrder
{
    public function __construct(private readonly IdempotentRequests $pey) {}

    public function byQr(Order $order): string
    {
        $qr = $this->pey->createQr("order-{$order->id}:qr", new CreateQrRequest($order->total, concept: "Order {$order->id}"));

        return $qr->qrString;
    }

    public function byPhone(Order $order, string $phone): string
    {
        return $this->pey->createPushNotification("order-{$order->id}:pn", new CreatePushNotificationRequest($order->total, $phone))->transactionId;
    }

    public function byLink(Order $order): string
    {
        // A payment link's key is its clientReferenceId, which is required here.
        return $this->pey->createPaymentLink(new CreatePaymentLinkRequest(
            $order->total,
            successUrl: route('orders.paid', $order),
            backUrl: route('orders.show', $order),
            clientReferenceId: "order-{$order->id}",
        ))->url;
    }
}
```

Calling again with the same key and the same request returns the stored result **without calling Pey**. Every retry of the same order must therefore use the same key.

## What happens on each call

| The key… | Result |
|---|---|
| has never been used | the request is sent once and its result stored |
| completed with the same request | the stored result is returned; **nothing is sent** |
| was used with a **different request** | `IdempotencyKeyReusedException`: one order's charge is never returned for another |
| belongs to an attempt still running | `IdempotencyInProgressException`: try again shortly |
| belongs to an attempt with an **unknown outcome** | `IdempotencyOutcomeUnknownException`: reconcile first |

A failure frees the key only when Pey certainly did not act: a local validation error, or a 4xx answer. Everything else keeps the key blocked as "outcome unknown", because the charge **may exist**:
- any network error, whatever the HTTP client reported;
- any 5xx;
- an unreadable response;
- a process that died mid-request.

The SDK's [idempotency guide](https://github.com/DEV1-Softworks/pey-php-sdk/blob/master/docs/idempotency.md) explains how to reconcile each kind of charge and then call `release()`. Push notifications need the most care, because the payer may have received one.

```php
use Dev1\Pey\Idempotency\IdempotentRequests;
use Dev1\Pey\Idempotency\Operation;

app(IdempotentRequests::class)->release(Operation::CodiPushNotification, 'order-1042:pn');   // logged at notice
```

Release a key only after confirming with Pey that nothing was created. Releasing a key whose charge exists allows a duplicate charge.

## The store

Records are kept in `PEY_IDEMPOTENCY_STORE`, or in the default cache store when it is unset. The store must meet three requirements:
- **Shared:** every process that creates charges for the same Pey account uses it.
- **Atomic locks:** `acquire()` takes `Cache::lock()` on the key, then calls `add()` on the store, so two processes never both send. Drivers whose own `add()` is atomic (redis, memcached, database, dynamodb) guard a second time.
- **No early eviction:** an evicted record allows a duplicate charge.

`database` meets all three; the `cache` and `cache_locks` tables ship with Laravel. So does a dedicated Redis database configured with `noeviction`. You can set only the idempotency store, and keep the rest of your cache where it is:

```dotenv
PEY_IDEMPOTENCY_STORE=database
```

When the store is first resolved, the package refuses three kinds of store, with a `ConfigurationException`:
- a store without atomic locks;
- `null`: its lock always succeeds and it stores nothing, so every charge could be sent twice;
- `array` outside tests: it keeps records and locks in the memory of one process.

`file` is accepted, because its locks are atomic on one server. The package cannot tell how many servers create charges, so in production it logs a warning when it resolves a `file` store.

When the store fails, `acquire()` throws (for example on a lock wait timeout, or a write the store reports as failed), and nothing is sent.

### Your own store

To use another store, bind the SDK's `IdempotencyStore` in one of your service providers. `IdempotentRequests` then uses it:

```php
use App\Pey\RedisIdempotencyStore;
use Dev1\Pey\Idempotency\IdempotencyStore;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(IdempotencyStore::class, RedisIdempotencyStore::class);
    }
}
```

The SDK documents the store contract in `Dev1\Pey\Idempotency\IdempotencyStore`.

## Timing

- **Retention: 7 days.** A result is replayed, and an unknown outcome stays blocked, for 7 days. After that the key is free, and a call sends again. A job retried later than that is no longer protected, for example with `php artisan queue:retry` on an old failed job. Prune or reconcile failed charge jobs within 7 days.
- **In progress: 5 minutes.** An attempt that never finished counts as in progress for 5 minutes, or a minute longer than `PEY_TIMEOUT` when that is longer. After that its outcome is unknown, and the key stays blocked until you reconcile it.

In tests, both follow Laravel's clock, so `$this->travel()` moves them. Redis expires records on its own clock.

## Limits

- The guarantee is only as strong as the store.
- It protects against repeating a **key**. Two different keys for the same order are two charges.
- Only calls made through `IdempotentRequests` are protected. A direct `Pey::codi()->createQr()` is not.

## Testing

`IdempotentRequests` is resolved fresh each time, around the client bound at that moment. So after `Pey::fake()`, the instances you resolve wrap the fake, while the records still go to your configured cache store (`array` in tests):

```php
use Dev1\Pey\Dto\Request\CreateQrRequest;
use Dev1\Pey\Idempotency\IdempotentRequests;
use Dev1\Pey\Laravel\Facades\Pey;

Pey::fake();

app(IdempotentRequests::class)->createQr('order-1:qr', new CreateQrRequest('150.00'));
app(IdempotentRequests::class)->createQr('order-1:qr', new CreateQrRequest('150.00'));

assert(\count(Pey::callsTo('codi.createQr')) === 1);
```

Resolve your services after calling `Pey::fake()`: an instance built before keeps the client it was given.
