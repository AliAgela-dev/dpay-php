<?php

declare(strict_types=1);

use DPay\Client\DPayClientInterface;
use DPay\Dto\OpenSessionRequest;
use DPay\Exceptions\DPayAuthException;
use DPay\Exceptions\DPaySessionNotFoundException;
use DPay\Exceptions\DPayValidationException;

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

/**
 * Cross-cutting error-path scenarios.
 *
 * These assert which exception the SDK maps a real DPay failure onto, rather
 * than running a payment flow — the offline suite pins the mapping against a
 * fake HTTP client, and these prove the same mapping against real responses.
 *
 * `client` selects the credentials: 'default' uses the real token from
 * DPAY_API_KEY, 'bad-token' uses a deliberately invalid one.
 *
 * There is deliberately **no 429 scenario**. Tripping the rate limiter on
 * purpose would throttle the rest of the run for no new information —
 * ProbeRunner::paced() already exercises 429 backoff organically, since the
 * sandbox throttles at roughly four rapid calls.
 *
 * There is also no 500 scenario: nothing the SDK can send reliably forces a
 * server error, and asserting on one would make the probe flaky.
 *
 * @return array<string, array{
 *     client: 'default'|'bad-token',
 *     expect: class-string<\Throwable>,
 *     run: callable(DPayClientInterface): mixed,
 *     proves: string
 * }>
 */
function dpay_error_scenarios(): array
{
    return [
        'error-401-bad-token' => [
            'client' => 'bad-token',
            'expect' => DPayAuthException::class,
            'run' => static fn (DPayClientInterface $client) => $client->getSession(999999999),
            'proves' => 'an invalid token maps to DPayAuthException, not a generic failure',
        ],
        'error-404-unknown-session' => [
            'client' => 'default',
            'expect' => DPaySessionNotFoundException::class,
            'run' => static fn (DPayClientInterface $client) => $client->getSession(999999999),
            'proves' => '404 on an unknown session maps to DPaySessionNotFoundException',
        ],
        'error-422-invalid-pay-method' => [
            'client' => 'default',
            'expect' => DPayValidationException::class,
            'run' => static fn (DPayClientInterface $client) => $client->openSession(new OpenSessionRequest(
                payMethod: 'not-a-real-gateway',
                amount: 10.5,
                customerMobile: '0912345678',
            )),
            'proves' => 'an unknown pay_method maps to DPayValidationException',
        ],
    ];
}
