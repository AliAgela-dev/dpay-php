<?php

declare(strict_types=1);

namespace DPay\Webhooks;

/**
 * Shared surface for the two webhook payload shapes: PaymentEvent (the 5
 * payment.* events) and TestEvent (webhook.test — no session_id at all).
 */
interface WebhookEventInterface
{
    public function eventType(): WebhookEventType;

    /**
     * The full decoded payload, for fields not mapped onto typed properties.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array;
}
