<?php

declare(strict_types=1);

namespace DPay\Webhooks;

/**
 * Builds the right typed event from a decoded webhook payload, based on
 * the `event` field.
 *
 * Anything that isn't webhook.test is treated as a PaymentEvent — including
 * an event name this SDK doesn't recognize yet, since a future payment.*
 * event is far more likely to share PaymentEvent's shape than TestEvent's.
 *
 * Routing here is currently binary (TEST vs. everything else). If DPay
 * ever adds another event with a genuinely different payload shape, turn
 * this into an explicit match over WebhookEventType — don't just extend
 * the ternary.
 */
final class WebhookEventFactory
{
    /**
     * @param  array<string, mixed>  $decoded
     */
    public static function fromArray(array $decoded): WebhookEventInterface
    {
        $type = WebhookEventType::fromString(self::toNullableString($decoded['event'] ?? null));

        return $type === WebhookEventType::TEST
            ? TestEvent::fromArray($decoded)
            : PaymentEvent::fromArray($decoded);
    }

    /**
     * Coerce a JSON-decoded value to a string, never triggering PHP's
     * "Array to string conversion" warning. json_decode() can hand back
     * anything for a field DPay documents as a string — an attacker
     * controls the webhook body, and a warning here becomes an uncaught
     * ErrorException under Laravel's default exception handling. Treat
     * anything non-scalar as absent rather than crashing the receiver.
     */
    private static function toNullableString(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }
}
