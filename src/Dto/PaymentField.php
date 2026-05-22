<?php

declare(strict_types=1);

namespace DPay\Dto;

/**
 * Describes a single input the provider's sendOtp() expects in $fields.
 *
 * Framework-agnostic. The Laravel bridge converts a list of these into
 * validation rules via DPay\Laravel\PaymentFieldRules.
 *
 * Use the named constructors PaymentField::phoneNumber() and
 * PaymentField::cardNumber() for the common defaults — they ship en+ar
 * labels and placeholders matching the health-portal seeder shape.
 */
final class PaymentField
{
    /**
     * @param  array<string, string>  $labels        ['en' => 'Phone Number', 'ar' => 'رقم الهاتف']
     * @param  array<string, string>  $placeholders  ['en' => '09xxxxxxxx', 'ar' => '09xxxxxxxx']
     */
    public function __construct(
        public readonly string $key,
        public readonly string $type = 'string',
        public readonly bool $required = true,
        public readonly ?string $regex = null,
        public readonly ?int $digits = null,
        public readonly array $labels = [],
        public readonly array $placeholders = [],
        public readonly string $inputType = 'text',
    ) {}

    /**
     * Libyan mobile-number field. Matches the health-portal default:
     *   regex /^09\d{8}$/, en+ar labels and placeholders, input type "tel".
     */
    public static function phoneNumber(
        string $key = 'phone_number',
        ?string $regex = '/^09\d{8}$/',
    ): self {
        return new self(
            key: $key,
            type: 'string',
            required: true,
            regex: $regex,
            labels: ['en' => 'Phone Number', 'ar' => 'رقم الهاتف'],
            placeholders: ['en' => '09xxxxxxxx', 'ar' => '09xxxxxxxx'],
            inputType: 'tel',
        );
    }

    /**
     * Card-number field with an exact digit count. Health-portal defaults:
     *   7 digits for MobiCash/SaharaPay/YousrPay/MasrefyPay,
     *   16 digits for the legacy Moamalat card-link flow.
     */
    public static function cardNumber(int $digits = 7, string $key = 'card_number'): self
    {
        $placeholder = $digits === 16 ? '#### #### #### ####' : str_repeat('#', $digits);

        return new self(
            key: $key,
            type: 'string',
            required: true,
            digits: $digits,
            labels: ['en' => 'Card Number', 'ar' => 'رقم البطاقة'],
            placeholders: ['en' => $placeholder, 'ar' => $placeholder],
            inputType: 'number',
        );
    }

    public function label(string $locale = 'en'): string
    {
        return (string) ($this->labels[$locale] ?? $this->labels['en'] ?? $this->key);
    }

    public function placeholder(string $locale = 'en'): string
    {
        return (string) ($this->placeholders[$locale] ?? $this->placeholders['en'] ?? '');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'type' => $this->type,
            'required' => $this->required,
            'regex' => $this->regex,
            'digits' => $this->digits,
            'labels' => $this->labels,
            'placeholders' => $this->placeholders,
            'input_type' => $this->inputType,
        ];
    }

    /**
     * @param  array<string, mixed>  $a
     */
    public static function fromArray(array $a): self
    {
        return new self(
            key: (string) ($a['key'] ?? ''),
            type: (string) ($a['type'] ?? 'string'),
            required: (bool) ($a['required'] ?? true),
            regex: isset($a['regex']) ? (string) $a['regex'] : null,
            digits: isset($a['digits']) ? (int) $a['digits'] : null,
            labels: (array) ($a['labels'] ?? []),
            placeholders: (array) ($a['placeholders'] ?? []),
            inputType: (string) ($a['input_type'] ?? 'text'),
        );
    }
}
