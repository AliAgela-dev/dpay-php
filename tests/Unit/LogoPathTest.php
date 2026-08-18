<?php

declare(strict_types=1);

namespace DPay\Tests\Unit;

use DPay\Client\DPayClientInterface;
use DPay\Contracts\PaymentProviderInterface;
use DPay\Providers\EdfaliProvider;
use DPay\Providers\MasrefyPayProvider;
use DPay\Providers\MoamalatProvider;
use DPay\Providers\MobiCashProvider;
use DPay\Providers\SadadProvider;
use DPay\Providers\SaharaPayProvider;
use DPay\Providers\YousrPayProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * logo() must return a path that actually resolves to where the Laravel
 * bridge publishes the bundled SVGs (`public/vendor/dpay/`).
 *
 * It previously returned `images/payment-methods/<code>.svg`, which
 * resolved to nothing — docs/checkout-flow.md worked around it by building
 * the correct path by hand rather than calling this method.
 */
final class LogoPathTest extends TestCase
{
    /** @return array<string, array{class-string<PaymentProviderInterface>}> */
    public static function providers(): array
    {
        return [
            'edfali' => [EdfaliProvider::class],
            'mobicash' => [MobiCashProvider::class],
            'saharapay' => [SaharaPayProvider::class],
            'yousrpay' => [YousrPayProvider::class],
            'masrefypay' => [MasrefyPayProvider::class],
            'sadad' => [SadadProvider::class],
            'moamalat' => [MoamalatProvider::class],
        ];
    }

    /** @param class-string<PaymentProviderInterface> $class */
    #[DataProvider('providers')]
    public function test_logo_points_at_the_bridges_publish_location(string $class): void
    {
        $provider = new $class($this->createStub(DPayClientInterface::class), 'x');

        self::assertSame(
            'vendor/dpay/'.$provider->code().'.svg',
            $provider->logo(),
            'logo() must match where DPayServiceProvider publishes the SVGs.',
        );
    }

    /** @param class-string<PaymentProviderInterface> $class */
    #[DataProvider('providers')]
    public function test_the_bundled_svg_actually_exists_under_that_name(string $class): void
    {
        $provider = new $class($this->createStub(DPayClientInterface::class), 'x');

        // The published filename is the basename of logo(). If the bundled
        // asset isn't named that, the path resolves to a 404 even once the
        // publish step has run.
        self::assertFileExists(
            dirname(__DIR__, 2).'/resources/logos/'.basename($provider->logo()),
        );
    }
}
