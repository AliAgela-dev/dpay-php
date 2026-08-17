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
        /**
         * Hosted receipt for the payment, when DPay issues one.
         */
        public readonly ?string $receiptUrl = null,
        /**
         * The nested payment record — where the card-rail reconciliation
         * fields live. Null when DPay omits the object.
         */
        public readonly ?Payment $payment = null,
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
            // DPay does not send `currency` at the top level of a verify
            // response; it lives on the nested payment object. Prefer that
            // over the SDK's own fallback so we aren't presenting a guess as
            // gateway data.
            currency: self::resolveCurrency($body),
            payMethod: (string) ($body['pay_method'] ?? ''),
            txId: (string) ($body['tx_id'] ?? ''),
            receiptUrl: isset($body['receipt_url']) && is_scalar($body['receipt_url'])
                ? (string) $body['receipt_url']
                : null,
            payment: isset($body['payment']) && is_array($body['payment'])
                ? Payment::fromArray($body['payment'])
                : null,
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

    /**
     * @param  array<string, mixed>  $body
     */
    private static function resolveCurrency(array $body): string
    {
        foreach ([$body['currency'] ?? null, $body['payment']['currency'] ?? null] as $candidate) {
            if (is_scalar($candidate) && (string) $candidate !== '') {
                return (string) $candidate;
            }
        }

        return 'LYD';
    }
}
