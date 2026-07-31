<?php

declare(strict_types=1);

namespace DPay\Tests\Unit\Webhooks;

use DPay\Webhooks\WebhookEventType;
use PHPUnit\Framework\TestCase;

final class WebhookEventTypeTest extends TestCase
{
    /**
     * @return iterable<string, array{string, WebhookEventType}>
     */
    public static function knownEvents(): iterable
    {
        yield 'paid' => ['payment.paid', WebhookEventType::PAID];
        yield 'failed' => ['payment.failed', WebhookEventType::FAILED];
        yield 'expired' => ['payment.expired', WebhookEventType::EXPIRED];
        yield 'refunded' => ['payment.refunded', WebhookEventType::REFUNDED];
        yield 'voided' => ['payment.voided', WebhookEventType::VOIDED];
        yield 'test' => ['webhook.test', WebhookEventType::TEST];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('knownEvents')]
    public function test_from_string_maps_every_documented_event(string $wire, WebhookEventType $expected): void
    {
        self::assertSame($expected, WebhookEventType::fromString($wire));
    }

    public function test_an_undocumented_event_degrades_to_unknown_rather_than_throwing(): void
    {
        // Mirrors SessionStatus::fromString — a new DPay event type must
        // never crash a webhook receiver. mpgs-related events, if DPay
        // adds them, would land here until this enum is updated.
        self::assertSame(WebhookEventType::UNKNOWN, WebhookEventType::fromString('payment.something_new'));
        self::assertSame(WebhookEventType::UNKNOWN, WebhookEventType::fromString(null));
    }
}
