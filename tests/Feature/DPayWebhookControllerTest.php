<?php

declare(strict_types=1);

namespace DPay\Tests\Feature;

use DPay\Laravel\DPayServiceProvider;
use DPay\Laravel\Events\DPayWebhookReceived;
use DPay\Webhooks\PaymentEvent;
use Illuminate\Support\Facades\Event;
use Orchestra\Testbench\TestCase;

final class DPayWebhookControllerTest extends TestCase
{
    private const SECRET = 'whsec_test';
    private const ROUTE = '/webhooks/dpay';

    protected function getPackageProviders($app): array
    {
        return [DPayServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('dpay.mock', true);
        $app['config']->set('dpay.api_key', 'k');
        $app['config']->set('dpay.webhooks.enabled', true);
        $app['config']->set('dpay.webhooks.route', self::ROUTE);
        $app['config']->set('dpay.webhooks.secret', self::SECRET);
    }

    private function sign(string $body, string $timestamp): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$body, self::SECRET);
    }

    public function test_a_correctly_signed_request_is_accepted_and_dispatches_an_event(): void
    {
        Event::fake();

        $body = json_encode([
            'event' => 'payment.paid', 'live' => true, 'session_id' => 42,
            'status' => 'paid', 'amount' => 100, 'pay_method' => 'edfali',
            'tx_id' => 'txn_abc123', 'system_reference' => null,
            'network_reference' => null, 'paid_through' => null,
            'payer_account' => null, 'data' => ['order_id' => 'ORD-001'],
            'created_at' => '2026-04-22T10:15:00+00:00', 'paid_at' => '2026-04-22T10:16:30+00:00',
        ], JSON_THROW_ON_ERROR);
        $timestamp = (string) time();

        $response = $this->call('POST', self::ROUTE, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X-DPAY-Signature' => $this->sign($body, $timestamp),
            'HTTP_X-DPAY-Timestamp' => $timestamp,
        ], $body);

        $response->assertOk();

        Event::assertDispatched(DPayWebhookReceived::class, function (DPayWebhookReceived $e) {
            return $e->event instanceof PaymentEvent && $e->event->sessionId === 42;
        });
    }

    public function test_a_badly_signed_request_is_rejected_with_401_and_dispatches_nothing(): void
    {
        Event::fake();

        $body = json_encode(['event' => 'payment.paid', 'session_id' => 42], JSON_THROW_ON_ERROR);
        $timestamp = (string) time();

        $response = $this->call('POST', self::ROUTE, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X-DPAY-Signature' => 'deliberately-wrong',
            'HTTP_X-DPAY-Timestamp' => $timestamp,
        ], $body);

        $response->assertStatus(401);
        Event::assertNotDispatched(DPayWebhookReceived::class);
    }

    public function test_a_stale_timestamp_is_rejected_with_401(): void
    {
        Event::fake();

        $body = json_encode(['event' => 'payment.paid', 'session_id' => 42], JSON_THROW_ON_ERROR);
        $staleTimestamp = (string) (time() - 3600);

        $response = $this->call('POST', self::ROUTE, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X-DPAY-Signature' => $this->sign($body, $staleTimestamp),
            'HTTP_X-DPAY-Timestamp' => $staleTimestamp,
        ], $body);

        $response->assertStatus(401);
        Event::assertNotDispatched(DPayWebhookReceived::class);
    }

    public function test_the_dashboard_test_event_is_accepted_and_dispatches_a_test_event(): void
    {
        Event::fake();

        $body = json_encode([
            'event' => 'webhook.test', 'test' => true, 'merchant_id' => 1,
            'merchant_email' => 'm@example.com', 'webhook_id' => 12,
            'webhook_label' => 'Production API', 'timestamp' => '2026-04-22T10:00:00+00:00',
            'message' => 'test',
        ], JSON_THROW_ON_ERROR);
        $timestamp = (string) time();

        $response = $this->call('POST', self::ROUTE, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X-DPAY-Signature' => $this->sign($body, $timestamp),
            'HTTP_X-DPAY-Timestamp' => $timestamp,
        ], $body);

        $response->assertOk();
        Event::assertDispatched(DPayWebhookReceived::class, fn (DPayWebhookReceived $e) => $e->event instanceof \DPay\Webhooks\TestEvent);
    }
}
