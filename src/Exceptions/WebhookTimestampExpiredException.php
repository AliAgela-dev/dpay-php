<?php

declare(strict_types=1);

namespace DPay\Exceptions;

/**
 * X-DPAY-Timestamp is more than 5 minutes from now, in either direction.
 *
 * Protects against replaying a captured request. A legitimate webhook
 * should never have a future timestamp either — that almost certainly
 * means clock skew or a forged request, not a delivery delay.
 */
class WebhookTimestampExpiredException extends InvalidWebhookException {}
