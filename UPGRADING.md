# Upgrading

## 0.3.0 → 0.4.0

**Almost certainly no code changes required.** `0.4.0` is additive apart
from one behaviour change, which only affects you if you render logos from
`logo()`.

### `logo()` now returns a path that resolves

`PaymentProviderInterface::logo()` returned `images/payment-methods/<code>.svg`,
which matched nothing the Laravel bridge published. It now returns
`vendor/dpay/<code>.svg`, matching the `dpay-logos` publish target exactly.

**If you were working around it** — building `asset('vendor/dpay/'.$code.'.svg')`
by hand, as our own docs did — you can now just call the method:

```php
'logo' => asset($provider->logo()),
```

**If you were mapping `images/payment-methods/` to your own asset path**,
that mapping will stop matching. Either drop it and publish the bundled
SVGs (`php artisan vendor:publish --tag=dpay-logos`), or keep serving your
own assets and ignore `logo()`.

**If you implement `PaymentProviderInterface` yourself**, nothing forces a
change — return whatever path your app serves.

Also note `SadadProvider` advertised a `sadad.svg` that was never bundled;
the file now exists, so re-run the publish command to pick it up.

### DTO constructor parameter positions shifted

`GetSessionResponse` and `VerifySessionResponse` gained properties, inserted
before `raw` rather than appended. **Named arguments and `fromArray()` are
unaffected** — which is how the SDK builds them everywhere. Only *positional*
construction of these two DTOs would bind differently.

### Everything else is additive

`PayMethodsClient`, `PayMethod`, `Payment`, `Transport::requestList()`,
`DPayClientFactory::createTransport()`, and the new response properties are
all new surface. Live-limit validation on `openSession()` is **off by
default** — you opt in with `validateAgainstLiveLimits: true` or
`DPAY_VALIDATE_LIVE_LIMITS=true`.

## 0.2.0 → 0.3.0

**No code changes required.** `0.3.0` contains no breaking API changes — it
is a relicensing (proprietary → MIT), documentation corrections from a live
verification run, and additional tests.

Two things are worth reading even though nothing forces you to act:

- **DPay does not settle the amount you send.** It settles at
  `round(amount + fee)` to the nearest whole LYD, applied at payment time,
  so `10.49` settles at `11` and `10.01` settles at `10`. This has always
  been true; `0.3.0` is simply the first release to document it. If you
  reconcile on exact amounts, read the note at the top of the
  [README](README.md).
- **`min_amount` should stay at its permissive `0.01` default.** DPay
  enforces its own per-gateway minimum and maximum, and those are
  configurable per pay method from the merchant dashboard, so any static
  floor you set may reject amounts your account actually accepts.

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
