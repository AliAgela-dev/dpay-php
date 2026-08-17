<?php

declare(strict_types=1);

namespace DPay\Client;

use DPay\Config\DPayConfig;
use DPay\Dto\GetSessionResponse;
use DPay\Dto\OpenSessionRequest;
use DPay\Dto\OpenSessionResponse;
use DPay\Dto\VerifySessionResponse;
use DPay\Exceptions\DPayExceptionInterface;
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
        /**
         * Optional. When supplied, openSession() checks the amount against
         * DPay's live per-gateway limits before opening a session. Omit it
         * (the default) and behaviour is exactly as it was — this is
         * additive, never a breaking change for existing callers.
         */
        private readonly ?PayMethodsClient $payMethods = null,
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

        $this->assertWithinLiveLimits($request);

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

    /**
     * Check the amount against DPay's live, per-merchant limits for this
     * gateway — but only when a PayMethodsClient was supplied.
     *
     * **Fails open by design.** If the lookup itself fails, the payment
     * proceeds. This endpoint is a convenience, and letting an outage on it
     * block real payments would trade revenue for a check DPay performs
     * server-side anyway — the caller simply gets DPay's rejection instead
     * of a faster local one, which is exactly the pre-v0.4.0 behaviour.
     * Transport has already logged the failure by the time we swallow it.
     *
     * A gateway absent from the list is likewise not an error: unknown is
     * not the same as disabled, and only an explicit `active: false` is
     * treated as a refusal.
     */
    private function assertWithinLiveLimits(OpenSessionRequest $request): void
    {
        if ($this->payMethods === null) {
            return;
        }

        try {
            $method = $this->payMethods->find($request->payMethod);
        } catch (DPayExceptionInterface) {
            return;
        }

        if ($method === null) {
            return;
        }

        if (! $method->active) {
            throw new DPayValidationException(
                sprintf(
                    'DPay reports the "%s" gateway as not enabled for this merchant account. '
                    .'Enable it from DPay\'s dashboard, or use a different pay method.',
                    $method->slug,
                ),
                422,
            );
        }

        if ($request->amount < $method->minDeposit) {
            throw new DPayValidationException(
                sprintf(
                    'Amount %s is below DPay\'s minimum deposit of %s for the "%s" gateway.',
                    $request->amount,
                    $method->minDeposit,
                    $method->slug,
                ),
                422,
            );
        }

        if ($request->amount > $method->maxDeposit) {
            throw new DPayValidationException(
                sprintf(
                    'Amount %s exceeds DPay\'s maximum deposit of %s for the "%s" gateway.',
                    $request->amount,
                    $method->maxDeposit,
                    $method->slug,
                ),
                422,
            );
        }
    }
}
