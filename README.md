# dpay-php

Framework-agnostic PHP SDK for the **DPay** payment gateway (Libya), with
provider abstractions for Edfali, MobiCash, SaharaPay, YousrPay,
MasrefyPay, and Moamalat. Ships an optional Laravel bridge.

Reverse-engineered from the production `health-portal` implementation. **The
field names and endpoint paths are believed correct but have not been validated
against an official DPay spec.** Run against DPay's sandbox and adjust before
flipping `mock => false` in production.

---

## Requirements

- PHP **8.2+**
- A PSR-18 HTTP client + PSR-17 factories. The factory falls back to Guzzle if
  installed.
- Laravel **10+** if you want the bridge.

## Install

This is a private package — wire it in via a Composer VCS repository:

```json
{
    "repositories": [
        { "type": "vcs", "url": "git@github.com:you/dpay-php.git" }
    ],
    "require": {
        "ali/dpay-php": "dev-main"
    }
}
```

```bash
composer require ali/dpay-php
```

---

## Quick start — pure PHP

```php
use DPay\Client\DPayClient;
use DPay\Client\DPayClientFactory;
use DPay\Config\DPayConfig;
use DPay\GatewayManager;
use DPay\Providers\EdfaliProvider;
use DPay\Providers\MoamalatProvider;

$config = new DPayConfig(
    baseUrl: 'https://dpay.ly/api',
    apiKey: getenv('DPAY_API_KEY'),
    timeout: 15,
    mock: false,
    minAmount: 5,
);

$client = DPayClientFactory::create($config);   // Guzzle-backed by default

// A) Talk to DPay directly:
$session = $client->openSession('edfali', 50, customerMobile: '0911234567');
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
DPAY_MIN_AMOUNT=5

PAYMENT_GATEWAY_EDFALI_ENABLED=true
PAYMENT_GATEWAY_MOBICASH_ENABLED=true
# ... see config/dpay.php for the full list
```

```php
use DPay\Laravel\Facades\DPay;

$reference = DPay::provider('edfali')->sendOtp(50, ['phone_number' => '0911234567']);
$paid      = DPay::provider('edfali')->verifyOtp($reference, '1234');

// Lower-level access to the client:
$session = DPay::openSession('moamalat', 50);
$status  = DPay::getSession($session->sessionId);
```

## Documentation

| Doc | Read it when |
|---|---|
| [docs/checkout-flow.md](docs/checkout-flow.md) | You're wiring up checkout — controllers, persistence, polling Moamalat, edge cases. |
| [docs/providers.md](docs/providers.md) | You need exact field schemas, request/response shapes, and per-provider gotchas. |
| [docs/extending.md](docs/extending.md) | You want to plug in your own Wallet/store-credit provider, or add a new DPay `pay_method`. |
| [docs/troubleshooting.md](docs/troubleshooting.md) | Something broke — what does this exception mean, what to check. |
| [docs/dto-reference.md](docs/dto-reference.md) | You want every field of every response object in one place. |
| [docs/configuration.md](docs/configuration.md) | Full env-var + config-key reference, with the `required_fields` override format. |
| [docs/sandbox-testing.md](docs/sandbox-testing.md) | _(Stub)_ How to go from mock to live once DPay sandbox creds arrive. |

---

## Providers

| Code | Class | `requiresOtp` | `supportsStatusCheck` | Fields consumed |
|---|---|---|---|---|
| `edfali`     | `EdfaliProvider`     | true  | false | `phone_number` |
| `mobicash`   | `MobiCashProvider`   | true  | false | `card_number`  |
| `saharapay`  | `SaharaPayProvider`  | true  | **true** | `card_number` |
| `yousrpay`   | `YousrPayProvider`   | true  | **true** | `card_number` |
| `masrefypay` | `MasrefyPayProvider` | true  | **true** | `card_number` |
| `moamalat`   | `MoamalatProvider`   | **false** | true | (none — payment-link flow) |

> **Sadad / Yaser** are not shipped — DPay's sandbox doesn't enable them
> for our merchant (returns `500 "Unsupported payment method"`). They'll
> be added back when DPay enables them. To re-add manually if your
> tenant supports them, see [docs/extending.md](docs/extending.md).

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
    "supports_webhook": false,
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

- `openSession` returns a synthetic session with a random `session_id` (1–99999)
- `verifySession` accepts **any 4–6 digit numeric OTP** and returns `paid`
- `getSession` returns `paid` for any id

Useful for local dev and for the test suite — same behavior as the original
health-portal mock.

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

## Migrating from the health-portal Laravel implementation

The package mirrors the existing structure closely, so a host project can
adopt it in a few steps:

1. `composer require ali/dpay-php`
2. Replace `config/payment.php` with the published `config/dpay.php` (same
   env-var names; just `payment.*` → `dpay.*`).
3. Delete `app/Services/Payment/Providers/{Edfali,MobiCash,Masrefypay,
   YousrPay,SaharaPay,Moamalat}/`. The package owns them. Keep
   `Sadad/` and `Yaser/` until DPay enables them — once they do, drop
   the local files and re-add via the package per
   [docs/extending.md](docs/extending.md).
4. Keep `WalletProvider` and `WalletClient` in your app — the wallet is an
   internal balance, not a DPay-backed method. Register it with the package's
   `GatewayManager` at boot:
   ```php
   $this->app->extend(GatewayManager::class, function (GatewayManager $m, $app) {
       return $m->register($app->make(\App\Services\Payment\Providers\Wallet\WalletProvider::class));
   });
   ```
5. Update imports: `App\Services\Payment\Contracts\PaymentProviderInterface`
   → `DPay\Contracts\PaymentProviderInterface`.
6. Replace `App\Services\Payment\PaymentGatewayManager` calls with
   `DPay\GatewayManager` (same method names).
7. Run your existing test suite.

The Action classes (`InitiateAppointmentPaymentAction`,
`VerifyWalletChargeAction`, …) stay in the host project — they encode business
flow (transactions, wallets, appointments) that doesn't belong in a payment SDK.

---

## Testing

```bash
composer install
vendor/bin/phpunit
```

33 tests, 99 assertions. Unit tests use a fake PSR-18 client; the Laravel
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
