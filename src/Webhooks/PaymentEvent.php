<?php

declare(strict_types=1);

namespace DPay\Webhooks;

use DPay\Dto\SessionStatus;

/**
 * Payload shape shared by the 5 payment.* webhook events: paid, failed,
 * expired, refunded, voided. See TestEvent for webhook.test's distinct
 * shape (it has no session_id at all).
 *
 * pay_method here is NOT restricted to this SDK's shipped providers — DPay
 * can send any gateway string, including ones without a provider class
 * (e.g. "mpgs"). It stays a plain string for exactly that reason.
 */
final class PaymentEvent implements WebhookEventInterface
{
    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly WebhookEventType $event,
        public readonly bool $live,
        public readonly int $sessionId,
        public readonly SessionStatus $status,
        public readonly float $amount,
        public readonly string $payMethod,
        public readonly string $txId,
        public readonly ?string $systemReference,
        public readonly ?string $networkReference,
        public readonly ?string $paidThrough,
        public readonly ?string $payerAccount,
        public readonly array $data,
        public readonly string $createdAt,
        public readonly string $paidAt,
        public readonly array $raw = [],
    ) {}

    /**
     * @param  array<string, mixed>  $body
     */
    public static function fromArray(array $body): self
    {
        return new self(
            event: WebhookEventType::fromString(self::toNullableString($body['event'] ?? null)),
            live: (bool) ($body['live'] ?? true),
            sessionId: (int) ($body['session_id'] ?? 0),
            status: SessionStatus::fromString(self::toNullableString($body['status'] ?? null)),
            amount: (float) ($body['amount'] ?? 0),
            payMethod: self::toStringOrDefault($body['pay_method'] ?? ''),
            txId: self::toStringOrDefault($body['tx_id'] ?? ''),
            systemReference: self::toNullableString($body['system_reference'] ?? null),
            networkReference: self::toNullableString($body['network_reference'] ?? null),
            paidThrough: self::toNullableString($body['paid_through'] ?? null),
            payerAccount: self::toNullableString($body['payer_account'] ?? null),
            data: is_array($body['data'] ?? null) ? $body['data'] : [],
            createdAt: self::toStringOrDefault($body['created_at'] ?? ''),
            paidAt: self::toStringOrDefault($body['paid_at'] ?? ''),
            raw: $body,
        );
    }

    /**
     * Coerce a JSON-decoded value to a string, never triggering PHP's
     * "Array to string conversion" warning. json_decode() can hand back
     * anything for a field DPay documents as a string — an attacker
     * controls the webhook body, and a warning here becomes an uncaught
     * ErrorException under Laravel's default exception handling. Treat
     * anything non-scalar as absent rather than crashing the receiver.
     */
    private static function toStringOrDefault(mixed $value, string $default = ''): string
    {
        return is_scalar($value) ? (string) $value : $default;
    }

    private static function toNullableString(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }

    public function eventType(): WebhookEventType
    {
        return $this->event;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->raw !== [] ? $this->raw : [
            'event' => $this->event->value,
            'live' => $this->live,
            'session_id' => $this->sessionId,
            'status' => $this->status->value,
            'amount' => $this->amount,
            'pay_method' => $this->payMethod,
            'tx_id' => $this->txId,
            'system_reference' => $this->systemReference,
            'network_reference' => $this->networkReference,
            'paid_through' => $this->paidThrough,
            'payer_account' => $this->payerAccount,
            'data' => $this->data,
            'created_at' => $this->createdAt,
            'paid_at' => $this->paidAt,
        ];
    }
}
