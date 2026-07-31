<?php

declare(strict_types=1);

namespace DPay\Exceptions;

use InvalidArgumentException;

/**
 * Thrown by GatewayManager when an unknown or disabled provider code is requested.
 */
class UnknownProviderException extends InvalidArgumentException implements DPayExceptionInterface {}
