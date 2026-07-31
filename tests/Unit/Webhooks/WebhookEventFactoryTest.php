<?php

declare(strict_types=1);

namespace DPay\Tests\Unit\Webhooks;

use DPay\Webhooks\PaymentEvent;
use DPay\Webhooks\TestEvent;
use DPay\Webhooks\WebhookEventFactory;
use DPay\Webhooks\WebhookEventType;
use PHPUnit\Framework\TestCase;

final class WebhookEventFactoryTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function paymentEventNames(): iterable
    {
        yield 'paid' => ['payment.paid'];
        yield 'failed' => ['payment.failed'];
        yield 'expired' => ['payment.expired'];
        yield 'refunded' => ['payment.refunded'];
        yield 'voided' => ['payment.voided'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('paymentEventNames')]
    public function test_every_payment_event_builds_a_payment_event_instance(string $eventName): void
    {
        $event = WebhookEventFactory::fromArray(['event' => $eventName, 'session_id' => 1]);

        self::assertInstanceOf(PaymentEvent::class, $event);
    }

    public function test_webhook_test_builds_a_test_event_instance(): void
    {
        $event = WebhookEventFactory::fromArray(['event' => 'webhook.test', 'merchant_id' => 1]);

        self::assertInstanceOf(TestEvent::class, $event);
    }

    public function test_an_unrecognized_event_still_builds_a_payment_event_rather_than_throwing(): void
    {
        // Mirrors SessionStatus's philosophy: an unexpected value from DPay
        // must degrade gracefully, not crash the webhook receiver. A future
        // payment.* event this SDK doesn't know about yet still has
        // session_id-shaped fields, so PaymentEvent is the right fallback.
        $event = WebhookEventFactory::fromArray(['event' => 'payment.something_new', 'session_id' => 7]);

        self::assertInstanceOf(PaymentEvent::class, $event);
        self::assertSame(WebhookEventType::UNKNOWN, $event->eventType());
        self::assertSame(7, $event->sessionId);
    }

    public function test_a_missing_event_key_builds_a_payment_event_with_unknown_type(): void
    {
        $event = WebhookEventFactory::fromArray(['session_id' => 1]);

        self::assertInstanceOf(PaymentEvent::class, $event);
        self::assertSame(WebhookEventType::UNKNOWN, $event->eventType());
    }

    public function test_a_non_scalar_event_field_still_routes_to_payment_event_with_unknown_type(): void
    {
        $event = WebhookEventFactory::fromArray(['event' => ['nested' => 'x'], 'session_id' => 1]);

        self::assertInstanceOf(PaymentEvent::class, $event);
        self::assertSame(WebhookEventType::UNKNOWN, $event->eventType());
    }
}
