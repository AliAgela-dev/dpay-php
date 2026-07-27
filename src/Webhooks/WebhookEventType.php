<?php

declare(strict_types=1);

namespace DPay\Webhooks;

/**
 * The 6 webhook events documented at https://dpay.ly/docs/api.
 *
 * fromString() never throws — an event type DPay adds later (or a
 * gateway-specific one this SDK doesn't know about yet) degrades to
 * UNKNOWN rather than crashing a webhook receiver, matching
 * SessionStatus::fromString()'s established behavior.
 */
enum WebhookEventType: string
{
    case PAID = 'payment.paid';
    case FAILED = 'payment.failed';
    case EXPIRED = 'payment.expired';
    case REFUNDED = 'payment.refunded';
    case VOIDED = 'payment.voided';
    case TEST = 'webhook.test';
    case UNKNOWN = 'unknown';

    public static function fromString(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::UNKNOWN;
    }
}
