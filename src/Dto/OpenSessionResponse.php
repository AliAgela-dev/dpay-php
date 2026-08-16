<?php

declare(strict_types=1);

namespace DPay\Dto;

/**
 * Response from POST /payment/sessions/open.
 *
 * Schema mirrors the original client's array typedef:
 *   session_id, status, amount, currency, fee, fee_amount, total,
 *   pay_method, expired_at, data, payment_link (optional).
 */
final class OpenSessionResponse
{
    public function __construct(
        public readonly int $sessionId,
        public readonly SessionStatus $status,
        public readonly float $amount,
        public readonly string $currency,
        public readonly float $fee,
        public readonly float $feeAmount,
        public readonly float $total,
        public readonly string $payMethod,
        public readonly string $expiredAt,
        public readonly mixed $data = null,
        public readonly ?string $paymentLink = null,
        /**
         * Optional human-readable success message ("Payment session created
         * successfully" in the sandbox). Not present in every response —
         * defaults to ''.
         */
        public readonly string $message = '',
        /** @var array<string, mixed> raw decoded body for fields we didn't map */
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
            fee: (float) ($body['fee'] ?? 0),
            feeAmount: (float) ($body['fee_amount'] ?? 0),
            total: (float) ($body['total'] ?? 0),
            payMethod: (string) ($body['pay_method'] ?? ''),
            expiredAt: (string) ($body['expired_at'] ?? ''),
            data: $body['data'] ?? null,
            paymentLink: isset($body['payment_link']) ? (string) $body['payment_link'] : null,
            message: (string) ($body['message'] ?? ''),
            raw: $body,
        );
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
            'fee' => $this->fee,
            'fee_amount' => $this->feeAmount,
            'total' => $this->total,
            'pay_method' => $this->payMethod,
            'expired_at' => $this->expiredAt,
            'data' => $this->data,
            'payment_link' => $this->paymentLink,
            'message' => $this->message,
        ];
    }
}
