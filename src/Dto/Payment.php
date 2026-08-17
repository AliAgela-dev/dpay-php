<?php

declare(strict_types=1);

namespace DPay\Dto;

/**
 * The nested `payment` object DPay returns on a successful verify.
 *
 * This is where the card-rail reconciliation fields live —
 * `system_reference`, `network_reference`, `paid_through` and
 * `payer_account`. They were previously reachable only through
 * VerifySessionResponse::$raw, which made the typed API less useful than the
 * untyped one for exactly the case that needs it most.
 *
 * All four are `?string` on purpose: every sandbox delivery we captured had
 * them null, and the official examples show them populated only on Moamalat
 * (card) payments. Wallet and bank gateways not filling them is correct
 * behaviour, not a gap.
 */
final class Payment
{
    public function __construct(
        public readonly int $id,
        public readonly int $paymentSessionId,
        public readonly float $amount,
        public readonly string $currency,
        /**
         * The payment record's own status — DPay uses its own vocabulary
         * here ("completed"), which is NOT the session lifecycle vocabulary.
         * It therefore degrades to UNKNOWN rather than being mistaken for a
         * session status. Read VerifySessionResponse::$status for that.
         */
        public readonly SessionStatus $status,
        public readonly string $payMethod,
        public readonly string $txId,
        public readonly ?string $systemReference = null,
        public readonly ?string $networkReference = null,
        public readonly ?string $paidThrough = null,
        public readonly ?string $payerAccount = null,
        public readonly string $createdAt = '',
        public readonly ?int $userId = null,
        public readonly ?int $companyId = null,
        /** @var array<string, mixed> */
        public readonly array $raw = [],
    ) {}

    /**
     * @param  array<string, mixed>  $body
     */
    public static function fromArray(array $body): self
    {
        return new self(
            id: (int) ($body['id'] ?? 0),
            paymentSessionId: (int) ($body['payment_session_id'] ?? 0),
            amount: (float) ($body['amount'] ?? 0),
            currency: self::toStringOrDefault($body['currency'] ?? null, 'LYD'),
            status: SessionStatus::fromString(self::toNullableString($body['status'] ?? null)),
            payMethod: self::toStringOrDefault($body['pay_method'] ?? null, ''),
            txId: self::toStringOrDefault($body['tx_id'] ?? null, ''),
            systemReference: self::toNullableString($body['system_reference'] ?? null),
            networkReference: self::toNullableString($body['network_reference'] ?? null),
            paidThrough: self::toNullableString($body['paid_through'] ?? null),
            payerAccount: self::toNullableString($body['payer_account'] ?? null),
            createdAt: self::toStringOrDefault($body['created_at'] ?? null, ''),
            userId: isset($body['user_id']) ? (int) $body['user_id'] : null,
            companyId: isset($body['company_id']) ? (int) $body['company_id'] : null,
            raw: $body,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->raw;
    }

    private static function toNullableString(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }

    private static function toStringOrDefault(mixed $value, string $default): string
    {
        return is_scalar($value) ? (string) $value : $default;
    }
}
