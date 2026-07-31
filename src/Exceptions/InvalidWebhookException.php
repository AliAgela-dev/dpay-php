<?php

declare(strict_types=1);

namespace DPay\Exceptions;

/**
 * Base for webhook verification failures.
 *
 * A controller catching this (rather than the two concrete subclasses)
 * can't tell signature mismatch from a stale timestamp — but either way
 * the correct response is the same: reject the request, don't process it.
 *
 * NEVER put the expected signature or the webhook secret in any message
 * on this hierarchy. An attacker controls the request that triggers these
 * exceptions; anything logged here is something they could read back.
 */
class InvalidWebhookException extends DPayException {}
