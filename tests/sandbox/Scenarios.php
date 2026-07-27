<?php

declare(strict_types=1);

/**
 * Per-provider sandbox scenarios.
 *
 * Test data from the DPay dashboard. Fixed OTP 111111 across all gateways;
 * 000000 simulates a decline. No token appears here — it is read from
 * DPAY_API_KEY at runtime.
 *
 * @return array<string, array{pay_method:string, fields:array<string,mixed>, proves:string}>
 */
function dpay_scenarios(): array
{
    return [
        'edfali' => [
            'pay_method' => 'edfali',
            'fields' => ['phone_number' => '0912345678'],
            'proves' => 'decimal amount round-trip + Idempotency-Key replay',
        ],
        'mobicash' => [
            'pay_method' => 'mobicash',
            'fields' => ['card_number' => '7279627', 'description' => 'Order #1234'],
            'proves' => 'description lands top-level, not under data',
        ],
        'masrefypay' => [
            'pay_method' => 'masrefypay',
            'fields' => ['card_number' => '1234567'],
            'proves' => 'same-bank 7-digit card',
        ],
        'masrefypay-crossbank' => [
            'pay_method' => 'masrefypay',
            'fields' => ['card_number' => '111234567'],
            'proves' => '9-digit OnePay card is accepted',
        ],
        'yousrpay' => [
            'pay_method' => 'yousrpay',
            'fields' => ['card_number' => '1234567'],
            'proves' => 'same-bank 7-digit card',
        ],
        'yousrpay-crossbank' => [
            'pay_method' => 'yousrpay',
            'fields' => ['card_number' => '331234567'],
            'proves' => '9-digit OnePay card, prefix 33',
        ],
        'saharapay' => [
            'pay_method' => 'saharapay',
            'fields' => ['card_number' => '1234567'],
            'proves' => 'same-bank 7-digit card',
        ],
        'saharapay-crossbank' => [
            'pay_method' => 'saharapay',
            'fields' => ['card_number' => '661234567'],
            'proves' => '9-digit OnePay card, prefix 66',
        ],
        'moamalat' => [
            'pay_method' => 'moamalat',
            'fields' => [],
            'proves' => 'payment_link returned for the LightBox',
        ],
        'sadad' => [
            'pay_method' => 'sadad',
            'fields' => ['phone_number' => '0912345678', 'birth_year' => '1994', 'category' => 20],
            'proves' => 'BLOCKED: no published sandbox test wallet',
        ],
    ];
}
