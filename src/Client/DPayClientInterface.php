<?php

declare(strict_types=1);

namespace DPay\Client;

use DPay\Dto\GetSessionResponse;
use DPay\Dto\OpenSessionResponse;
use DPay\Dto\VerifySessionResponse;
use DPay\Exceptions\DPayException;

/**
 * Contract for the underlying DPay HTTP client.
 *
 * Exposed as an interface so providers and the GatewayManager can be
 * type-hinted against the abstraction, and so tests can swap in fakes
 * without touching the network.
 */
interface DPayClientInterface
{
    /**
     * Open a payment session on DPay.
     *
     * @throws DPayException on validation errors, auth failures, or network issues
     */
    public function openSession(
        string $payMethod,
        float $amount,
        ?string $customerMobile = null,
        ?string $cardNumber = null,
        ?string $description = null,
    ): OpenSessionResponse;

    /**
     * Verify a payment session with OTP.
     *
     * Returns the payment data on success, or null when the OTP is
     * invalid / the session is expired / not found. This lets callers
     * treat verification as a boolean instead of catching exceptions
     * for a normal user-error flow.
     */
    public function verifySession(int $sessionId, string $otp): ?VerifySessionResponse;

    /**
     * Retrieve a payment session by ID.
     *
     * @throws DPayException when the session is not found or auth fails
     */
    public function getSession(int $sessionId): GetSessionResponse;
}
