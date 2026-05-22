<?php

declare(strict_types=1);

namespace DPay\Exceptions;

/**
 * Thrown for 401/403 responses from DPay (missing/expired/invalid API key).
 */
class DPayAuthException extends DPayException {}
