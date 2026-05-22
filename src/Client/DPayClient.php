<?php

declare(strict_types=1);

namespace DPay\Client;

use DPay\Config\DPayConfig;
use DPay\Dto\GetSessionResponse;
use DPay\Dto\OpenSessionRequest;
use DPay\Dto\OpenSessionResponse;
use DPay\Dto\VerifySessionResponse;
use DPay\Exceptions\DPayAuthException;
use DPay\Exceptions\DPayException;
use DPay\Exceptions\DPayNetworkException;
use DPay\Exceptions\DPayRateLimitException;
use DPay\Exceptions\DPaySessionNotFoundException;
use DPay\Exceptions\DPayValidationException;
use DPay\Support\MockTransport;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Framework-agnostic client for the DPay gateway.
 *
 * Ported 1:1 from the Laravel implementation in
 *   app/Services/Payment/Providers/DPay/DPayClient.php
 *
 * Behaviors preserved:
 *   - Whole-number amount enforcement (fractional => DPayValidationException)
 *   - Configurable min_amount enforcement (default 5)
 *   - Bearer-token auth, JSON request/response
 *   - Mock branch returning synthetic responses (random session_id,
 *     4-6 digit OTP accepted)
 *   - verifySession returns null (not throws) on bad OTP/expired
 *
 * Behaviors added:
 *   - Typed responses (DTOs) instead of raw arrays. Use ->toArray() on
 *     a DTO if you need the raw shape.
 *   - Granular exceptions (Auth/Validation/SessionNotFound/Network) all
 *     extending DPayException so existing catch blocks keep working.
 */
class DPayClient implements DPayClientInterface
{
    public function __construct(
        private readonly DPayConfig $config,
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly ?MockTransport $mockTransport = null,
    ) {}

    public function openSession(
        string $payMethod,
        float $amount,
        ?string $customerMobile = null,
        ?string $cardNumber = null,
        ?string $description = null,
    ): OpenSessionResponse {
        $request = new OpenSessionRequest(
            payMethod: $payMethod,
            amount: $amount,
            customerMobile: $customerMobile,
            cardNumber: $cardNumber,
            description: $description,
        );

        if ($this->config->mock) {
            return ($this->mockTransport ?? new MockTransport())->openSession($request);
        }

        // DPay requires a whole-number amount. Reject fractional values here
        // rather than silently truncating (which would steal money from the
        // user or the platform).
        if (floor($amount) !== (float) $amount) {
            throw new DPayValidationException(
                'Amount must be a whole number for this payment provider.',
                422,
            );
        }

        if ($amount < $this->config->minAmount) {
            throw new DPayValidationException(
                "Amount is below the minimum of {$this->config->minAmount}.",
                422,
            );
        }

        $response = $this->send('POST', '/payment/sessions/open', $request->toBody());

        if (! $this->isSuccessful($response)) {
            $body = $this->decode($response);
            $message = (string) ($body['message'] ?? 'Failed to create payment session.');

            $this->logger->error('DPay openSession failed', [
                'status' => $response->getStatusCode(),
                'message' => $message,
                'pay_method' => $payMethod,
            ]);

            throw $this->buildException($response->getStatusCode(), $message, $body);
        }

        return OpenSessionResponse::fromArray($this->decode($response));
    }

    public function verifySession(int $sessionId, string $otp): ?VerifySessionResponse
    {
        if ($this->config->mock) {
            return ($this->mockTransport ?? new MockTransport())->verifySession($sessionId, $otp);
        }

        $response = $this->send('POST', '/payment/sessions/verify', [
            'session_id' => $sessionId,
            'otp' => $otp,
        ]);

        if (! $this->isSuccessful($response)) {
            $this->logger->warning('DPay verifySession failed', [
                'status' => $response->getStatusCode(),
                'message' => (string) ($this->decode($response)['message'] ?? ''),
                'session_id' => $sessionId,
            ]);

            // Wrong OTP, expired session, not found — all return null so the
            // provider's verifyOtp() returns false gracefully without forcing
            // callers to catch exceptions for a normal user-error flow.
            return null;
        }

        return VerifySessionResponse::fromArray($this->decode($response));
    }

    public function getSession(int $sessionId): GetSessionResponse
    {
        if ($this->config->mock) {
            return ($this->mockTransport ?? new MockTransport())->getSession($sessionId);
        }

        $response = $this->send('GET', "/payment/sessions/{$sessionId}", null);

        if (! $this->isSuccessful($response)) {
            $body = $this->decode($response);
            $message = (string) ($body['message'] ?? 'Payment session not found.');

            $this->logger->error('DPay getSession failed', [
                'status' => $response->getStatusCode(),
                'message' => $message,
                'session_id' => $sessionId,
            ]);

            throw $this->buildException($response->getStatusCode(), $message, $body);
        }

        return GetSessionResponse::fromArray($this->decode($response));
    }

    /**
     * @param  array<string, mixed>|null  $body
     */
    private function send(string $method, string $path, ?array $body): ResponseInterface
    {
        $url = rtrim($this->config->baseUrl, '/').$path;

        $request = $this->requestFactory->createRequest($method, $url)
            ->withHeader('Accept', 'application/json')
            ->withHeader('Authorization', 'Bearer '.$this->config->apiKey);

        if ($body !== null) {
            $payload = json_encode($body, JSON_THROW_ON_ERROR);
            $request = $request
                ->withHeader('Content-Type', 'application/json')
                ->withBody($this->streamFactory->createStream($payload));
        }

        try {
            return $this->httpClient->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            $this->logger->error('DPay transport failure', [
                'method' => $method,
                'path' => $path,
                'error' => $e->getMessage(),
            ]);

            throw new DPayNetworkException(
                'Failed to reach DPay: '.$e->getMessage(),
                0,
                null,
                $e,
            );
        }
    }

    private function isSuccessful(ResponseInterface $response): bool
    {
        $status = $response->getStatusCode();

        return $status >= 200 && $status < 300;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(ResponseInterface $response): array
    {
        $body = (string) $response->getBody();

        if ($body === '') {
            return [];
        }

        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function buildException(int $status, string $message, array $body): DPayException
    {
        $errors = isset($body['errors']) && is_array($body['errors']) ? $body['errors'] : null;

        return match (true) {
            $status === 401 || $status === 403 => new DPayAuthException($message, $status, $errors),
            $status === 404 => new DPaySessionNotFoundException($message, $status, $errors),
            $status === 429 => new DPayRateLimitException($message, $status, $errors),
            $status >= 400 && $status < 500 => new DPayValidationException($message, $status, $errors),
            default => new DPayException($message, $status, $errors),
        };
    }
}
