<?php

declare(strict_types=1);

namespace DPay\Support;

use DPay\Dto\GetSessionResponse;
use DPay\Dto\OpenSessionRequest;
use DPay\Dto\OpenSessionResponse;
use DPay\Dto\SessionStatus;
use DPay\Dto\VerifySessionResponse;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;

/**
 * In-memory mock of DPay's three endpoints.
 *
 * Behavior is intentionally identical to the health-portal mock branch:
 *   - openSession returns a random session_id 1-99999 with a 2.5% fee
 *   - verifySession accepts any 4-6 digit numeric OTP, returns 'paid'
 *   - getSession returns 'paid' for any id
 *
 * Used both by DPayClient when DPayConfig::$mock is true, and as a unit-test
 * fixture so consumers can build deterministic suites without a sandbox.
 */
class MockTransport
{
    public function openSession(OpenSessionRequest $request): OpenSessionResponse
    {
        $fee = 2.5;
        $feeAmount = round($request->amount * $fee / 100, 2);

        $body = [
            'session_id' => random_int(1, 99999),
            'status' => SessionStatus::PENDING->value,
            'amount' => $request->amount,
            'currency' => 'LYD',
            'fee' => $fee,
            'fee_amount' => $feeAmount,
            'total' => $request->amount + $feeAmount,
            'pay_method' => $request->payMethod,
            'expired_at' => $this->thirtyMinutesFromNow(),
            'data' => null,
        ];

        return OpenSessionResponse::fromArray($body);
    }

    public function verifySession(int $sessionId, string $otp): ?VerifySessionResponse
    {
        if (! preg_match('/^\d{4,6}$/', $otp)) {
            return null;
        }

        return VerifySessionResponse::fromArray([
            'message' => 'Payment verified successfully',
            'payment_id' => random_int(1, 99999),
            'status' => SessionStatus::PAID->value,
            'amount' => 0,
            'currency' => 'LYD',
            'pay_method' => 'mock',
            'tx_id' => 'txn_'.$this->randomString(10),
        ]);
    }

    public function getSession(int $sessionId): GetSessionResponse
    {
        return GetSessionResponse::fromArray([
            'session_id' => $sessionId,
            'status' => SessionStatus::PAID->value,
            'amount' => 0,
            'currency' => 'LYD',
            'pay_method' => 'mock',
            'expired_at' => $this->thirtyMinutesFromNow(),
            'data' => null,
        ]);
    }

    private function thirtyMinutesFromNow(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->add(new DateInterval('PT30M'))
            ->format(DateTimeImmutable::ATOM);
    }

    private function randomString(int $length): string
    {
        $alphabet = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $out = '';
        $max = strlen($alphabet) - 1;
        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[random_int(0, $max)];
        }

        return $out;
    }
}
