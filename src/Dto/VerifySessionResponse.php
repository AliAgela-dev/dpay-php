<?php

declare(strict_types=1);

namespace DPay\Dto;

/**
 * Response from POST /payment/sessions/verify on success.
 *
 * verifySession returns `null` (not this object) on bad OTP / expired session,
 * matching the original client behavior — callers can treat verification
 * as a boolean without catching exceptions for normal user errors.
 *
 * Schema: message, payment_id, status, amount, currency, pay_method, tx_id.
 */
final class VerifySessionResponse
{
    public function __construct(
        public readonly string $message,
        public readonly int $paymentId,
        public readonly SessionStatus $status,
        public readonly float $amount,
        public readonly string $currency,
        public readonly string $payMethod,
        public readonly string $txId,
        /** @var array<string, mixed> */
        public readonly array $raw = [],
    ) {}

    /**
     * @param  array<string, mixed>  $body
     */
    public static function fromArray(array $body): self
    {
        return new self(
            message: (string) ($body['message'] ?? ''),
            paymentId: (int) ($body['payment_id'] ?? 0),
            status: SessionStatus::fromString($body['status'] ?? null),
            amount: (float) ($body['amount'] ?? 0),
            currency: (string) ($body['currency'] ?? 'LYD'),
            payMethod: (string) ($body['pay_method'] ?? ''),
            txId: (string) ($body['tx_id'] ?? ''),
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
            'message' => $this->message,
            'payment_id' => $this->paymentId,
            'status' => $this->status->value,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'pay_method' => $this->payMethod,
            'tx_id' => $this->txId,
        ];
    }
}
