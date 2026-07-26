<?php

declare(strict_types=1);

namespace DPay\Tests\Unit;

use DPay\Client\DPayClient;
use DPay\Config\DPayConfig;
use DPay\Dto\SessionStatus;
use DPay\Exceptions\DPayAuthException;
use DPay\Exceptions\DPayException;
use DPay\Exceptions\DPayNetworkException;
use DPay\Exceptions\DPayRateLimitException;
use DPay\Exceptions\DPaySessionNotFoundException;
use DPay\Exceptions\DPayValidationException;
use DPay\Support\MockTransport;
use DPay\Tests\Unit\Support\FakeHttpClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DPayClientTest extends TestCase
{
    private FakeHttpClient $http;

    private Psr17Factory $psr17;

    private DPayClient $client;

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient();
        $this->psr17 = new Psr17Factory();
        $this->client = new DPayClient(
            config: new DPayConfig(
                baseUrl: 'https://dpay.example/api',
                apiKey: 'test-key',
                timeout: 5,
                mock: false,
                // Explicit floor (above the DPayConfig default of 0.01) so
                // test_open_session_rejects_amount_below_minimum still
                // exercises the below-minimum rejection path.
                minAmount: 5.0,
            ),
            httpClient: $this->http,
            requestFactory: $this->psr17,
            streamFactory: $this->psr17,
        );
    }

    public function test_open_session_sends_expected_request_and_parses_response(): void
    {
        $this->http->queueJson(200, [
            'session_id' => 4242,
            'status' => 'pending',
            'amount' => 50,
            'currency' => 'LYD',
            'fee' => 2.5,
            'fee_amount' => 1.25,
            'total' => 51.25,
            'pay_method' => 'edfali',
            'expired_at' => '2026-01-01T00:00:00+00:00',
            'data' => null,
        ]);

        $resp = $this->client->openSession('edfali', 50, '0911234567');

        self::assertSame(4242, $resp->sessionId);
        self::assertSame(SessionStatus::PENDING, $resp->status);
        self::assertSame(51.25, $resp->total);

        $req = $this->http->lastRequest();
        self::assertSame('POST', $req->getMethod());
        self::assertSame('https://dpay.example/api/payment/sessions/open', (string) $req->getUri());
        self::assertSame('Bearer test-key', $req->getHeaderLine('Authorization'));
        self::assertSame('application/json', $req->getHeaderLine('Accept'));

        $body = json_decode((string) $req->getBody(), true);
        self::assertSame([
            'pay_method' => 'edfali',
            'amount' => 50,
            'customer_mobile' => '0911234567',
        ], $body);
    }

    public function test_open_session_rejects_fractional_amount(): void
    {
        $this->expectException(DPayValidationException::class);
        $this->expectExceptionMessage('whole number');
        $this->client->openSession('edfali', 50.5);
    }

    public function test_open_session_rejects_amount_below_minimum(): void
    {
        $this->expectException(DPayValidationException::class);
        $this->expectExceptionMessage('below the minimum');
        $this->client->openSession('edfali', 1);
    }

    public function test_open_session_maps_422_to_validation_exception(): void
    {
        $this->http->queueJson(422, ['message' => 'pay_method is required']);

        $this->expectException(DPayValidationException::class);
        $this->expectExceptionMessage('pay_method is required');
        $this->client->openSession('edfali', 50, '0911234567');
    }

    public function test_open_session_maps_401_to_auth_exception(): void
    {
        $this->http->queueJson(401, ['message' => 'invalid api key']);

        $this->expectException(DPayAuthException::class);
        $this->client->openSession('edfali', 50, '0911234567');
    }

    public function test_open_session_maps_429_to_rate_limit_exception(): void
    {
        $this->http->queueJson(429, ['message' => 'Too Many Attempts.']);

        $this->expectException(DPayRateLimitException::class);
        $this->expectExceptionMessage('Too Many Attempts');
        $this->client->openSession('edfali', 50, '0911234567');
    }

    public function test_open_session_captures_message_field(): void
    {
        $this->http->queueJson(200, [
            'message' => 'Payment session created successfully',
            'session_id' => 999,
            'status' => 'pending',
            'amount' => 50,
            'fee' => 0.2,
            'fee_amount' => 0.1,
            'total' => 50.1,
            'pay_method' => 'edfali',
            'expired_at' => '2026-05-22T21:34:19.000000Z',
            'data' => null,
            'sandbox' => true,
        ]);

        $resp = $this->client->openSession('edfali', 50, '0911234567');

        self::assertSame('Payment session created successfully', $resp->message);
        self::assertSame('LYD', $resp->currency, 'currency defaults to LYD when not in response');
        self::assertArrayHasKey('sandbox', $resp->raw, 'unmapped fields stay accessible via raw');
    }

    public function test_open_session_maps_500_to_generic_exception(): void
    {
        $this->http->queueJson(500, ['message' => 'internal error']);

        try {
            $this->client->openSession('edfali', 50, '0911234567');
            self::fail('Expected DPayException');
        } catch (DPayValidationException | DPayAuthException | DPaySessionNotFoundException $e) {
            self::fail('Should not match a 4xx subclass; got '.$e::class);
        } catch (DPayException $e) {
            self::assertSame(500, $e->httpStatus);
        }
    }

    public function test_open_session_wraps_transport_failure_in_network_exception(): void
    {
        $this->http->throwOnNext = new RuntimeException('boom');

        $this->expectException(DPayNetworkException::class);
        $this->expectExceptionMessage('Failed to reach DPay: boom');
        $this->client->openSession('edfali', 50, '0911234567');
    }

    public function test_verify_session_returns_null_for_bad_otp(): void
    {
        $this->http->queueJson(422, ['message' => 'invalid otp']);

        $result = $this->client->verifySession(4242, '0000');

        self::assertNull($result);
    }

    public function test_verify_session_parses_success(): void
    {
        $this->http->queueJson(200, [
            'message' => 'ok',
            'payment_id' => 99,
            'status' => 'paid',
            'amount' => 50,
            'currency' => 'LYD',
            'pay_method' => 'edfali',
            'tx_id' => 'txn_abc',
        ]);

        $result = $this->client->verifySession(4242, '1234');

        self::assertNotNull($result);
        self::assertTrue($result->isPaid());
        self::assertSame('txn_abc', $result->txId);

        $body = json_decode((string) $this->http->lastRequest()->getBody(), true);
        self::assertSame(['session_id' => 4242, 'otp' => '1234'], $body);
    }

    public function test_get_session_maps_404_to_session_not_found(): void
    {
        $this->http->queueJson(404, ['message' => 'session not found']);

        $this->expectException(DPaySessionNotFoundException::class);
        $this->client->getSession(999);
    }

    public function test_get_session_parses_success(): void
    {
        $this->http->queueJson(200, [
            'session_id' => 4242,
            'status' => 'paid',
            'amount' => 50,
            'currency' => 'LYD',
            'pay_method' => 'moamalat',
            'expired_at' => '2026-01-01T00:00:00+00:00',
            'data' => null,
        ]);

        $resp = $this->client->getSession(4242);

        self::assertTrue($resp->isPaid());
        self::assertSame('GET', $this->http->lastRequest()->getMethod());
        self::assertSame(
            'https://dpay.example/api/payment/sessions/4242',
            (string) $this->http->lastRequest()->getUri(),
        );
    }

    public function test_mock_mode_bypasses_http(): void
    {
        $mockClient = new DPayClient(
            config: new DPayConfig(mock: true),
            httpClient: $this->http,
            requestFactory: $this->psr17,
            streamFactory: $this->psr17,
            mockTransport: new MockTransport(),
        );

        $session = $mockClient->openSession('edfali', 50, '0911234567');
        self::assertSame(SessionStatus::PENDING, $session->status);
        self::assertSame(50.0, $session->amount);
        self::assertSame([], $this->http->sent, 'mock mode must not hit the HTTP client');

        $verified = $mockClient->verifySession($session->sessionId, '1234');
        self::assertNotNull($verified);
        self::assertTrue($verified->isPaid());

        self::assertNull($mockClient->verifySession($session->sessionId, 'abc'));
    }
}
