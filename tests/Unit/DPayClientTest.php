<?php

declare(strict_types=1);

namespace DPay\Tests\Unit;

use DPay\Client\DPayClient;
use DPay\Config\DPayConfig;
use DPay\Dto\OpenSessionRequest;
use DPay\Dto\SessionStatus;
use DPay\Exceptions\DPayAuthException;
use DPay\Exceptions\DPayException;
use DPay\Exceptions\DPayNetworkException;
use DPay\Exceptions\DPayRateLimitException;
use DPay\Exceptions\DPaySessionNotFoundException;
use DPay\Exceptions\DPayValidationException;
use DPay\Http\Transport;
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

        $config = new DPayConfig(
            baseUrl: 'https://dpay.example/api',
            apiKey: 'test-key',
            timeout: 5,
            mock: false,
        );

        $this->client = new DPayClient(
            config: $config,
            transport: new Transport($config, $this->http, $this->psr17, $this->psr17),
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

        $resp = $this->client->openSession(
            new OpenSessionRequest(payMethod: 'edfali', amount: 50, customerMobile: '0911234567'),
        );

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

    public function test_open_session_maps_422_to_validation_exception(): void
    {
        $this->http->queueJson(422, ['message' => 'pay_method is required']);

        $this->expectException(DPayValidationException::class);
        $this->expectExceptionMessage('pay_method is required');
        $this->client->openSession(
            new OpenSessionRequest(payMethod: 'edfali', amount: 50, customerMobile: '0911234567'),
        );
    }

    public function test_open_session_maps_401_to_auth_exception(): void
    {
        $this->http->queueJson(401, ['message' => 'invalid api key']);

        $this->expectException(DPayAuthException::class);
        $this->client->openSession(
            new OpenSessionRequest(payMethod: 'edfali', amount: 50, customerMobile: '0911234567'),
        );
    }

    public function test_open_session_maps_429_to_rate_limit_exception(): void
    {
        $this->http->queueJson(429, ['message' => 'Too Many Attempts.']);

        $this->expectException(DPayRateLimitException::class);
        $this->expectExceptionMessage('Too Many Attempts');
        $this->client->openSession(
            new OpenSessionRequest(payMethod: 'edfali', amount: 50, customerMobile: '0911234567'),
        );
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

        $resp = $this->client->openSession(
            new OpenSessionRequest(payMethod: 'edfali', amount: 50, customerMobile: '0911234567'),
        );

        self::assertSame('Payment session created successfully', $resp->message);
        self::assertSame('LYD', $resp->currency, 'currency defaults to LYD when not in response');
        self::assertArrayHasKey('sandbox', $resp->raw, 'unmapped fields stay accessible via raw');
    }

    public function test_open_session_maps_500_to_generic_exception(): void
    {
        $this->http->queueJson(500, ['message' => 'internal error']);

        try {
            $this->client->openSession(
                new OpenSessionRequest(payMethod: 'edfali', amount: 50, customerMobile: '0911234567'),
            );
            self::fail('Expected DPayException');
        } catch (DPayValidationException | DPayAuthException | DPaySessionNotFoundException $e) {
            self::fail('Should not match a 4xx subclass; got '.$e::class);
        } catch (DPayException $e) {
            self::assertSame(500, $e->httpStatus);
        }
    }

    public function test_open_session_falls_back_to_a_generic_message_when_the_error_body_has_none(): void
    {
        $http = (new FakeHttpClient())->queueJson(500, []);

        try {
            $this->clientWith($http)->openSession(new OpenSessionRequest(payMethod: 'edfali', amount: 50));
            self::fail('Expected a DPayException.');
        } catch (DPayException $e) {
            self::assertSame('DPay request failed.', $e->getMessage());
        }
    }

    public function test_open_session_wraps_transport_failure_in_network_exception(): void
    {
        $this->http->throwOnNext = new RuntimeException('boom');

        $this->expectException(DPayNetworkException::class);
        $this->expectExceptionMessage('Failed to reach DPay: boom');
        $this->client->openSession(
            new OpenSessionRequest(payMethod: 'edfali', amount: 50, customerMobile: '0911234567'),
        );
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
        $mockConfig = new DPayConfig(mock: true);
        $mockClient = new DPayClient(
            config: $mockConfig,
            transport: new Transport($mockConfig, $this->http, $this->psr17, $this->psr17),
            mockTransport: new MockTransport(),
        );

        $session = $mockClient->openSession(
            new OpenSessionRequest(payMethod: 'edfali', amount: 50, customerMobile: '0911234567'),
        );
        self::assertSame(SessionStatus::PENDING, $session->status);
        self::assertSame(50.0, $session->amount);
        self::assertSame([], $this->http->sent, 'mock mode must not hit the HTTP client');

        $verified = $mockClient->verifySession($session->sessionId, '1234');
        self::assertNotNull($verified);
        self::assertTrue($verified->isPaid());

        self::assertNull($mockClient->verifySession($session->sessionId, 'abc'));
    }

    public function test_fractional_amount_is_now_accepted(): void
    {
        $http = (new FakeHttpClient())->queueJson(200, ['session_id' => 7, 'status' => 'pending', 'amount' => 10.5]);

        $response = $this->clientWith($http)->openSession(
            new OpenSessionRequest(payMethod: 'edfali', amount: 10.5, customerMobile: '0912345678'),
        );

        self::assertSame(7, $response->sessionId);
        self::assertStringContainsString('"amount":10.5', (string) $http->lastRequest()->getBody());
    }

    public function test_amount_below_minimum_is_still_rejected(): void
    {
        $this->expectException(DPayValidationException::class);

        $this->clientWith(new FakeHttpClient(), new DPayConfig(apiKey: 'k', minAmount: 5.0))
            ->openSession(new OpenSessionRequest(payMethod: 'edfali', amount: 1.0));
    }

    public function test_idempotency_key_is_sent_as_a_header(): void
    {
        $http = (new FakeHttpClient())->queueJson(200, ['session_id' => 1, 'status' => 'pending']);

        $this->clientWith($http)->openSession(
            new OpenSessionRequest(payMethod: 'edfali', amount: 50),
            'b3e1c9f0-0000-4000-8000-000000000000',
        );

        self::assertSame(
            'b3e1c9f0-0000-4000-8000-000000000000',
            $http->lastRequest()->getHeaderLine('Idempotency-Key'),
        );
    }

    public function test_no_idempotency_header_when_key_is_omitted(): void
    {
        $http = (new FakeHttpClient())->queueJson(200, ['session_id' => 1, 'status' => 'pending']);

        $this->clientWith($http)->openSession(new OpenSessionRequest(payMethod: 'edfali', amount: 50));

        self::assertFalse($http->lastRequest()->hasHeader('Idempotency-Key'));
    }

    private function clientWith(FakeHttpClient $http, ?DPayConfig $config = null): DPayClient
    {
        $psr17 = new Psr17Factory();
        $config ??= new DPayConfig(apiKey: 'k');

        return new DPayClient(
            $config,
            new Transport($config, $http, $psr17, $psr17),
        );
    }
}
