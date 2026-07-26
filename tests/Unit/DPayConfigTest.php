<?php

declare(strict_types=1);

namespace DPay\Tests\Unit;

use DPay\Config\DPayConfig;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class DPayConfigTest extends TestCase
{
    public function test_min_amount_defaults_to_the_spec_minimum(): void
    {
        self::assertSame(0.01, (new DPayConfig())->minAmount);
    }

    public function test_min_amount_accepts_a_fractional_value(): void
    {
        self::assertSame(2.5, (new DPayConfig(minAmount: 2.5))->minAmount);
    }

    public function test_from_array_reads_a_fractional_min_amount(): void
    {
        self::assertSame(0.5, DPayConfig::fromArray(['min_amount' => 0.5])->minAmount);
    }

    public function test_negative_min_amount_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new DPayConfig(minAmount: -1.0);
    }

    public function test_zero_disables_the_preflight_floor(): void
    {
        // 0 is deliberately allowed: it opts out of client-side checking and
        // defers validity entirely to DPay. See DPayConfig::$minAmount.
        self::assertSame(0.0, (new DPayConfig(minAmount: 0))->minAmount);
    }

    public function test_a_floor_below_the_spec_minimum_is_accepted(): void
    {
        // The SDK does not police the gateway's rule; it only pre-filters.
        self::assertSame(0.001, (new DPayConfig(minAmount: 0.001))->minAmount);
    }

    public function test_from_array_casts_a_string_config_value(): void
    {
        // env() hands back strings, so this path matters in the Laravel bridge.
        self::assertSame(0.5, DPayConfig::fromArray(['min_amount' => '0.5'])->minAmount);
    }
}
