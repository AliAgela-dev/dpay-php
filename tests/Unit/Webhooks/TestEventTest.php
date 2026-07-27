<?php

declare(strict_types=1);

namespace DPay\Tests\Unit\Webhooks;

use DPay\Webhooks\TestEvent;
use DPay\Webhooks\WebhookEventType;
use PHPUnit\Framework\TestCase;

final class TestEventTest extends TestCase
{
    public function test_parses_the_dashboard_test_event(): void
    {
        $event = TestEvent::fromArray(json_decode(<<<'JSON'
        {
          "event": "webhook.test",
          "test": true,
          "merchant_id": 1,
          "merchant_email": "merchant@example.com",
          "webhook_id": 12,
          "webhook_label": "Production API",
          "timestamp": "2026-04-22T10:00:00+00:00",
          "message": "This is a test event from the DPAY dashboard. If you received this, your webhook is configured correctly."
        }
        JSON, true));

        self::assertSame(WebhookEventType::TEST, $event->eventType());
        self::assertSame(1, $event->merchantId);
        self::assertSame('merchant@example.com', $event->merchantEmail);
        self::assertSame(12, $event->webhookId);
        self::assertSame('Production API', $event->webhookLabel);
        self::assertSame('2026-04-22T10:00:00+00:00', $event->timestamp);
        self::assertStringContainsString('configured correctly', $event->message);
    }

    public function test_has_no_session_id_property_at_all(): void
    {
        // Structural check, not just a missing-field check: TestEvent must
        // not accidentally inherit PaymentEvent's shape. If this line fails
        // to compile after a refactor, that refactor merged the two shapes
        // incorrectly.
        self::assertFalse(property_exists(TestEvent::class, 'sessionId'));
    }

    public function test_raw_preserves_the_full_payload(): void
    {
        $body = [
            'event' => 'webhook.test', 'test' => true, 'merchant_id' => 1,
            'merchant_email' => 'm@example.com', 'webhook_id' => 12,
            'webhook_label' => 'X', 'timestamp' => '2026-01-01T00:00:00+00:00',
            'message' => 'hi',
        ];

        self::assertSame($body, TestEvent::fromArray($body)->toArray());
    }

    public function test_a_non_scalar_field_value_degrades_instead_of_warning_or_crashing(): void
    {
        $event = TestEvent::fromArray(['message' => ['nested' => 'x'], 'merchant_id' => 1]);

        self::assertSame('', $event->message);
    }
}
