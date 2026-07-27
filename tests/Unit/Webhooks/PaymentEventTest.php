<?php

declare(strict_types=1);

namespace DPay\Tests\Unit\Webhooks;

use DPay\Dto\SessionStatus;
use DPay\Webhooks\PaymentEvent;
use DPay\Webhooks\WebhookEventType;
use PHPUnit\Framework\TestCase;

final class PaymentEventTest extends TestCase
{
    public function test_parses_a_paid_event(): void
    {
        $event = PaymentEvent::fromArray(json_decode(<<<'JSON'
        {
          "event": "payment.paid",
          "live": true,
          "session_id": 42,
          "status": "paid",
          "amount": 100,
          "pay_method": "edfali",
          "tx_id": "txn_abc123",
          "system_reference": "SYS-12345",
          "network_reference": "NET-67890",
          "paid_through": null,
          "payer_account": "218XXXX1234",
          "data": { "order_id": "ORD-001", "customer_id": "cust_42" },
          "created_at": "2026-04-22T10:15:00+00:00",
          "paid_at": "2026-04-22T10:16:30+00:00"
        }
        JSON, true));

        self::assertSame(WebhookEventType::PAID, $event->eventType());
        self::assertTrue($event->live);
        self::assertSame(42, $event->sessionId);
        self::assertSame(SessionStatus::PAID, $event->status);
        self::assertSame(100.0, $event->amount);
        self::assertSame('edfali', $event->payMethod);
        self::assertSame('txn_abc123', $event->txId);
        self::assertSame('SYS-12345', $event->systemReference);
        self::assertSame('NET-67890', $event->networkReference);
        self::assertNull($event->paidThrough);
        self::assertSame('218XXXX1234', $event->payerAccount);
        self::assertSame(['order_id' => 'ORD-001', 'customer_id' => 'cust_42'], $event->data);
        self::assertSame('2026-04-22T10:15:00+00:00', $event->createdAt);
        self::assertSame('2026-04-22T10:16:30+00:00', $event->paidAt);
    }

    public function test_parses_a_failed_event_with_mostly_null_reference_fields(): void
    {
        $event = PaymentEvent::fromArray(json_decode(<<<'JSON'
        {
          "event": "payment.failed",
          "live": true,
          "session_id": 42,
          "status": "failed",
          "amount": 100,
          "pay_method": "mpgs",
          "tx_id": "txn_abc123",
          "system_reference": null,
          "network_reference": null,
          "paid_through": null,
          "payer_account": null,
          "data": { "order_id": "ORD-001", "gateway_code": "DECLINED", "acquirer_message": "Do not honor" },
          "created_at": "2026-04-22T10:15:00+00:00",
          "paid_at": "2026-04-22T10:15:45+00:00"
        }
        JSON, true));

        self::assertSame(WebhookEventType::FAILED, $event->eventType());
        self::assertSame(SessionStatus::FAILED, $event->status);
        // pay_method "mpgs" is not a pay_method this SDK ships a provider
        // for — it must still parse as a plain string, not throw.
        self::assertSame('mpgs', $event->payMethod);
        self::assertNull($event->systemReference);
        self::assertNull($event->networkReference);
        self::assertNull($event->paidThrough);
        self::assertNull($event->payerAccount);
        self::assertSame('DECLINED', $event->data['gateway_code']);
    }

    public function test_parses_an_expired_event(): void
    {
        $event = PaymentEvent::fromArray(json_decode(<<<'JSON'
        {
          "event": "payment.expired",
          "live": true,
          "session_id": 42,
          "status": "expired",
          "amount": 100,
          "pay_method": "edfali",
          "tx_id": "txn_abc123",
          "system_reference": null,
          "network_reference": null,
          "paid_through": null,
          "payer_account": null,
          "data": { "order_id": "ORD-001" },
          "created_at": "2026-04-22T10:15:00+00:00",
          "paid_at": "2026-04-22T10:45:00+00:00"
        }
        JSON, true));

        self::assertSame(WebhookEventType::EXPIRED, $event->eventType());
        self::assertSame(SessionStatus::EXPIRED, $event->status);
    }

    public function test_parses_a_refunded_event_with_populated_reference_fields(): void
    {
        $event = PaymentEvent::fromArray(json_decode(<<<'JSON'
        {
          "event": "payment.refunded",
          "live": true,
          "session_id": 42,
          "status": "refunded",
          "amount": 100,
          "pay_method": "moamalat",
          "tx_id": "txn_abc123",
          "system_reference": "SYS-12345",
          "network_reference": "NET-67890",
          "paid_through": "Visa",
          "payer_account": "****1234",
          "data": { "order_id": "ORD-001", "refund_amount": 100, "refund_reference": "RFD-99881" },
          "created_at": "2026-04-20T09:00:00+00:00",
          "paid_at": "2026-04-22T11:30:00+00:00"
        }
        JSON, true));

        self::assertSame(WebhookEventType::REFUNDED, $event->eventType());
        self::assertSame(SessionStatus::REFUNDED, $event->status);
        self::assertSame('Visa', $event->paidThrough);
        self::assertSame('RFD-99881', $event->data['refund_reference']);
    }

    public function test_parses_a_voided_event(): void
    {
        $event = PaymentEvent::fromArray(json_decode(<<<'JSON'
        {
          "event": "payment.voided",
          "live": true,
          "session_id": 42,
          "status": "voided",
          "amount": 100,
          "pay_method": "moamalat",
          "tx_id": "txn_abc123",
          "system_reference": "SYS-12345",
          "network_reference": "NET-67890",
          "paid_through": "Visa",
          "payer_account": "****1234",
          "data": { "order_id": "ORD-001", "void_reference": "VOID-99882" },
          "created_at": "2026-04-22T10:15:00+00:00",
          "paid_at": "2026-04-22T10:20:00+00:00"
        }
        JSON, true));

        self::assertSame(WebhookEventType::VOIDED, $event->eventType());
        // VOIDED must map through SessionStatus, not degrade to UNKNOWN —
        // this is exactly the case a prior plan added SessionStatus::VOIDED for.
        self::assertSame(SessionStatus::VOIDED, $event->status);
        self::assertTrue($event->status->isTerminal());
    }

    public function test_parses_a_sandbox_paid_event_with_live_false(): void
    {
        $event = PaymentEvent::fromArray(json_decode(<<<'JSON'
        {
          "event": "payment.paid",
          "live": false,
          "session_id": 42,
          "status": "paid",
          "amount": 100,
          "pay_method": "edfali",
          "tx_id": "sb_txn_abc123",
          "system_reference": null,
          "network_reference": null,
          "paid_through": null,
          "payer_account": "218XXXX1234",
          "data": { "order_id": "ORD-001", "customer_id": "cust_42" },
          "created_at": "2026-04-22T10:15:00+00:00",
          "paid_at": "2026-04-22T10:16:30+00:00"
        }
        JSON, true));

        self::assertFalse($event->live);
        self::assertSame('sb_txn_abc123', $event->txId);
    }

    public function test_raw_preserves_the_full_undecoded_payload(): void
    {
        $body = json_decode(<<<'JSON'
        {
          "event": "payment.paid", "live": true, "session_id": 42, "status": "paid",
          "amount": 100, "pay_method": "edfali", "tx_id": "txn_abc123",
          "system_reference": null, "network_reference": null, "paid_through": null,
          "payer_account": null, "data": null,
          "created_at": "2026-04-22T10:15:00+00:00", "paid_at": "2026-04-22T10:16:30+00:00"
        }
        JSON, true);

        $event = PaymentEvent::fromArray($body);

        self::assertSame($body, $event->toArray());
    }

    public function test_a_null_data_field_becomes_an_empty_array_not_null(): void
    {
        $event = PaymentEvent::fromArray([
            'event' => 'payment.paid', 'session_id' => 1, 'status' => 'paid',
            'amount' => 10, 'pay_method' => 'edfali', 'tx_id' => 'x',
            'data' => null,
        ]);

        self::assertSame([], $event->data);
    }
}
