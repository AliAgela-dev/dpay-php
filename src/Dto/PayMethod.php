<?php

declare(strict_types=1);

namespace DPay\Dto;

/**
 * One entry from GET /pay-methods.
 *
 * DPay documents this endpoint as returning "all active payment methods with
 * your merchant-specific fee overrides applied" — so `fee`, `minDeposit` and
 * `maxDeposit` are **per-merchant**, configured from DPay's dashboard, and
 * are not constants this SDK could ever hardcode.
 *
 * `fee` is a percentage (e.g. 2.5 for 2.5%), matching
 * OpenSessionResponse::$fee. It is not an absolute amount.
 */
final class PayMethod
{
    public function __construct(
        public readonly string $name,
        public readonly string $slug,
        public readonly bool $active,
        public readonly float $fee,
        public readonly float $minDeposit,
        public readonly float $maxDeposit,
        public readonly ?string $logoUrl = null,
        /** @deprecated upstream in favour of logoUrl; kept because DPay still sends it. */
        public readonly ?string $icon = null,
        /** @var array<string, mixed> raw entry, so unmapped fields are never lost */
        public readonly array $raw = [],
    ) {}

    /**
     * @param  array<string, mixed>  $body
     */
    public static function fromArray(array $body): self
    {
        return new self(
            name: self::toStringOrEmpty($body['name'] ?? null),
            slug: self::toStringOrEmpty($body['slug'] ?? null),
            active: (bool) ($body['active'] ?? false),
            fee: self::toFloat($body['fee'] ?? null),
            minDeposit: self::toFloat($body['min_deposit'] ?? null),
            maxDeposit: self::toFloat($body['max_deposit'] ?? null),
            logoUrl: self::toNullableString($body['logo_url'] ?? null),
            icon: self::toNullableString($body['icon'] ?? null),
            raw: $body,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->raw !== [] ? $this->raw : [
            'name' => $this->name,
            'slug' => $this->slug,
            'icon' => $this->icon,
            'logo_url' => $this->logoUrl,
            'active' => $this->active,
            'fee' => $this->fee,
            'min_deposit' => $this->minDeposit,
            'max_deposit' => $this->maxDeposit,
        ];
    }

    /**
     * Anything non-scalar is treated as absent rather than converted — the
     * suite runs failOnWarning, and "Array to string conversion" would
     * otherwise turn a surprising payload into a test failure or, under
     * Laravel, an uncaught ErrorException.
     */
    private static function toStringOrEmpty(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private static function toNullableString(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }

    /**
     * DPay sends the deposit limits as JSON integers. Amounts are floats
     * end-to-end in this SDK, so normalise here rather than relying on
     * PHP's juggling at the comparison site.
     */
    private static function toFloat(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }
}
