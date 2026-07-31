<?php

declare(strict_types=1);

namespace DPay\Dto;

/**
 * DPay session lifecycle states.
 *
 *   - 'pending' returned on openSession
 *   - 'paid'    returned on successful verifySession / getSession after settlement
 *
 * Terminal states (isTerminal() returns true):
 *   paid, failed, expired, refunded, voided
 *
 * voided and refunded are NOT interchangeable:
 *   - voided   cancels an authorisation before capture, returning the hold
 *              without ever settling (payment.voided webhook)
 *   - refunded reverses an already-settled charge (payment.refunded webhook)
 *   Both are Moamalat-only.
 *
 * fromString() falls back to UNKNOWN rather than throwing, so an unexpected
 * value from the gateway doesn't crash callers. isTerminal() is deliberately
 * exhaustive with no default arm: a new case must be classified explicitly,
 * and PHPStan will fail the build until it is.
 */
enum SessionStatus: string
{
    case PENDING = 'pending';
    case PAID = 'paid';
    case FAILED = 'failed';
    case EXPIRED = 'expired';
    case REFUNDED = 'refunded';
    case VOIDED = 'voided';
    case UNKNOWN = 'unknown';

    public static function fromString(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::UNKNOWN;
    }

    public function isTerminal(): bool
    {
        return match ($this) {
            self::PAID, self::FAILED, self::EXPIRED, self::REFUNDED, self::VOIDED => true,
            self::PENDING, self::UNKNOWN => false,
        };
    }
}
