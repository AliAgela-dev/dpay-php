<?php

declare(strict_types=1);

namespace DPay\Tests\Unit\Http;

use DPay\Config\DPayConfig;
use DPay\Exceptions\DPayAuthException;
use DPay\Exceptions\DPayException;
use DPay\Exceptions\DPayNetworkException;
use DPay\Exceptions\DPayRateLimitException;
use DPay\Exceptions\DPayValidationException;
use DPay\Http\Transport;
use DPay\Tests\Unit\Support\FakeHttpClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;

final class TransportTest extends TestCase
{
    private function transport(FakeHttpClient $http): Transport
    {
        $psr17 = new Psr17Factory();

        return new Transport(
            new DPayConfig(baseUrl: 'https://dpay.ly/api', apiKey: 'k'),
            $http,
            $psr17,
            $psr17,
        );
    }

    public function test_request_sends_bearer_auth_and_decodes_json(): void
    {
        $http = (new FakeHttpClient())->queueJson(200, ['ok' => true]);

        $body = $this->transport($http)->request('GET', '/ping');

        self::assertSame(['ok' => true], $body);
        self::assertSame('Bearer k', $http->lastRequest()->getHeaderLine('Authorization'));
    }

    public function test_extra_headers_are_attached(): void
    {
        $http = (new FakeHttpClient())->queueJson(200, []);

        $this->transport($http)->request('POST', '/x', ['a' => 1], ['Idempotency-Key' => 'abc-123']);

        self::assertSame('abc-123', $http->lastRequest()->getHeaderLine('Idempotency-Key'));
    }

    public function test_request_throws_mapped_exception(): void
    {
        $http = (new FakeHttpClient())->queueJson(401, ['message' => 'Invalid sandbox API token.']);

        $this->expectException(DPayAuthException::class);
        $this->transport($http)->request('GET', '/x');
    }

    public function test_rate_limit_maps_to_its_own_exception(): void
    {
        $http = (new FakeHttpClient())->queueJson(429, ['message' => 'Too Many Attempts.']);

        $this->expectException(DPayRateLimitException::class);
        $this->transport($http)->request('GET', '/x');
    }

    public function test_a_message_less_error_body_falls_back_to_a_generic_message(): void
    {
        $http = (new FakeHttpClient())->queueJson(500, []);

        try {
            $this->transport($http)->request('GET', '/x');
            self::fail('Expected a DPayException.');
        } catch (DPayException $e) {
            self::assertSame('DPay request failed.', $e->getMessage());
        }
    }

    public function test_attempt_returns_null_instead_of_throwing(): void
    {
        $http = (new FakeHttpClient())->queueJson(422, ['message' => 'bad otp']);

        self::assertNull($this->transport($http)->attempt('POST', '/verify', ['otp' => '0000']));
    }

    public function test_transport_failure_becomes_network_exception(): void
    {
        $http = new FakeHttpClient();
        $http->throwOnNext = new \RuntimeException('could not resolve host');

        $this->expectException(DPayNetworkException::class);
        $this->transport($http)->request('GET', '/x');
    }

    public function test_a_closure_in_the_body_is_rejected_rather_than_silently_dropped(): void
    {
        $http = (new FakeHttpClient())->queueJson(200, []);

        $this->expectException(DPayValidationException::class);
        $this->transport($http)->request('POST', '/x', ['data' => ['cb' => static fn () => 1]]);
    }

    public function test_the_rejection_names_the_offending_field_path(): void
    {
        $http = (new FakeHttpClient())->queueJson(200, []);

        try {
            $this->transport($http)->request('POST', '/x', ['data' => ['order' => ['cb' => static fn () => 1]]]);
            self::fail('Expected a validation exception.');
        } catch (DPayValidationException $e) {
            self::assertStringContainsString('data.order.cb', $e->getMessage());
        }
    }

    public function test_lists_are_walked_too_and_reported_by_index(): void
    {
        $http = (new FakeHttpClient())->queueJson(200, []);

        try {
            $this->transport($http)->request('POST', '/x', [
                'data' => ['items' => ['ok', static fn () => 1]],
            ]);
            self::fail('Expected a validation exception.');
        } catch (DPayValidationException $e) {
            self::assertStringContainsString('data.items.1', $e->getMessage());
        }
    }

    public function test_scalars_arrays_and_json_serializable_all_pass(): void
    {
        $http = (new FakeHttpClient())->queueJson(200, ['ok' => true]);

        $serializable = new class implements \JsonSerializable
        {
            public function jsonSerialize(): mixed
            {
                return ['v' => 1];
            }
        };

        $body = $this->transport($http)->request('POST', '/x', [
            'a' => 'str', 'b' => 1, 'c' => 1.5, 'd' => true, 'e' => null,
            'f' => ['nested' => ['deep' => 'ok']],
            'g' => $serializable,
        ]);

        self::assertSame(['ok' => true], $body);
    }

    public function test_no_request_is_sent_when_the_body_is_rejected(): void
    {
        $http = (new FakeHttpClient())->queueJson(200, []);

        try {
            $this->transport($http)->request('POST', '/x', ['bad' => static fn () => 1]);
        } catch (DPayValidationException) {
            // expected
        }

        self::assertSame([], $http->sent, 'Nothing may reach the wire when the body is invalid.');
    }
}
