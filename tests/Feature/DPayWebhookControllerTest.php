<?php

declare(strict_types=1);

namespace DPay\Tests\Feature;

use DPay\Dto\SessionStatus;
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

    public function test_no_signature_headers_at_all_is_rejected_with_401(): void
    {
        Event::fake();

        $body = json_encode(['event' => 'payment.paid', 'session_id' => 42], JSON_THROW_ON_ERROR);

        $response = $this->call('POST', self::ROUTE, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], $body);

        $response->assertStatus(401);
        Event::assertNotDispatched(DPayWebhookReceived::class);
    }

    /**
     * The signature is computed over the exact bytes DPay sent. If anything
     * between the wire and the controller re-encodes the JSON, the HMAC
     * stops matching — which is the failure troubleshooting.md tells people
     * to look for, and nothing pinned it.
     *
     * This body is built so that a round-trip through json_decode/json_encode
     * would necessarily change the bytes: Arabic text PHP would re-escape to
     * \uXXXX, a slash PHP would escape to \/, a trailing zero on 10.50 that
     * would collapse to 10.5, and non-alphabetical key order. If the
     * controller verified against a re-serialized body, this test fails.
     */
    public function test_the_signature_is_verified_against_the_raw_bytes_not_a_reserialized_body(): void
    {
        Event::fake();

        $body = '{"event":"payment.paid","session_id":77,"amount":10.50,'
            .'"description":"دفع طلب","receipt":"https://dpay.ly/r/77",'
            .'"data":{"order_id":"ORD-9"},"pay_method":"edfali"}';

        // Guard the premise: if PHP's re-encoding ever matched byte-for-byte,
        // this test would silently stop proving anything.
        $reserialized = json_encode(json_decode($body, true), JSON_THROW_ON_ERROR);
        self::assertNotSame($body, $reserialized, 'Premise broken: re-encoding no longer changes the bytes.');

        $timestamp = (string) time();

        $response = $this->call('POST', self::ROUTE, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X-DPAY-Signature' => $this->sign($body, $timestamp),
            'HTTP_X-DPAY-Timestamp' => $timestamp,
        ], $body);

        $response->assertOk();
        Event::assertDispatched(DPayWebhookReceived::class);
    }

    /**
     * The exact payload DPay delivered to a live endpoint on 2026-08-16,
     * with only the tx_id shortened. Pins three real-world details the
     * hand-written fixtures above don't have: the settled `amount` is the
     * rounded figure (11) rather than the requested 10.5, DPay's fee keys
     * are merged into the merchant's own `data`, and every reference field
     * arrives null on a wallet gateway.
     */
    public function test_a_real_captured_delivery_parses_end_to_end(): void
    {
        Event::fake();

        $body = json_encode([
            'event' => 'payment.paid',
            'live' => false,
            'session_id' => 1608,
            'status' => 'paid',
            'amount' => 11,
            'pay_method' => 'edfali',
            'tx_id' => 'sb_txn_1ae47e4b',
            'system_reference' => null,
            'network_reference' => null,
            'paid_through' => null,
            'payer_account' => null,
            'data' => [
                'probe' => 'webhook-live-test',
                'fee_amount' => 0.02,
                'fee_percent' => 0.2,
                'original_amount' => 10.5,
            ],
            'created_at' => '2026-08-16T19:08:58+00:00',
            'paid_at' => '2026-08-16T19:09:01+00:00',
        ], JSON_THROW_ON_ERROR);
        $timestamp = (string) time();

        $response = $this->call('POST', self::ROUTE, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X-DPAY-Signature' => $this->sign($body, $timestamp),
            'HTTP_X-DPAY-Timestamp' => $timestamp,
        ], $body);

        $response->assertOk();

        Event::assertDispatched(DPayWebhookReceived::class, function (DPayWebhookReceived $e) {
            $p = $e->event;

            return $p instanceof PaymentEvent
                && $p->sessionId === 1608
                && $p->live === false
                // The settled amount, not the 10.5 that was requested.
                && $p->amount === 11.0
                && $p->status === SessionStatus::PAID
                // Merchant metadata survives alongside DPay's injected keys.
                && ($p->data['probe'] ?? null) === 'webhook-live-test'
                && ($p->data['original_amount'] ?? null) === 10.5
                // Null reference fields are expected on a wallet gateway.
                && $p->systemReference === null
                && $p->payerAccount === null;
        });
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
