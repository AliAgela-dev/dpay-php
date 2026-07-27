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
 */
final class WebhookEventFactory
{
    /**
     * @param  array<string, mixed>  $decoded
     */
    public static function fromArray(array $decoded): WebhookEventInterface
    {
        $type = WebhookEventType::fromString(isset($decoded['event']) ? (string) $decoded['event'] : null);

        return $type === WebhookEventType::TEST
            ? TestEvent::fromArray($decoded)
            : PaymentEvent::fromArray($decoded);
    }
}
