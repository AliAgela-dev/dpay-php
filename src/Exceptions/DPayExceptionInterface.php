<?php

declare(strict_types=1);

namespace DPay\Exceptions;

use Throwable;

/**
 * Marker implemented by every exception this SDK throws.
 *
 * DPayException extends RuntimeException while UnknownProviderException
 * extends InvalidArgumentException, so no single class sits above both.
 * Catch this interface to handle any SDK failure in one block.
 */
interface DPayExceptionInterface extends Throwable {}
