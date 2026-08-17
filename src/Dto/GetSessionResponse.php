<?php

declare(strict_types=1);

namespace DPay\Dto;

/**
 * Response from GET /payment/sessions/{sessionId}.
 *
 * Schema: session_id, status, amount, currency, pay_method, expired_at, data.
 * Used by Moamalat (and any other status-check / payment-link flow).
 */
final class GetSessionResponse
{
    public function __construct(
        public readonly int $sessionId,
        public readonly SessionStatus $status,
        public readonly float $amount,
        public readonly string $currency,
        public readonly string $payMethod,
        public readonly string $expiredAt,
        /**
         * The gateway transaction reference. Absent from the spec's minimal
         * example but present in every live response we captured, and it is
         * what you reconcile against.
         */
        public readonly string $txId = '',
        /**
         * Moamalat's hosted payment page. Present on a Moamalat session and
         * how that flow is resumed; null elsewhere.
         */
        public readonly ?string $paymentLink = null,
        public readonly mixed $data = null,
        /** @var array<string, mixed> */
        public readonly array $raw = [],
    ) {}

    /**
     * @param  array<string, mixed>  $body
     */
    public static function fromArray(array $body): self
    {
        return new self(
            sessionId: (int) ($body['session_id'] ?? 0),
            status: SessionStatus::fromString($body['status'] ?? null),
            amount: (float) ($body['amount'] ?? 0),
            currency: (string) ($body['currency'] ?? 'LYD'),
            payMethod: (string) ($body['pay_method'] ?? ''),
            expiredAt: (string) ($body['expired_at'] ?? ''),
            txId: isset($body['tx_id']) && is_scalar($body['tx_id']) ? (string) $body['tx_id'] : '',
            paymentLink: isset($body['payment_link']) && is_scalar($body['payment_link'])
                ? (string) $body['payment_link']
                : null,
            data: $body['data'] ?? null,
            raw: $body,
        );
    }

    public function isPaid(): bool
    {
        return $this->status === SessionStatus::PAID;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->raw !== [] ? $this->raw : [
            'session_id' => $this->sessionId,
            'status' => $this->status->value,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'pay_method' => $this->payMethod,
            'expired_at' => $this->expiredAt,
            'data' => $this->data,
        ];
    }
}
