<?php

declare(strict_types=1);

use DPay\Providers\EdfaliProvider;
use DPay\Providers\MasrefyPayProvider;
use DPay\Providers\MoamalatProvider;
use DPay\Providers\MobiCashProvider;
use DPay\Providers\SaharaPayProvider;
use DPay\Providers\YousrPayProvider;

return [
    /*
    |--------------------------------------------------------------------------
    | DPay Gateway (shared credentials for every DPay-backed provider)
    |--------------------------------------------------------------------------
    */
    'base_url' => env('DPAY_BASE_URL', 'https://dpay.ly/api'),
    'api_key' => env('DPAY_API_KEY', ''),
    'timeout' => (int) env('DPAY_TIMEOUT', 15),
    'mock' => (bool) env('DPAY_MOCK', true),
    'min_amount' => (int) env('DPAY_MIN_AMOUNT', 5),

    /*
    |--------------------------------------------------------------------------
    | Payment Gateways
    |--------------------------------------------------------------------------
    |
    | `provider`   — Provider class implementing PaymentProviderInterface.
    | `enabled`    — whether the gateway is live for clients.
    | `pay_method` — the exact string DPay's /payment/sessions/open expects in
    |                its `pay_method` field. Externalized so we can correct a
    |                mismatch without redeploying. Confirm each value against
    |                DPay's docs before flipping DPAY_MOCK=false in production.
    | `required_fields` (optional) — override the provider's default field
    |                schema. Omit (or set null) to keep defaults. Pass an
    |                empty array to clear all fields, or a list of arrays in
    |                PaymentField::toArray() shape to customize.
    |
    */
    'gateways' => [
        'edfali' => [
            'enabled' => (bool) env('PAYMENT_GATEWAY_EDFALI_ENABLED', true),
            'provider' => EdfaliProvider::class,
            'pay_method' => env('DPAY_PAY_METHOD_EDFALI', 'edfali'),
        ],
        'mobicash' => [
            'enabled' => (bool) env('PAYMENT_GATEWAY_MOBICASH_ENABLED', true),
            'provider' => MobiCashProvider::class,
            'pay_method' => env('DPAY_PAY_METHOD_MOBICASH', 'mobicash'),
        ],
        'masrefypay' => [
            'enabled' => (bool) env('PAYMENT_GATEWAY_MASREFYPAY_ENABLED', true),
            'provider' => MasrefyPayProvider::class,
            'pay_method' => env('DPAY_PAY_METHOD_MASREFYPAY', 'masrefypay'),
        ],
        'yousrpay' => [
            'enabled' => (bool) env('PAYMENT_GATEWAY_YOUSRPAY_ENABLED', true),
            'provider' => YousrPayProvider::class,
            'pay_method' => env('DPAY_PAY_METHOD_YOUSRPAY', 'yousrpay'),
        ],
        'saharapay' => [
            'enabled' => (bool) env('PAYMENT_GATEWAY_SAHARAPAY_ENABLED', true),
            'provider' => SaharaPayProvider::class,
            'pay_method' => env('DPAY_PAY_METHOD_SAHARAPAY', 'saharapay'),
        ],
        'moamalat' => [
            'enabled' => (bool) env('PAYMENT_GATEWAY_MOAMALAT_ENABLED', false),
            'provider' => MoamalatProvider::class,
            'pay_method' => env('DPAY_PAY_METHOD_MOAMALAT', 'moamalat'),
        ],
    ],
];
