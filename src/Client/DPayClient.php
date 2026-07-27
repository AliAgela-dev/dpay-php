<?php

declare(strict_types=1);

namespace DPay\Client;

use DPay\Config\DPayConfig;
use DPay\Dto\GetSessionResponse;
use DPay\Dto\OpenSessionRequest;
use DPay\Dto\OpenSessionResponse;
use DPay\Dto\VerifySessionResponse;
use DPay\Exceptions\DPayValidationException;
use DPay\Http\Transport;
use DPay\Support\MockTransport;

/**
 * Client for DPay's three payment-session endpoints.
 *
 * HTTP plumbing lives in DPay\Http\Transport; this class owns endpoint
 * semantics only.
 *
 * Behaviours preserved from v0.1.0:
 *   - configurable min_amount enforcement
 *   - mock branch returning synthetic responses
 *   - verifySession returns null (not throws) on bad OTP / expired
 *
 * Changed in v0.2.0 to match https://dpay.ly/docs/api:
 *   - fractional amounts are ALLOWED (spec minimum is 0.01)
 *   - openSession takes an OpenSessionRequest and an optional Idempotency-Key
 */
class DPayClient implements DPayClientInterface
{
    public function __construct(
        private readonly DPayConfig $config,
        private readonly Transport $transport,
        private readonly ?MockTransport $mockTransport = null,
    ) {}

    public function openSession(OpenSessionRequest $request, ?string $idempotencyKey = null): OpenSessionResponse
    {
        if ($this->config->mock) {
            return ($this->mockTransport ?? new MockTransport())->openSession($request);
        }

        if ($request->amount < $this->config->minAmount) {
            throw new DPayValidationException(
                "Amount is below the minimum of {$this->config->minAmount}.",
                422,
            );
        }

        $headers = $idempotencyKey === null ? [] : ['Idempotency-Key' => $idempotencyKey];

        return OpenSessionResponse::fromArray(
            $this->transport->request('POST', '/payment/sessions/open', $request->toBody(), $headers),
        );
    }

    public function verifySession(int $sessionId, string $otp): ?VerifySessionResponse
    {
        if ($this->config->mock) {
            return ($this->mockTransport ?? new MockTransport())->verifySession($sessionId, $otp);
        }

        $body = $this->transport->attempt('POST', '/payment/sessions/verify', [
            'session_id' => $sessionId,
            'otp' => $otp,
        ]);

        return $body === null ? null : VerifySessionResponse::fromArray($body);
    }

    public function getSession(int $sessionId): GetSessionResponse
    {
        if ($this->config->mock) {
            return ($this->mockTransport ?? new MockTransport())->getSession($sessionId);
        }

        return GetSessionResponse::fromArray(
            $this->transport->request('GET', "/payment/sessions/{$sessionId}"),
        );
    }
}
