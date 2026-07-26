<?php

declare(strict_types=1);

namespace DPay\Dto;

use InvalidArgumentException;

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
        /**
         * @var list<int>|null Exact digit counts, any of which is valid
         *      (bank cards: 7 or 9). An empty array means "no digit
         *      constraint" — PaymentFieldRules emits no regex rule for it.
         */
        public readonly ?array $digitsOneOf = null,
        public readonly array $labels = [],
        public readonly array $placeholders = [],
        public readonly string $inputType = 'text',
        /** Wire field name sent to DPay. Defaults to $key. */
        public readonly ?string $sendAs = null,
    ) {
        if ($digits !== null && $digitsOneOf !== null) {
            throw new InvalidArgumentException(
                'PaymentField cannot set both digits and digitsOneOf — they would '
                .'generate mutually unsatisfiable validation rules. Use digitsOneOf '
                .'for a field accepting several lengths, digits for exactly one.',
            );
        }
    }

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

    /**
     * Bank card for MasrefyPay / YousrPay / SaharaPay.
     * 7 digits same-bank, or 9 digits cross-bank via OnePay
     * (2-digit bank prefix + 7). MobiCash is 7-only — use cardNumber().
     */
    public static function bankCardNumber(string $key = 'card_number'): self
    {
        return new self(
            key: $key,
            type: 'string',
            required: true,
            digitsOneOf: [7, 9],
            labels: ['en' => 'Card Number', 'ar' => 'رقم البطاقة'],
            placeholders: ['en' => '7 or 9 digits', 'ar' => '7 أو 9 أرقام'],
            inputType: 'number',
        );
    }

    /**
     * Sadad year of birth. Cross-checked against the wallet registration record.
     */
    public static function birthYear(string $key = 'birth_year'): self
    {
        return new self(
            key: $key,
            type: 'string',
            required: true,
            digits: 4,
            labels: ['en' => 'Year of Birth', 'ar' => 'سنة الميلاد'],
            placeholders: ['en' => '1994', 'ar' => '1994'],
            inputType: 'number',
        );
    }

    /**
     * Sadad service category (0-36). Optional — omitting it uses the
     * merchant's configured default.
     */
    public static function sadadCategory(string $key = 'category'): self
    {
        return new self(
            key: $key,
            type: 'integer',
            required: false,
            labels: ['en' => 'Service Category', 'ar' => 'فئة الخدمة'],
            placeholders: ['en' => '20', 'ar' => '20'],
            inputType: 'number',
        );
    }

    /**
     * The field name as DPay expects it on the wire.
     */
    public function wireName(): string
    {
        return $this->sendAs ?? $this->key;
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
            'digits_one_of' => $this->digitsOneOf,
            'labels' => $this->labels,
            'placeholders' => $this->placeholders,
            'input_type' => $this->inputType,
            // Deliberately the RESOLVED name, not the raw nullable $sendAs:
            // JSON consumers must always get a concrete wire name rather than
            // having to replicate the null-coalesce. Do not "simplify" this.
            'send_as' => $this->wireName(),
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
            digitsOneOf: isset($a['digits_one_of']) && is_array($a['digits_one_of'])
                ? array_values(array_map(static fn ($d): int => (int) $d, $a['digits_one_of']))
                : null,
            labels: (array) ($a['labels'] ?? []),
            placeholders: (array) ($a['placeholders'] ?? []),
            inputType: (string) ($a['input_type'] ?? 'text'),
            sendAs: isset($a['send_as']) ? (string) $a['send_as'] : null,
        );
    }
}
