# Upgrading

## 0.1.0 → 0.2.0

`0.2.0` is a breaking release. See [CHANGELOG.md](CHANGELOG.md) for the
complete list of changes; this guide covers only what requires you to change
calling code.

If you only use `GatewayManager` / the `DPay` facade's
`provider($code)->sendOtp()` path, the one section that affects you is
"`minAmount` is now a `float`, and its default changed" — everything else is
either additive or below your abstraction level.

### `openSession()` no longer takes positional scalar arguments

**Before:**
```php
$session = $client->openSession('edfali', 50, customerMobile: '0911234567');
```

**After:**
```php
use DPay\Dto\OpenSessionRequest;

$session = $client->openSession(new OpenSessionRequest(
    payMethod: 'edfali',
    amount: 50.0,
    customerMobile: '0911234567',
));
```

Full `OpenSessionRequest` shape: `payMethod`, `amount`, `customerMobile`,
`cardNumber`, `birthYear`, `category`, `description`, `data`. See
[docs/dto-reference.md](docs/dto-reference.md).

If you go through `GatewayManager`/the `DPay` facade's
`provider($code)->sendOtp()` path instead of calling `openSession()`
directly, **you are not affected** — `sendOtp(float $amount, array $fields)`
keeps its existing signature.

### `openSession()` also takes an optional `Idempotency-Key`

```php
$session = $client->openSession($request, idempotencyKey: 'my-unique-key');
```

Optional — omit it and behavior is unchanged. New capability, not a
breaking change.

### `DPayClient`'s constructor takes a `Transport`, not raw PSR-18/17 objects

Only affects you if you construct `DPayClient` directly instead of using
`DPayClientFactory::create()`.

**Before:**
```php
new DPayClient(
    config: $config,
    httpClient: $psr18,
    requestFactory: $psr17,
    streamFactory: $psr17,
    logger: $psr3,
    mockTransport: null,
);
```

**After:**
```php
use DPay\Http\Transport;

$client = new DPayClient(
    config: $config,
    transport: new Transport(
        config: $config,
        httpClient: $psr18,
        requestFactory: $psr17,
        streamFactory: $psr17,
        logger: $psr3,
    ),
    mockTransport: null,
);
```

If you use `DPayClientFactory::create($config)`, **you are not affected** —
the factory builds the `Transport` for you.

### `minAmount` is now a `float`, and its default changed

`DPayConfig::$minAmount` was `int`, default `5`. It's now `float`, default
`0.01` — matching DPay's documented minimum instead of an SDK-imposed
whole-number floor inherited from the original implementation.

**If you relied on the old default of 5:** set it explicitly —
`minAmount: 5.0` (constructor) or `DPAY_MIN_AMOUNT=5` (env, Laravel).
Nothing breaks if you don't; amounts between `0.01` and `5` will simply
stop being rejected pre-flight, which matches DPay's own rules more
closely than the old default did.

### Decimal amounts are now accepted

There was a pre-flight check rejecting any non-integer `amount` (e.g.
`49.5`) with `DPayValidationException`. It's gone — the spec always
allowed decimals, and the SDK's own whole-number cast (`(int) $amount` in
`toBody()`) would have silently truncated `10.50` to `10` if that check
were ever removed without also fixing the cast. Both are fixed together.

**If your integration relies on amounts being rejected unless whole:** add
your own check before calling `sendOtp()`/`openSession()`. The SDK no
longer does this for you.

### Capability flags changed on every provider

`GatewayManager::describe()`'s JSON output — and each provider's
`supportsWebhook()`/`supportsRefund()` — changed:

- `supportsWebhook()` is now `true` for every provider (was `false`
  everywhere). Webhooks are configured account-wide at DPay's Dashboard →
  Webhooks, not per-gateway.
- `MoamalatProvider::supportsRefund()` is now `true` (was `false`). DPay
  supports refunds/voids for Moamalat, triggered from their dashboard and
  observed via `payment.refunded`/`payment.voided` webhooks — not
  something this SDK can invoke directly.

If your frontend renders UI conditionally on these flags (e.g. hiding a
"webhook available" badge), it will start showing differently. Nothing in
the SDK's behavior changed beyond the flags themselves.

### Sadad is now available (opt-in)

New `SadadProvider`. Ships registered but disabled
(`PAYMENT_GATEWAY_SADAD_ENABLED=false`) — DPay gates it per-merchant. No
action needed unless you want to enable it; see
[docs/providers.md § Sadad](docs/providers.md#sadad).

### New: webhooks

New `DPay\Webhooks\WebhookVerifier` and `WebhookEventFactory` (framework-
agnostic), plus an opt-in Laravel receiver route
(`DPAY_WEBHOOKS_ENABLED=false` by default). Entirely additive — nothing to
change unless you want to adopt it. See
[docs/webhooks.md](docs/webhooks.md).
