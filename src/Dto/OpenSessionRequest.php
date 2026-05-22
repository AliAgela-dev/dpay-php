<?php

declare(strict_types=1);

namespace DPay\Dto;

/**
 * Input for DPayClient::openSession.
 *
 * Field semantics match the health-portal implementation:
 *   - payMethod       : the exact string DPay's /payment/sessions/open expects
 *                       in its `pay_method` field (e.g. 'edfali', 'mobicash').
 *   - amount          : whole-number LYD amount. Fractional values are rejected.
 *   - customerMobile  : end-user mobile (required by the Edfali flow).
 *   - cardNumber      : card number (used by MobiCash / Sahara / Yousr / Masrefy).
 *   - description     : free-text description, sent as `data.description`.
 */
final class OpenSessionRequest
{
    public function __construct(
        public readonly string $payMethod,
        public readonly float $amount,
        public readonly ?string $customerMobile = null,
        public readonly ?string $cardNumber = null,
        public readonly ?string $description = null,
    ) {}

    /**
     * Build the JSON body sent to /payment/sessions/open, with null fields stripped.
     *
     * @return array<string, mixed>
     */
    public function toBody(): array
    {
        return array_filter([
            'pay_method' => $this->payMethod,
            'amount' => (int) $this->amount,
            'customer_mobile' => $this->customerMobile,
            'card_number' => $this->cardNumber,
            'data' => $this->description !== null ? ['description' => $this->description] : null,
        ], static fn ($v) => $v !== null);
    }
}
