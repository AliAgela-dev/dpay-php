<?php

declare(strict_types=1);

namespace DPay\Laravel\Events;

use DPay\Webhooks\WebhookEventInterface;

/**
 * Fired after a webhook request passes signature + timestamp verification
 * and has been parsed into a typed event. Listen for this to react to
 * payment.paid/failed/expired/refunded/voided or webhook.test.
 */
final class DPayWebhookReceived
{
    public function __construct(public readonly WebhookEventInterface $event) {}
}
