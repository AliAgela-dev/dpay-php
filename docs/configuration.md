# Configuration reference

Every env var, config key, and constructor argument the SDK reads.

---

## Environment variables (Laravel bridge)

These are consumed by [`config/dpay.php`](../src/Laravel/config/dpay.php).
Set them in your `.env`.

### Gateway-wide

| Variable | Default | Required | Notes |
|---|---|---|---|
| `DPAY_BASE_URL` | `https://dpay.ly/api` | yes (in prod) | No trailing slash. Use the sandbox URL when DPay gives you one. |
| `DPAY_API_KEY` | _empty_ | yes (in prod) | Used as `Authorization: Bearer <key>`. |
| `DPAY_TIMEOUT` | `15` | no | Seconds. Bump if you see frequent `DPayNetworkException` timeouts. |
| `DPAY_MOCK` | `true` | no | When true, no HTTP traffic. **Set to false in staging+prod.** |
| `DPAY_MIN_AMOUNT` | `5` | no | LYD floor. Anything below throws `DPayValidationException` pre-flight. |

### Per-gateway enable

| Variable | Default | Notes |
|---|---|---|
| `PAYMENT_GATEWAY_EDFALI_ENABLED` | `true` | |
| `PAYMENT_GATEWAY_MOBICASH_ENABLED` | `true` | |
| `PAYMENT_GATEWAY_MASREFYPAY_ENABLED` | `true` | |
| `PAYMENT_GATEWAY_YOUSRPAY_ENABLED` | `true` | |
| `PAYMENT_GATEWAY_SAHARAPAY_ENABLED` | `true` | |
| `PAYMENT_GATEWAY_SADAD_ENABLED` | `false` | Merchant-gated — confirm with DPay before enabling. |
| `PAYMENT_GATEWAY_MOAMALAT_ENABLED` | `false` | Disabled by default — needs explicit opt-in because the flow is different (redirect, not OTP). |

### Per-gateway `pay_method` override

DPay's API uses string identifiers for each gateway (`edfali`, `mobicash`,
…). If DPay renames one tomorrow, override it via env without a redeploy:

| Variable | Default |
|---|---|
| `DPAY_PAY_METHOD_EDFALI` | `edfali` |
| `DPAY_PAY_METHOD_MOBICASH` | `mobicash` |
| `DPAY_PAY_METHOD_MASREFYPAY` | `masrefypay` |
| `DPAY_PAY_METHOD_YOUSRPAY` | `yousrpay` |
| `DPAY_PAY_METHOD_SAHARAPAY` | `saharapay` |
| `DPAY_PAY_METHOD_SADAD` | `sadad` |
| `DPAY_PAY_METHOD_MOAMALAT` | `moamalat` |

---

## `config/dpay.php` keys

After `php artisan vendor:publish --tag=dpay-config` you get a copy in
your app's `config/`.

```php
return [
    'base_url'   => env('DPAY_BASE_URL', 'https://dpay.ly/api'),
    'api_key'    => env('DPAY_API_KEY', ''),
    'timeout'    => (int) env('DPAY_TIMEOUT', 15),
    'mock'       => (bool) env('DPAY_MOCK', true),
    'min_amount' => (int) env('DPAY_MIN_AMOUNT', 5),

    'gateways' => [
        'edfali' => [
            'enabled'         => (bool) env('PAYMENT_GATEWAY_EDFALI_ENABLED', true),
            'provider'        => \DPay\Providers\EdfaliProvider::class,
            'pay_method'      => env('DPAY_PAY_METHOD_EDFALI', 'edfali'),
            'required_fields' => null,   // optional — see below
        ],
        // ... seven more gateways
    ],
];
```

### Per-gateway keys

| Key | Type | Notes |
|---|---|---|
| `enabled` | `bool` | If false, `GatewayManager` rejects resolution. |
| `provider` | `class-string<PaymentProviderInterface>` | The implementation class. |
| `pay_method` | `string` | DPay's request string. |
| `required_fields` | `null \| []  \| array<array>` | Optional override of the provider's default field schema. See next section. |

### `required_fields` override

Three valid shapes:

```php
// 1) Omit or null — use the provider's built-in default.
'required_fields' => null,

// 2) Empty array — clear all fields (provider sendOtp ignores $fields).
'required_fields' => [],

// 3) Custom schema — list of arrays in PaymentField::toArray() shape.
'required_fields' => [
    [
        'key'          => 'phone_number',
        'type'         => 'string',
        'required'     => true,
        'regex'        => '/^09[1-6]\d{7}$/',
        'digits'       => null,
        'labels'       => ['en' => 'Mobile', 'ar' => 'الجوال'],
        'placeholders' => ['en' => '091xxxxxxx', 'ar' => '091xxxxxxx'],
        'input_type'   => 'tel',
    ],
],
```

If you want to add brand-new fields, see [extending.md](extending.md).

---

## Constructor arguments (pure PHP)

Use these if you're not running Laravel.

### `DPayConfig`

```php
new DPayConfig(
    baseUrl: 'https://dpay.ly/api',  // string
    apiKey:  '...',                  // string
    timeout: 15,                     // int >= 1
    mock:    false,                  // bool
    minAmount: 5,                    // int >= 0
);
```

### `DPayClient`

```php
new DPayClient(
    config: $config,                       // DPayConfig
    httpClient: $psr18,                    // Psr\Http\Client\ClientInterface
    requestFactory: $psr17,                // Psr\Http\Message\RequestFactoryInterface
    streamFactory:  $psr17,                // Psr\Http\Message\StreamFactoryInterface
    logger:         $psr3,                 // Psr\Log\LoggerInterface, defaults to NullLogger
    mockTransport:  null,                  // DPay\Support\MockTransport — used when $config->mock = true
);
```

Or use the convenience factory (requires `guzzlehttp/guzzle`):

```php
DPayClientFactory::create($config);
```

### Concrete providers

Every concrete provider takes the same shape:

```php
new EdfaliProvider(
    client:    $dpayClient,                // DPayClientInterface
    payMethod: 'edfali',                   // string — must match DPay's pay_method
    enabled:   true,                       // bool
    requiredFields: null,                  // ?list<PaymentField> — null = built-in default
);
```

`MoamalatProvider` has the same signature.
