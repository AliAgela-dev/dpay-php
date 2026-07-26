<?php

declare(strict_types=1);

namespace DPay\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Base exception for all DPay SDK errors.
 *
 * Carries the human-readable message from DPay's JSON response so callers
 * can surface it to end users (e.g. as a ValidationException in Laravel).
 */
class DPayException extends RuntimeException implements DPayExceptionInterface
{
    /**
     * @param  array<string, mixed>|null  $errors  Optional field-level errors from DPay's response.
     */
    public function __construct(
        string $message,
        public readonly int $httpStatus = 0,
        public readonly ?array $errors = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $httpStatus, $previous);
    }
}
