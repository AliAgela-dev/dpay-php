<?php

declare(strict_types=1);

namespace DPay\Tests\Unit\Http;

use DPay\Config\DPayConfig;
use DPay\Exceptions\DPayAuthException;
use DPay\Exceptions\DPayException;
use DPay\Exceptions\DPayNetworkException;
use DPay\Exceptions\DPayRateLimitException;
use DPay\Exceptions\DPaySessionNotFoundException;
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

    public function test_403_maps_to_auth_exception_just_like_401(): void
    {
        $http = (new FakeHttpClient())->queueJson(403, ['message' => 'This action is unauthorized.']);

        try {
            $this->transport($http)->request('GET', '/x');
            self::fail('Expected a DPayAuthException.');
        } catch (DPayAuthException $e) {
            self::assertSame(403, $e->httpStatus);
            self::assertSame('This action is unauthorized.', $e->getMessage());
        }
    }

    public function test_field_level_errors_are_exposed_on_the_exception(): void
    {
        $http = (new FakeHttpClient())->queueJson(422, [
            'message' => 'The given data was invalid.',
            'errors' => ['card_number' => ['The card number must be 7 or 9 digits.']],
        ]);

        try {
            $this->transport($http)->request('POST', '/x', ['a' => 1]);
            self::fail('Expected a DPayValidationException.');
        } catch (DPayValidationException $e) {
            self::assertSame(
                ['card_number' => ['The card number must be 7 or 9 digits.']],
                $e->errors,
            );
        }
    }

    public function test_an_absent_errors_field_leaves_errors_null(): void
    {
        $http = (new FakeHttpClient())->queueJson(422, ['message' => 'nope']);

        try {
            $this->transport($http)->request('POST', '/x', ['a' => 1]);
            self::fail('Expected a DPayValidationException.');
        } catch (DPayValidationException $e) {
            self::assertNull($e->errors);
        }
    }

    public function test_a_non_array_errors_field_degrades_to_null_rather_than_type_erroring(): void
    {
        $http = (new FakeHttpClient())->queueJson(422, ['message' => 'nope', 'errors' => 'not-an-array']);

        try {
            $this->transport($http)->request('POST', '/x', ['a' => 1]);
            self::fail('Expected a DPayValidationException.');
        } catch (DPayValidationException $e) {
            self::assertNull($e->errors);
        }
    }

    /**
     * DPay's sandbox serves a branded HTML 404 page for unrouted paths
     * rather than a JSON error body — observed live against /auth/me,
     * /payments and /invoices. decode() must swallow the unparseable body
     * and still produce the status-mapped exception.
     */
    public function test_an_html_error_page_still_produces_the_status_mapped_exception(): void
    {
        $http = (new FakeHttpClient())->queueJson(404, '<!DOCTYPE html><html><body>Not Found</body></html>');

        try {
            $this->transport($http)->request('GET', '/auth/me');
            self::fail('Expected a DPaySessionNotFoundException.');
        } catch (DPaySessionNotFoundException $e) {
            self::assertSame(404, $e->httpStatus);
            self::assertSame('DPay request failed.', $e->getMessage());
            self::assertNull($e->errors);
        }
    }

    public function test_an_empty_error_body_falls_back_to_the_generic_message(): void
    {
        $http = (new FakeHttpClient())->queueJson(502, '');

        try {
            $this->transport($http)->request('GET', '/x');
            self::fail('Expected a DPayException.');
        } catch (DPayException $e) {
            self::assertSame('DPay request failed.', $e->getMessage());
            self::assertSame(502, $e->httpStatus);
        }
    }

    public function test_a_successful_response_with_a_non_array_json_body_decodes_to_an_empty_array(): void
    {
        // Valid JSON, but a scalar — json_decode succeeds and returns a string.
        $http = (new FakeHttpClient())->queueJson(200, '"just a string"');

        self::assertSame([], $this->transport($http)->request('GET', '/x'));
    }

    public function test_a_successful_response_with_an_empty_body_decodes_to_an_empty_array(): void
    {
        $http = (new FakeHttpClient())->queueJson(204, '');

        self::assertSame([], $this->transport($http)->request('DELETE', '/x'));
    }

    public function test_a_redirect_is_not_treated_as_success(): void
    {
        $http = (new FakeHttpClient())->queueJson(302, '');

        try {
            $this->transport($http)->request('GET', '/x');
            self::fail('Expected a DPayException.');
        } catch (DPayException $e) {
            self::assertSame(302, $e->httpStatus);
            // 3xx is outside every 4xx arm, so it lands on the generic default.
            self::assertSame(DPayException::class, $e::class);
        }
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
