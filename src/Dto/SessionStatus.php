<?php

declare(strict_types=1);

namespace DPay\Dto;

/**
 * DPay session lifecycle states observed in the health-portal client:
 *   - 'pending' returned on openSession
 *   - 'paid'    returned on successful verifySession / getSession after settlement
 *
 * Additional cases included for forward-compat and to match common gateway vocab.
 * `fromString` falls back to UNKNOWN rather than throwing so an unexpected value
 * from the gateway doesn't crash callers.
 */
enum SessionStatus: string
{
    case PENDING = 'pending';
    case PAID = 'paid';
    case FAILED = 'failed';
    case EXPIRED = 'expired';
    case REFUNDED = 'refunded';
    case UNKNOWN = 'unknown';

    public static function fromString(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::UNKNOWN;
    }

    public function isTerminal(): bool
    {
        return match ($this) {
            self::PAID, self::FAILED, self::EXPIRED, self::REFUNDED => true,
            default => false,
        };
    }
}
