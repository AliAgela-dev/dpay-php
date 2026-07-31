<?php

declare(strict_types=1);

namespace DPay\Tests\Unit\Exceptions;

use DPay\Exceptions\DPayExceptionInterface;
use DPay\Exceptions\InvalidWebhookException;
use DPay\Exceptions\WebhookSignatureMismatchException;
use DPay\Exceptions\WebhookTimestampExpiredException;
use PHPUnit\Framework\TestCase;

final class InvalidWebhookExceptionTest extends TestCase
{
    public function test_signature_mismatch_is_an_invalid_webhook_exception(): void
    {
        $e = new WebhookSignatureMismatchException('bad signature');

        self::assertInstanceOf(InvalidWebhookException::class, $e);
        self::assertInstanceOf(DPayExceptionInterface::class, $e);
    }

    public function test_timestamp_expired_is_an_invalid_webhook_exception(): void
    {
        $e = new WebhookTimestampExpiredException('too old');

        self::assertInstanceOf(InvalidWebhookException::class, $e);
        self::assertInstanceOf(DPayExceptionInterface::class, $e);
    }

    public function test_one_catch_block_covers_both_failure_modes(): void
    {
        $caught = 0;

        foreach ([new WebhookSignatureMismatchException('x'), new WebhookTimestampExpiredException('y')] as $e) {
            try {
                throw $e;
            } catch (InvalidWebhookException) {
                $caught++;
            }
        }

        self::assertSame(2, $caught);
    }
}
