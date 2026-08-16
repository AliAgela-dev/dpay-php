# dpay-php

Framework-agnostic PHP SDK for the **DPay** payment gateway (Libya), with
provider abstractions for Edfali, MobiCash, Sadad, SaharaPay, YousrPay,
MasrefyPay, and Moamalat. Ships an optional Laravel bridge.

Reverse-engineered from a production Laravel implementation, then
aligned against DPay's official API spec (https://dpay.ly/docs/api) and
live-verified against their sandbox — see [SANDBOX-VALIDATION.md](SANDBOX-VALIDATION.md).
Sandbox credentials are per-merchant; get your own before flipping
`mock => false` in production.

---

## Read this before you integrate

Three DPay behaviours surprise people. None of them are SDK bugs, and all
three are measured, not assumed.

**1. The amount you request is not the amount that settles.** DPay settles at
`round(amount + fee)` to the **nearest whole LYD**, applied at payment time:

| You request | fee | total | **settles at** |
|---|---|---|---|
| `10.01` | `0.02` | `10.03` | **`10`** ↓ |
| `10.49` | `0.02` | `10.51` | **`11`** ↑ |
| `10.50` | `0.02` | `10.52` | **`11`** |

It rounds *to nearest*, so a payment can settle **below** what you asked for.
`OpenSessionResponse` gives you `amount`, `fee`, `feeAmount` and `total` — and
none of them is the settled figure. Read that from `getSession()` or the
`payment.paid` webhook, where your original survives as
`data.original_amount`. If you need exact amounts, request whole LYD.

**2. Minimum and maximum deposits are DPay's, per gateway, and configurable
by the merchant.** They're enforced server-side and rejected with
`DPayValidationException`. This SDK's `min_amount` deliberately defaults to a
permissive `0.01` and lets DPay be the authority — no static default could be
right. The SDK cannot currently read these limits (DPay's `GET /pay-methods`
is not yet implemented here), so check your dashboard.

**3. Your `data` object comes back with DPay's keys merged in** —
`fee_amount`, `fee_percent`, `original_amount` on every payment, plus
`refund_amount` / `refund_reference` / `void_reference` on those events. Your
own keys survive alongside them, but reusing any of those six names means
your value is silently replaced.

Full detail in [docs/troubleshooting.md](docs/troubleshooting.md) and
[docs/sandbox-testing.md](docs/sandbox-testing.md).

---

## Requirements

- PHP **8.2+**
- A PSR-18 HTTP client + PSR-17 factories. The factory falls back to Guzzle if
  installed.
- Laravel **10+** if you want the bridge.

## Install

Not yet published to Packagist, so add the repository explicitly:

```json
{
    "repositories": [
        { "type": "vcs", "url": "https://github.com/AliAgela-dev/dpay-php.git" }
    ],
    "require": {
        "aliagela-dev/dpay-php": "^0.2"
    }
}
```

```bash
composer require aliagela-dev/dpay-php
```

MIT licensed — see [LICENSE](LICENSE). Contributions welcome:
[CONTRIBUTING.md](CONTRIBUTING.md). Security issues go through
[SECURITY.md](SECURITY.md), not public issues.

---

## Quick start — pure PHP

```php
use DPay\Client\DPayClient;
use DPay\Client\DPayClientFactory;
use DPay\Config\DPayConfig;
use DPay\Dto\OpenSessionRequest;
use DPay\GatewayManager;
use DPay\Providers\EdfaliProvider;
use DPay\Providers\MoamalatProvider;

$config = new DPayConfig(
    baseUrl: 'https://dpay.ly/api',
    apiKey: getenv('DPAY_API_KEY'),
    timeout: 15,
    mock: false,
    minAmount: 0.01,
);

$client = DPayClientFactory::create($config);   // Guzzle-backed by default

// A) Talk to DPay directly:
$session = $client->openSession(new OpenSessionRequest(
    payMethod: 'edfali',
    amount: 50.0,
    customerMobile: '0911234567',
));
$verify  = $client->verifySession($session->sessionId, '1234');

if ($verify?->isPaid()) {
    // payment settled
}

// B) Use the provider abstraction (recommended for multi-method checkout):
$gateways = (new GatewayManager())
    ->register(new EdfaliProvider($client, payMethod: 'edfali'))
    ->register(new MoamalatProvider($client, payMethod: 'moamalat'));

$reference = $gateways->provider('edfali')
    ->sendOtp(50, ['phone_number' => '0911234567']);

$paid = $gateways->provider('edfali')->verifyOtp($reference, '1234');
```

---

## Quick start — Laravel

The package auto-registers `DPay\Laravel\DPayServiceProvider` via composer
discovery and aliases the `DPay` facade.

```bash
php artisan vendor:publish --tag=dpay-config
php artisan vendor:publish --tag=dpay-logos
```

```env
DPAY_BASE_URL=https://dpay.ly/api
DPAY_API_KEY=your-key
DPAY_TIMEOUT=15
DPAY_MOCK=true
DPAY_MIN_AMOUNT=0.01

PAYMENT_GATEWAY_EDFALI_ENABLED=true
PAYMENT_GATEWAY_MOBICASH_ENABLED=true
# ... see config/dpay.php for the full list
```

```php
use DPay\Dto\OpenSessionRequest;
use DPay\Laravel\Facades\DPay;

$reference = DPay::provider('edfali')->sendOtp(50, ['phone_number' => '0911234567']);
$paid      = DPay::provider('edfali')->verifyOtp($reference, '1234');

// Lower-level access to the client:
$session = DPay::openSession(new OpenSessionRequest(payMethod: 'moamalat', amount: 50.0));
$status  = DPay::getSession($session->sessionId);
```

## Documentation

| Doc | Read it when |
|---|---|
| [docs/checkout-flow.md](docs/checkout-flow.md) | You're wiring up checkout — controllers, persistence, polling Moamalat, edge cases. |
| [docs/webhooks.md](docs/webhooks.md) | You'd rather DPay push payment status to you than poll for it — signature verification, the 6 events, opt-in Laravel setup. |
| [docs/providers.md](docs/providers.md) | You need exact field schemas, request/response shapes, and per-provider gotchas. |
| [docs/extending.md](docs/extending.md) | You want to plug in your own Wallet/store-credit provider, or add a new DPay `pay_method`. |
| [docs/troubleshooting.md](docs/troubleshooting.md) | Something broke — what does this exception mean, what to check. |
| [docs/dto-reference.md](docs/dto-reference.md) | You want every field of every response object in one place. |
| [docs/configuration.md](docs/configuration.md) | Full env-var + config-key reference, with the `required_fields` override format. |
| [docs/sandbox-testing.md](docs/sandbox-testing.md) | You have sandbox creds and want to run the live probe, or need the sandbox test-input cheat sheet. |
| [UPGRADING.md](UPGRADING.md) | You're coming from `0.1.0` — every breaking change in `0.2.0` and the exact code edit each one needs. |

---

## Providers

| Code | Class | `requiresOtp` | `supportsStatusCheck` | Fields consumed |
|---|---|---|---|---|
| `edfali`     | `EdfaliProvider`     | true  | false | `phone_number` |
| `mobicash`   | `MobiCashProvider`   | true  | false | `card_number`  |
| `saharapay`  | `SaharaPayProvider`  | true  | **true** | `card_number` |
| `yousrpay`   | `YousrPayProvider`   | true  | **true** | `card_number` |
| `masrefypay` | `MasrefyPayProvider` | true  | **true** | `card_number` |
| `sadad`      | `SadadProvider`      | true  | false | `phone_number`, `birth_year`, `category` (optional) |
| `moamalat`   | `MoamalatProvider`   | **false** | true | (none — payment-link flow) |

> **Sadad ships disabled by default** (`PAYMENT_GATEWAY_SADAD_ENABLED=false`) —
> it's merchant-gated on DPay's side, not missing SDK support. Confirm with
> DPay that Sadad is enabled on your merchant account before flipping it on.
> **Yaser** is not shipped — it does not appear in the official DPay spec at
> all.

Every concrete provider sets its identity (`code`, `displayName`, `logo`) and
which fields it pulls from the `$fields` array passed to `sendOtp()`. The
shared `AbstractDPayProvider` handles the HTTP calls; `MoamalatProvider`
overrides because it uses status-polling instead of OTP.

---

## Listing providers for your frontend

`GatewayManager::describe()` returns JSON-ready metadata for every enabled
provider — code, name, logo, capability flags, and the `required_fields`
schema (with regex / digit count / en+ar labels). Drop this into a
controller and your frontend has everything it needs to render the
checkout selector and validate inputs inline.

```php
use DPay\Laravel\Facades\DPay;
use DPay\GatewayManager;

return response()->json(app(GatewayManager::class)->describe());
```

Sample shape:

```json
[
  {
    "code": "edfali",
    "name": "Edfali",
    "logo": "images/payment-methods/edfali.svg",
    "requires_otp": true,
    "supports_status_check": false,
    "supports_refund": false,
    "supports_webhook": true,
    "required_fields": [
      {
        "key": "phone_number",
        "type": "string",
        "required": true,
        "regex": "/^09\\d{8}$/",
        "digits": null,
        "labels": { "en": "Phone Number", "ar": "رقم الهاتف" },
        "placeholders": { "en": "09xxxxxxxx", "ar": "09xxxxxxxx" },
        "input_type": "tel"
      }
    ]
  }
]
```

For Laravel server-side validation against the same schema:

```php
use DPay\Laravel\PaymentFieldRules;

$provider = DPay::provider($request->method);
$request->validate(
    PaymentFieldRules::for($provider, prefix: 'fields'),
    attributes: PaymentFieldRules::attributesFor($provider, app()->getLocale()),
);
```

---

## Mock mode

Set `mock: true` (or `DPAY_MOCK=true` in Laravel) and the client bypasses HTTP
entirely:

- `openSession` returns a synthetic session with a random `session_id`
  (1–99999) and a session lifetime of 10 minutes for `moamalat`/`sadad`,
  15 minutes for everything else — matching DPay's documented expiries.
- `verifySession` accepts **any 4–6 digit numeric OTP except `000000`**,
  which simulates a decline (mirrors the sandbox). A matching OTP returns
  `paid`; `000000` or a non-numeric/wrong-length value returns `null`.
- `getSession` returns `paid` for any id.

Useful for local dev and for the test suite — same behavior as the real
DPay sandbox for these cases.

---

## Exception hierarchy

All HTTP-level errors extend `DPay\Exceptions\DPayException`:

| Exception | When |
|---|---|
| `DPayValidationException` | 4xx (fractional amount, below min, DPay 422, etc.) |
| `DPayAuthException`       | 401 / 403 (missing or invalid API key) |
| `DPaySessionNotFoundException` | 404 from `getSession` |
| `DPayNetworkException`    | PSR-18 transport failure (DNS, connect, timeout) |

`verifySession` returns `null` (not throws) for wrong OTP / expired session, so
provider `verifyOtp()` calls return `false` for normal user errors.

`UnknownProviderException` is thrown by `GatewayManager` when the requested
code isn't registered, or is registered but disabled.

---

## Testing

```bash
composer install
vendor/bin/phpunit
```

191 tests, 396 assertions. Unit tests use a fake PSR-18 client; the Laravel
feature test uses Orchestra Testbench.

---

## Project layout

```
src/
├── Client/         DPayClient, interface, factory
├── Config/         DPayConfig value object
├── Contracts/      PaymentProviderInterface
├── Dto/            OpenSession/VerifySession/GetSession DTOs, SessionStatus enum
├── Exceptions/     DPayException hierarchy
├── GatewayManager.php
├── Providers/      AbstractDPayProvider + 8 concrete providers
├── Support/        MockTransport
└── Laravel/        ServiceProvider, Facade, config/dpay.php (optional bridge)
resources/logos/    SVG assets (published to public/vendor/dpay/)
tests/              Unit + Laravel feature tests
```
