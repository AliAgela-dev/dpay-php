<?php

declare(strict_types=1);

namespace DPay\Laravel\Facades;

use DPay\Client\DPayClientInterface;
use DPay\Contracts\PaymentProviderInterface;
use DPay\Dto\GetSessionResponse;
use DPay\Dto\OpenSessionResponse;
use DPay\Dto\VerifySessionResponse;
use DPay\GatewayManager;
use Illuminate\Support\Facades\Facade;

/**
 * Convenience facade combining the DPay client + GatewayManager.
 *
 * @method static OpenSessionResponse          openSession(string $payMethod, float $amount, ?string $customerMobile = null, ?string $cardNumber = null, ?string $description = null)
 * @method static VerifySessionResponse|null   verifySession(int $sessionId, string $otp)
 * @method static GetSessionResponse           getSession(int $sessionId)
 * @method static PaymentProviderInterface     provider(string $code)
 * @method static bool                         isEnabled(string $code)
 * @method static bool                         requiresOtp(string $code)
 * @method static array                        features(string $code)
 * @method static list<string>                 listEnabled()
 */
class DPay extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return DPayFacadeAccessor::class;
    }
}
