<?php

declare(strict_types=1);

namespace DPay\Tests\Unit;

use DPay\Dto\SessionStatus;
use PHPUnit\Framework\TestCase;

final class SessionStatusTest extends TestCase
{
    public function test_voided_is_a_known_status(): void
    {
        self::assertSame(SessionStatus::VOIDED, SessionStatus::fromString('voided'));
    }

    public function test_voided_is_terminal(): void
    {
        self::assertTrue(SessionStatus::VOIDED->isTerminal());
    }

    public function test_unrecognised_status_still_degrades_to_unknown(): void
    {
        self::assertSame(SessionStatus::UNKNOWN, SessionStatus::fromString('wat'));
    }
}
