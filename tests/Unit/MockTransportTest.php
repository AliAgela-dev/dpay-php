<?php

declare(strict_types=1);

namespace DPay\Tests\Unit;

use DPay\Dto\OpenSessionRequest;
use DPay\Support\MockTransport;
use PHPUnit\Framework\TestCase;

final class MockTransportTest extends TestCase
{
    public function test_decline_code_returns_null(): void
    {
        self::assertNull((new MockTransport())->verifySession(1, '000000'));
    }

    public function test_fixed_sandbox_otp_succeeds(): void
    {
        self::assertTrue((new MockTransport())->verifySession(1, '111111')?->isPaid());
    }

    public function test_non_numeric_otp_returns_null(): void
    {
        self::assertNull((new MockTransport())->verifySession(1, 'abcd'));
    }

    public function test_moamalat_expires_in_ten_minutes(): void
    {
        $response = (new MockTransport())->openSession(
            new OpenSessionRequest(payMethod: 'moamalat', amount: 50),
        );

        $minutes = (strtotime($response->expiredAt) - time()) / 60;

        self::assertEqualsWithDelta(10, $minutes, 0.05);
    }

    public function test_other_gateways_expire_in_fifteen_minutes(): void
    {
        $response = (new MockTransport())->openSession(
            new OpenSessionRequest(payMethod: 'edfali', amount: 50),
        );

        $minutes = (strtotime($response->expiredAt) - time()) / 60;

        self::assertEqualsWithDelta(15, $minutes, 0.05);
    }

    public function test_sadad_expires_in_ten_minutes(): void
    {
        $response = (new MockTransport())->openSession(
            new OpenSessionRequest(payMethod: 'sadad', amount: 50),
        );

        $minutes = (strtotime($response->expiredAt) - time()) / 60;

        self::assertEqualsWithDelta(10, $minutes, 0.05);
    }

    public function test_decimal_amount_is_preserved(): void
    {
        $response = (new MockTransport())->openSession(
            new OpenSessionRequest(payMethod: 'edfali', amount: 10.5),
        );

        self::assertSame(10.5, $response->amount);
    }
}
