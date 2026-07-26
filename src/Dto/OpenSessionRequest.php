<?php

declare(strict_types=1);

namespace DPay\Dto;

/**
 * Input for DPayClient::openSession.
 *
 * Field names and types follow the official spec at https://dpay.ly/docs/api.
 *   - amount      : LYD. Decimals allowed, minimum 0.01. NEVER cast to int.
 *   - description : top-level field (MobiCash), NOT nested under data.
 *   - data        : free-form merchant metadata, echoed back in webhooks.
 *   - birthYear   : Sadad only, 4 digits, checked against the wallet record.
 *   - category    : Sadad only, 0-36. Zero is meaningful — the null filter
 *                   below must stay `!== null`, never a truthiness check.
 */
final class OpenSessionRequest
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public readonly string $payMethod,
        public readonly float $amount,
        public readonly ?string $customerMobile = null,
        public readonly ?string $cardNumber = null,
        public readonly ?string $birthYear = null,
        public readonly ?int $category = null,
        public readonly ?string $description = null,
        public readonly array $data = [],
    ) {}

    /**
     * Build the JSON body for /payment/sessions/open, stripping null fields.
     *
     * @return array<string, mixed>
     */
    public function toBody(): array
    {
        return array_filter([
            'pay_method' => $this->payMethod,
            'amount' => $this->amount,
            'customer_mobile' => $this->customerMobile,
            'card_number' => $this->cardNumber,
            'birth_year' => $this->birthYear,
            'category' => $this->category,
            'description' => $this->description,
            'data' => $this->data === [] ? null : $this->data,
        ], static fn ($v) => $v !== null);
    }
}
