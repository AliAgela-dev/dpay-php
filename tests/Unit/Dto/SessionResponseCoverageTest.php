<?php

declare(strict_types=1);

namespace DPay\Tests\Unit\Dto;

use DPay\Dto\GetSessionResponse;
use DPay\Dto\Payment;
use DPay\Dto\VerifySessionResponse;
use PHPUnit\Framework\TestCase;

/**
 * Fields these two DTOs were dropping.
 *
 * Found by diffing the mapped properties against the official spec examples
 * and against payloads captured live on 2026-08-16. Everything here was
 * previously reachable only through ->raw, which meant the typed API was
 * quietly less useful than the untyped one.
 */
final class SessionResponseCoverageTest extends TestCase
{
    /**
     * Verbatim from "Verify Session (Success)" in the Postman collection.
     *
     * @return array<string, mixed>
     */
    private function verifyBody(): array
    {
        return [
            'message' => 'Payment verified successfully',
            'payment' => [
                'id' => 101,
                'payment_session_id' => 1234,
                'amount' => 100,
                'currency' => 'LYD',
                'status' => 'completed',
                'pay_method' => 'moamalat',
                'tx_id' => 'txn_abc123',
                'system_reference' => 'SYS-99881',
                'network_reference' => 'NET-66022',
                'paid_through' => 'Visa',
                'payer_account' => '****1234',
                'created_at' => '2026-04-23T10:15:30+00:00',
            ],
            'payment_id' => 101,
            'status' => 'paid',
            'amount' => 100,
            'pay_method' => 'moamalat',
            'tx_id' => 'txn_abc123',
            'receipt_url' => 'https://dpay.ly/receipt/1234/abc...',
        ];
    }

    public function test_verify_maps_the_receipt_url(): void
    {
        self::assertSame(
            'https://dpay.ly/receipt/1234/abc...',
            VerifySessionResponse::fromArray($this->verifyBody())->receiptUrl,
        );
    }

    public function test_verify_maps_the_nested_payment_object(): void
    {
        $payment = VerifySessionResponse::fromArray($this->verifyBody())->payment;

        self::assertInstanceOf(Payment::class, $payment);
        self::assertSame('SYS-99881', $payment->systemReference);
        self::assertSame('Visa', $payment->paidThrough);
    }

    public function test_verify_has_no_payment_object_when_dpay_omits_one(): void
    {
        $body = $this->verifyBody();
        unset($body['payment']);

        self::assertNull(VerifySessionResponse::fromArray($body)->payment);
    }

    /**
     * DPay does not return `currency` at the top level of a verify response —
     * it lives inside the nested payment object. The DTO used to fall back to
     * a hardcoded 'LYD', presenting an SDK guess as gateway data.
     */
    public function test_verify_takes_currency_from_the_nested_payment_when_present(): void
    {
        $body = $this->verifyBody();
        $body['payment']['currency'] = 'USD';

        self::assertSame('USD', VerifySessionResponse::fromArray($body)->currency);
    }

    public function test_verify_still_defaults_currency_when_nothing_supplies_it(): void
    {
        self::assertSame('LYD', VerifySessionResponse::fromArray(['status' => 'paid'])->currency);
    }

    /**
     * Captured live on 2026-08-16 from GET /payment/sessions/1606. Note it
     * carries tx_id and payment_link, neither of which appears in the spec's
     * minimal example — and neither of which the DTO mapped.
     */
    public function test_get_session_maps_tx_id_and_payment_link_from_a_real_payload(): void
    {
        $response = GetSessionResponse::fromArray([
            'session_id' => 1606,
            'status' => 'paid',
            'amount' => 11,
            'pay_method' => 'moamalat',
            'tx_id' => 'sb_txn_ce0a4825128e67538e678fc3b6b31357',
            'expired_at' => '2026-08-16T19:04:50.000000Z',
            'data' => ['fee_amount' => 0.02, 'original_amount' => 10.5],
            'sandbox' => true,
            'payment_link' => 'https://dpay.ly/sandbox/moamalat-pay/1606/8013df9',
        ]);

        // tx_id is the reconciliation reference; payment_link is how the
        // Moamalat flow is resumed.
        self::assertSame('sb_txn_ce0a4825128e67538e678fc3b6b31357', $response->txId);
        self::assertSame('https://dpay.ly/sandbox/moamalat-pay/1606/8013df9', $response->paymentLink);
    }

    public function test_get_session_leaves_them_empty_when_absent(): void
    {
        // The spec's own minimal example has neither field.
        $response = GetSessionResponse::fromArray([
            'session_id' => 42,
            'status' => 'paid',
            'amount' => 100,
            'pay_method' => 'edfali',
            'expired_at' => '2026-01-15T12:30:00.000000Z',
            'data' => null,
        ]);

        self::assertSame('', $response->txId);
        self::assertNull($response->paymentLink);
    }
}
