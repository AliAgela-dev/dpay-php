<?php

declare(strict_types=1);

namespace DPay\Tests\Unit\Client;

use DPay\Client\DPayClient;
use DPay\Client\PayMethodsClient;
use DPay\Config\DPayConfig;
use DPay\Dto\OpenSessionRequest;
use DPay\Exceptions\DPayValidationException;
use DPay\Http\Transport;
use DPay\Support\MockTransport;
use DPay\Tests\Unit\Support\FakeHttpClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;

/**
 * Opt-in validation of an amount against DPay's live per-gateway limits.
 *
 * The policy under test, decided deliberately:
 *   - absent PayMethodsClient  -> behave exactly as before (backwards compatible)
 *   - active: false            -> refuse locally with a clear message
 *   - outside min/max          -> refuse locally
 *   - lookup itself fails      -> FAIL OPEN and let DPay decide
 *   - slug DPay doesn't list   -> FAIL OPEN (unknown != disabled)
 *   - mock mode                -> no lookup at all
 */
final class LiveLimitValidationTest extends TestCase
{
    /** @return list<array<string, mixed>> */
    private function payMethodsBody(bool $edfaliActive = true): array
    {
        return [
            ['name' => 'EDFali', 'slug' => 'edfali', 'active' => $edfaliActive, 'fee' => 2.5, 'min_deposit' => 5, 'max_deposit' => 60000],
        ];
    }

    /** @return array<string, mixed> */
    private function openSessionBody(): array
    {
        return [
            'session_id' => 1, 'status' => 'pending', 'amount' => 50.0, 'currency' => 'LYD',
            'fee' => 2.5, 'fee_amount' => 1.25, 'total' => 51.25,
            'pay_method' => 'edfali', 'expired_at' => '2026-08-17T12:00:00Z',
        ];
    }

    private function request(float $amount): OpenSessionRequest
    {
        return new OpenSessionRequest(payMethod: 'edfali', amount: $amount, customerMobile: '0912345678');
    }

    private function build(FakeHttpClient $http, bool $withPayMethods = true, bool $mock = false): DPayClient
    {
        $psr17 = new Psr17Factory();
        $config = new DPayConfig(baseUrl: 'https://dpay.ly/api', apiKey: 'k', mock: $mock, minAmount: 0.01);
        $transport = new Transport($config, $http, $psr17, $psr17);

        return new DPayClient(
            config: $config,
            transport: $transport,
            mockTransport: $mock ? new MockTransport() : null,
            payMethods: $withPayMethods ? new PayMethodsClient($transport) : null,
        );
    }

    public function test_without_a_pay_methods_client_no_lookup_happens_at_all(): void
    {
        // Backwards compatibility: only the openSession response is queued,
        // so any extra request would blow up on an empty queue.
        $http = (new FakeHttpClient())->queueJson(200, $this->openSessionBody());

        $this->build($http, withPayMethods: false)->openSession($this->request(50.0));

        self::assertCount(1, $http->sent);
        self::assertStringContainsString('/payment/sessions/open', (string) $http->lastRequest()->getUri());
    }

    public function test_an_amount_below_the_live_minimum_is_refused_before_any_session_is_opened(): void
    {
        $http = (new FakeHttpClient())->queueJson(200, $this->payMethodsBody());

        try {
            $this->build($http)->openSession($this->request(4.99));
            self::fail('Expected a DPayValidationException.');
        } catch (DPayValidationException $e) {
            self::assertStringContainsString('5', $e->getMessage());
            self::assertStringContainsString('edfali', $e->getMessage());
        }

        // Only the pay-methods lookup went out — no session was opened.
        self::assertCount(1, $http->sent);
        self::assertStringContainsString('/pay-methods', (string) $http->lastRequest()->getUri());
    }

    public function test_an_amount_above_the_live_maximum_is_refused(): void
    {
        $http = (new FakeHttpClient())->queueJson(200, $this->payMethodsBody());

        $this->expectException(DPayValidationException::class);
        $this->build($http)->openSession($this->request(60000.01));
    }

    public function test_an_amount_inside_the_live_limits_opens_normally(): void
    {
        $http = (new FakeHttpClient())
            ->queueJson(200, $this->payMethodsBody())
            ->queueJson(200, $this->openSessionBody());

        $response = $this->build($http)->openSession($this->request(50.0));

        self::assertSame(1, $response->sessionId);
        self::assertCount(2, $http->sent);
    }

    public function test_the_boundaries_themselves_are_accepted(): void
    {
        $http = (new FakeHttpClient())
            ->queueJson(200, $this->payMethodsBody())
            ->queueJson(200, $this->openSessionBody());
        $client = $this->build($http);

        // min_deposit is inclusive; 4.99 was rejected above, 5 must not be.
        $client->openSession($this->request(5.0));

        self::assertCount(2, $http->sent);
    }

    public function test_a_gateway_dpay_reports_as_inactive_is_refused(): void
    {
        $http = (new FakeHttpClient())->queueJson(200, $this->payMethodsBody(edfaliActive: false));

        try {
            $this->build($http)->openSession($this->request(50.0));
            self::fail('Expected a DPayValidationException.');
        } catch (DPayValidationException $e) {
            self::assertStringContainsString('not enabled', $e->getMessage());
            self::assertStringContainsString('edfali', $e->getMessage());
        }
    }

    public function test_a_failed_lookup_fails_open_and_the_session_still_opens(): void
    {
        // A transient failure on a convenience endpoint must never block a
        // real payment. DPay still enforces its own limits server-side.
        $http = (new FakeHttpClient())
            ->queueJson(500, ['message' => 'pay-methods is down'])
            ->queueJson(200, $this->openSessionBody());

        $response = $this->build($http)->openSession($this->request(50.0));

        self::assertSame(1, $response->sessionId);
    }

    public function test_a_transport_failure_during_lookup_also_fails_open(): void
    {
        $http = new FakeHttpClient();
        $http->throwOnNext = new \RuntimeException('could not resolve host');
        $http->queueJson(200, $this->openSessionBody());

        $response = $this->build($http)->openSession($this->request(50.0));

        self::assertSame(1, $response->sessionId);
    }

    public function test_a_slug_dpay_does_not_list_fails_open_rather_than_being_treated_as_disabled(): void
    {
        // Absent from the list is not the same as active:false. We can't
        // validate it, so we let DPay answer.
        $http = (new FakeHttpClient())
            ->queueJson(200, [['slug' => 'mobicash', 'active' => true, 'min_deposit' => 1, 'max_deposit' => 10]])
            ->queueJson(200, $this->openSessionBody());

        $response = $this->build($http)->openSession($this->request(50.0));

        self::assertSame(1, $response->sessionId);
    }

    public function test_mock_mode_performs_no_lookup(): void
    {
        // Mock mode short-circuits before validation, as it already did for
        // min_amount. An empty queue proves no HTTP happened at all.
        $http = new FakeHttpClient();

        $response = $this->build($http, mock: true)->openSession($this->request(50.0));

        self::assertGreaterThan(0, $response->sessionId);
        self::assertSame([], $http->sent);
    }

    public function test_the_configured_min_amount_still_applies_and_runs_first(): void
    {
        // The cheap local floor should reject before any network call — the
        // two checks serve different purposes and both remain.
        $http = new FakeHttpClient();
        $config = new DPayConfig(baseUrl: 'https://dpay.ly/api', apiKey: 'k', minAmount: 10.0);
        $psr17 = new Psr17Factory();
        $transport = new Transport($config, $http, $psr17, $psr17);
        $client = new DPayClient($config, $transport, null, new PayMethodsClient($transport));

        try {
            $client->openSession($this->request(1.0));
            self::fail('Expected a DPayValidationException.');
        } catch (DPayValidationException $e) {
            self::assertStringContainsString('below the minimum of 10', $e->getMessage());
        }

        self::assertSame([], $http->sent, 'min_amount must reject before any lookup.');
    }

    public function test_the_lookup_is_memoised_across_repeated_openSession_calls(): void
    {
        $http = (new FakeHttpClient())
            ->queueJson(200, $this->payMethodsBody())
            ->queueJson(200, $this->openSessionBody())
            ->queueJson(200, $this->openSessionBody());
        $client = $this->build($http);

        $client->openSession($this->request(50.0));
        $client->openSession($this->request(50.0));

        // 1 lookup + 2 opens, not 2 lookups.
        self::assertCount(3, $http->sent);
    }
}
