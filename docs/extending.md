# Extending — adding your own providers

There are two reasons you'd add a provider:

1. **Wire in a host-side provider** (a wallet, a store-credit balance,
   manual invoicing) that isn't backed by DPay at all but should appear
   in the same checkout listing.
2. **Add a new DPay-backed `pay_method`** (DPay supports a new gateway
   tomorrow and you don't want to wait for a package release).

Both come down to "implement `PaymentProviderInterface` and register it
with `GatewayManager`."

---

## Scenario 1 — a non-DPay provider (the Wallet pattern)

This is the case in the health-portal: `WalletProvider` manages an
internal balance, not a DPay session. The SDK doesn't ship it because
the SDK has no concept of users or balances.

Your host code (Laravel example):

```php
namespace App\Services\Payment\Providers\Wallet;

use DPay\Contracts\PaymentProviderInterface;
use DPay\Dto\PaymentField;

class WalletProvider implements PaymentProviderInterface
{
    public function __construct(private WalletClient $client) {}

    public function code(): string                { return 'wallet'; }
    public function displayName(): string         { return 'Wallet'; }
    public function logo(): string                { return 'images/payment-methods/wallet.svg'; }
    public function isEnabled(): bool             { return true; }
    public function requiresOtp(): bool           { return false; }
    public function supportsRefund(): bool        { return true;  } // your call
    public function supportsStatusCheck(): bool   { return true;  }
    public function supportsWebhook(): bool       { return false; }
    public function requiredFields(): array       { return []; }

    public function sendOtp(float $amount, array $fields): string
    {
        return $this->client->initiateCharge($amount);  // returns your internal reference
    }

    public function verifyOtp(string $reference, string $otp): bool
    {
        return $this->client->settle($reference);
    }
}
```

Then register it alongside the SDK's providers:

```php
// app/Providers/AppServiceProvider.php
use App\Services\Payment\Providers\Wallet\WalletProvider;
use DPay\GatewayManager;

public function boot(): void
{
    $this->app->extend(GatewayManager::class, function (GatewayManager $m, $app) {
        return $m->register($app->make(WalletProvider::class));
    });
}
```

Now `DPay::listEnabled()` returns wallet alongside Edfali/MobiCash/etc.,
and `DPay::provider('wallet')` resolves to your class.

---

## Scenario 2 — a new DPay `pay_method`

DPay adds support for a new gateway called "Hatif." All you need is:

```php
namespace App\Payment\Providers;

use DPay\Dto\PaymentField;
use DPay\Providers\AbstractDPayProvider;

final class HatifProvider extends AbstractDPayProvider
{
    public function code(): string        { return 'hatif'; }
    public function displayName(): string { return 'Hatif'; }
    public function logo(): string        { return 'images/payment-methods/hatif.svg'; }

    protected function defaultFields(): array
    {
        return [PaymentField::phoneNumber()];  // or cardNumber, or whatever Hatif needs
    }
}
```

Add to `config/dpay.php`:

```php
'gateways' => [
    // ... existing ones
    'hatif' => [
        'enabled' => env('PAYMENT_GATEWAY_HATIF_ENABLED', true),
        'provider' => \App\Payment\Providers\HatifProvider::class,
        'pay_method' => env('DPAY_PAY_METHOD_HATIF', 'hatif'),
    ],
],
```

That's it — the `DPayServiceProvider` boots it like any other provider.

If Hatif uses status-poll instead of OTP, override
`requiresOtp()` (return `false`) and `verifyOtp()` (call `getSession`)
like `MoamalatProvider` does — but in most cases extending
`AbstractDPayProvider` and just declaring `defaultFields()` is enough.

> **Sadad is a real, shipped example of this** — see
> `src/Providers/SadadProvider.php`. It needs `birth_year` and `category`
> alongside `phone_number`; no base-class changes were needed, because
> `AbstractDPayProvider::sendOtp()` maps every declared field by its
> `PaymentField::wireName()`, not a hardcoded key list.

---

## Scenario 3 — same DPay provider, different field schema per tenant

You don't need a new class. Use the **constructor override** (pure PHP)
or the **`required_fields` config key** (Laravel):

```php
// Pure PHP
new EdfaliProvider($client, 'edfali', requiredFields: [
    new PaymentField(
        key: 'phone_number',
        regex: '/^09[1-6]\d{7}$/',                       // tighter regex
        labels: ['en' => 'Mobile', 'ar' => 'الجوال'],
    ),
]);

// Laravel — config/dpay.php
'edfali' => [
    'provider' => EdfaliProvider::class,
    'pay_method' => 'edfali',
    'required_fields' => [[
        'key' => 'phone_number',
        'regex' => '/^09[1-6]\d{7}$/',
        'labels' => ['en' => 'Mobile', 'ar' => 'الجوال'],
        'input_type' => 'tel',
    ]],
],
```

---

## What you can't / shouldn't change

- **The `sendOtp`/`verifyOtp` signature.** That's the contract the
  `PaymentService` / your controllers depend on.
- **The reference being a string.** DPay's session_id is numeric, but the
  SDK stringifies it deliberately so wallet-style providers (which return
  UUIDs or hashes) work the same way.
- **The body-field mapping in `AbstractDPayProvider`.** Each declared
  field is forwarded under its `PaymentField::wireName()`, but only if
  that wire name is one `OpenSessionRequest` recognizes: `customer_mobile`,
  `card_number`, `birth_year`, `category`, or `description` (that set is
  what makes Sadad's `birth_year`/`category` possible with no base-class
  changes — see the Sadad callout above). If you need a totally new field
  name to reach DPay that isn't in that set, don't extend
  `AbstractDPayProvider` — talk to `DPayClient` directly in your
  provider's `sendOtp`, e.g. for an imagined `national_id` field.
