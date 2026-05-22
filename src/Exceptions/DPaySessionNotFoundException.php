<?php

declare(strict_types=1);

namespace DPay\Exceptions;

/**
 * Thrown when DPay returns 404 for getSession (session id unknown / purged).
 */
class DPaySessionNotFoundException extends DPayException {}
