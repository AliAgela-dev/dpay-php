<?php

declare(strict_types=1);

namespace DPay\Tests\Unit;

use DPay\Exceptions\DPayAuthException;
use DPay\Exceptions\DPayExceptionInterface;
use DPay\Exceptions\UnknownProviderException;
use PHPUnit\Framework\TestCase;

final class ExceptionHierarchyTest extends TestCase
{
    public function test_dpay_exceptions_implement_the_marker(): void
    {
        self::assertInstanceOf(DPayExceptionInterface::class, new DPayAuthException('nope', 401));
    }

    public function test_unknown_provider_exception_implements_the_marker(): void
    {
        self::assertInstanceOf(DPayExceptionInterface::class, new UnknownProviderException('nope'));
    }

    public function test_one_catch_block_covers_both_trees(): void
    {
        $caught = 0;

        foreach ([new DPayAuthException('a', 401), new UnknownProviderException('b')] as $e) {
            try {
                throw $e;
            } catch (DPayExceptionInterface) {
                $caught++;
            }
        }

        self::assertSame(2, $caught);
    }
}
