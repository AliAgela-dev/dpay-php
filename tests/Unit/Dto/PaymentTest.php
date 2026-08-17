<?php

declare(strict_types=1);

namespace DPay\Tests\Unit\Dto;

use DPay\Dto\Payment;
use DPay\Dto\SessionStatus;
use PHPUnit\Framework\TestCase;

/**
 * The nested `payment` object on a verify response. This is where DPay puts
 * the card-rail reconciliation fields — system_reference, network_reference,
 * paid_through, payer_account — which were previously reachable only via
 * ->raw.
 */
final class PaymentTest extends TestCase
{
    /**
     * Verbatim from the official Postman collection's
     * "Verify Session (Success)" example.
     *
     * @return array<string, mixed>
     */
    private function goldenBody(): array
    {
        return [
            'id' => 101,
            'user_id' => 42,
            'company_id' => 7,
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
        ];
    }

    public function test_it_maps_every_documented_field(): void
    {
        $p = Payment::fromArray($this->goldenBody());

        self::assertSame(101, $p->id);
        self::assertSame(1234, $p->paymentSessionId);
        self::assertSame(100.0, $p->amount);
        self::assertSame('LYD', $p->currency);
        self::assertSame('moamalat', $p->payMethod);
        self::assertSame('txn_abc123', $p->txId);
        self::assertSame('2026-04-23T10:15:30+00:00', $p->createdAt);
    }

    public function test_it_maps_the_card_rail_reconciliation_fields(): void
    {
        $p = Payment::fromArray($this->goldenBody());

        self::assertSame('SYS-99881', $p->systemReference);
        self::assertSame('NET-66022', $p->networkReference);
        self::assertSame('Visa', $p->paidThrough);
        self::assertSame('****1234', $p->payerAccount);
    }

    public function test_the_reference_fields_are_null_when_absent(): void
    {
        // Exactly what every sandbox delivery looked like: wallet and bank
        // gateways don't populate the card-rail fields.
        $p = Payment::fromArray(['id' => 1, 'status' => 'completed']);

        self::assertNull($p->systemReference);
        self::assertNull($p->networkReference);
        self::assertNull($p->paidThrough);
        self::assertNull($p->payerAccount);
    }

    /**
     * The nested object's own `status` is "completed", which is NOT one of
     * the session lifecycle strings. It must degrade to UNKNOWN rather than
     * be mistaken for a session status.
     */
    public function test_the_nested_status_degrades_rather_than_being_misread(): void
    {
        self::assertSame(SessionStatus::UNKNOWN, Payment::fromArray($this->goldenBody())->status);
    }

    public function test_the_raw_object_is_preserved(): void
    {
        self::assertSame(42, Payment::fromArray($this->goldenBody())->raw['user_id']);
    }
}
