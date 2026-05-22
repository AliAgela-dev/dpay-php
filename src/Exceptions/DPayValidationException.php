<?php

declare(strict_types=1);

namespace DPay\Exceptions;

/**
 * Thrown for 4xx client-side validation errors:
 * - amount below min_amount
 * - fractional amount
 * - invalid request body rejected by DPay (422)
 */
class DPayValidationException extends DPayException {}
