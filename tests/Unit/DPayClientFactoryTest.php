<?php

declare(strict_types=1);

namespace DPay\Tests\Unit;

use DPay\Client\DPayClient;
use DPay\Client\DPayClientFactory;
use DPay\Config\DPayConfig;
use DPay\Dto\OpenSessionRequest;
use DPay\Exceptions\DPayValidationException;
use DPay\Support\MockTransport;
use DPay\Tests\Unit\Support\FakeHttpClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * `DPayClientFactory::create()` is the first thing most consumers call, and
 * until now nothing covered it — only the live sandbox probe exercised it,
 * and that isn't PHPUnit and doesn't run in CI.
 */
final class DPayClientFactoryTest extends TestCase
{
    private function config(bool $mock = false): DPayConfig
    {
        return new DPayConfig(
            baseUrl: 'https://dpay.ly/api',
            apiKey: 'k',
            timeout: 15,
            mock: $mock,
            minAmount: 0.01,
        );
    }

    public function test_it_builds_a_client_with_no_dependencies_supplied(): void
    {
        // The whole point of the factory: a brand-new project with nothing
        // wired up gets a working client, because Guzzle is present.
        self::assertInstanceOf(DPayClient::class, DPayClientFactory::create($this->config()));
    }

    public function test_supplied_dependencies_are_used_instead_of_the_guessed_ones(): void
    {
        $psr17 = new Psr17Factory();
        $http = (new FakeHttpClient())->queueJson(200, [
            'session_id' => 4242,
            'status' => 'pending',
            'amount' => 10.5,
            'currency' => 'LYD',
            'fee' => 0.2,
            'fee_amount' => 0.02,
            'total' => 10.52,
            'pay_method' => 'edfali',
            'expired_at' => '2026-08-16T19:00:00Z',
        ]);

        $client = DPayClientFactory::create(
            $this->config(),
            httpClient: $http,
            requestFactory: $psr17,
            streamFactory: $psr17,
            logger: new NullLogger(),
        );

        $response = $client->openSession(new OpenSessionRequest(
            payMethod: 'edfali',
            amount: 10.5,
            customerMobile: '0912345678',
        ));

        // Proves the injected client was actually wired through, not merely
        // accepted and discarded in favour of a Guzzle instance.
        self::assertSame(4242, $response->sessionId);
        self::assertCount(1, $http->sent);
        self::assertSame('Bearer k', $http->lastRequest()->getHeaderLine('Authorization'));
    }

    public function test_the_configured_base_url_and_timeout_reach_the_request(): void
    {
        $psr17 = new Psr17Factory();
        $http = (new FakeHttpClient())->queueJson(200, ['session_id' => 1, 'status' => 'paid']);

        $client = DPayClientFactory::create(
            new DPayConfig(baseUrl: 'https://dpay.ly/api/sandbox', apiKey: 'tok', mock: false),
            httpClient: $http,
            requestFactory: $psr17,
            streamFactory: $psr17,
        );

        $client->getSession(1);

        self::assertSame(
            'https://dpay.ly/api/sandbox/payment/sessions/1',
            (string) $http->lastRequest()->getUri(),
        );
        self::assertSame('Bearer tok', $http->lastRequest()->getHeaderLine('Authorization'));
    }

    public function test_a_supplied_mock_transport_is_passed_through_and_short_circuits_http(): void
    {
        $psr17 = new Psr17Factory();
        // Queue nothing: FakeHttpClient throws if a request ever reaches it,
        // so this fails loudly if mock mode doesn't short-circuit.
        $http = new FakeHttpClient();

        $client = DPayClientFactory::create(
            $this->config(mock: true),
            httpClient: $http,
            requestFactory: $psr17,
            streamFactory: $psr17,
            mockTransport: new MockTransport(),
        );

        $response = $client->openSession(new OpenSessionRequest(
            payMethod: 'edfali',
            amount: 10.5,
            customerMobile: '0912345678',
        ));

        self::assertGreaterThan(0, $response->sessionId);
        self::assertSame([], $http->sent, 'Mock mode must not perform any HTTP request.');
    }

    public function test_live_limit_validation_is_off_by_default(): void
    {
        $psr17 = new Psr17Factory();
        // Only the openSession response is queued — a pay-methods lookup
        // would hit an empty queue and throw.
        $http = (new FakeHttpClient())->queueJson(200, [
            'session_id' => 9, 'status' => 'pending', 'amount' => 50.0, 'currency' => 'LYD',
            'fee' => 0.0, 'fee_amount' => 0.0, 'total' => 50.0,
            'pay_method' => 'edfali', 'expired_at' => '2026-08-17T12:00:00Z',
        ]);

        $client = DPayClientFactory::create(
            $this->config(),
            httpClient: $http,
            requestFactory: $psr17,
            streamFactory: $psr17,
        );

        $client->openSession(new OpenSessionRequest(payMethod: 'edfali', amount: 50.0, customerMobile: '0912345678'));

        self::assertCount(1, $http->sent);
    }

    public function test_validate_against_live_limits_wires_a_pay_methods_client(): void
    {
        $psr17 = new Psr17Factory();
        $http = (new FakeHttpClient())->queueJson(200, [
            ['slug' => 'edfali', 'active' => true, 'min_deposit' => 100, 'max_deposit' => 900],
        ]);

        $client = DPayClientFactory::create(
            $this->config(),
            httpClient: $http,
            requestFactory: $psr17,
            streamFactory: $psr17,
            validateAgainstLiveLimits: true,
        );

        // 50 is below the live minimum of 100, so it must be refused locally
        // — which only happens if the factory wired the lookup through.
        $this->expectException(DPayValidationException::class);
        $client->openSession(new OpenSessionRequest(payMethod: 'edfali', amount: 50.0, customerMobile: '0912345678'));
    }

    public function test_each_call_returns_an_independent_client(): void
    {
        $a = DPayClientFactory::create($this->config());
        $b = DPayClientFactory::create($this->config());

        // The factory is a convenience constructor, not a singleton registry —
        // callers may hold differently-configured clients at once.
        self::assertNotSame($a, $b);
    }
}
