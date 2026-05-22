<?php

declare(strict_types=1);

namespace DPay\Exceptions;

/**
 * Thrown when the underlying PSR-18 client throws (DNS, connect, timeout, TLS, etc.).
 */
class DPayNetworkException extends DPayException {}
