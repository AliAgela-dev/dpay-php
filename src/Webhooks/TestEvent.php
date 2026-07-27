<?php

declare(strict_types=1);

namespace DPay\Webhooks;

/**
 * Payload for webhook.test, fired by the "Send test event" button at
 * Dashboard -> Webhooks. Deliberately a different shape from PaymentEvent —
 * it identifies the webhook endpoint being tested (merchant/webhook id),
 * not a payment session. There is no session_id here at all.
 */
final class TestEvent implements WebhookEventInterface
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly int $merchantId,
        public readonly string $merchantEmail,
        public readonly int $webhookId,
        public readonly string $webhookLabel,
        public readonly string $timestamp,
        public readonly string $message,
        public readonly array $raw = [],
    ) {}

    /**
     * @param  array<string, mixed>  $body
     */
    public static function fromArray(array $body): self
    {
        return new self(
            merchantId: (int) ($body['merchant_id'] ?? 0),
            merchantEmail: (string) ($body['merchant_email'] ?? ''),
            webhookId: (int) ($body['webhook_id'] ?? 0),
            webhookLabel: (string) ($body['webhook_label'] ?? ''),
            timestamp: (string) ($body['timestamp'] ?? ''),
            message: (string) ($body['message'] ?? ''),
            raw: $body,
        );
    }

    public function eventType(): WebhookEventType
    {
        return WebhookEventType::TEST;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->raw !== [] ? $this->raw : [
            'event' => WebhookEventType::TEST->value,
            'merchant_id' => $this->merchantId,
            'merchant_email' => $this->merchantEmail,
            'webhook_id' => $this->webhookId,
            'webhook_label' => $this->webhookLabel,
            'timestamp' => $this->timestamp,
            'message' => $this->message,
        ];
    }
}
