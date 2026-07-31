<?php

declare(strict_types=1);

namespace DPay\Laravel;

use DPay\Contracts\PaymentProviderInterface;
use DPay\Dto\PaymentField;

/**
 * Converts a provider's PaymentField[] schema into Laravel validation
 * rules + attribute names, so $request->validate() works against the
 * same schema the frontend uses.
 *
 * Mirrors the behavior of the health-portal's PaymentFieldValidator so
 * existing controllers can drop in this helper with a one-line change.
 *
 * Example:
 *   use DPay\Laravel\Facades\DPay;
 *   use DPay\Laravel\PaymentFieldRules;
 *
 *   $provider = DPay::provider('edfali');
 *   $request->validate(
 *       PaymentFieldRules::for($provider, prefix: 'fields'),
 *       attributes: PaymentFieldRules::attributesFor($provider, locale: 'ar'),
 *   );
 */
class PaymentFieldRules
{
    /**
     * @return array<string, list<string>>
     */
    public static function for(PaymentProviderInterface $provider, string $prefix = 'fields'): array
    {
        $rules = [];

        foreach ($provider->requiredFields() as $field) {
            $key = "{$prefix}.{$field->key}";
            $rules[$key] = self::rulesForField($field);
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public static function attributesFor(
        PaymentProviderInterface $provider,
        string $locale = 'en',
        string $prefix = 'fields',
    ): array {
        $attrs = [];

        foreach ($provider->requiredFields() as $field) {
            $attrs["{$prefix}.{$field->key}"] = $field->label($locale);
        }

        return $attrs;
    }

    /**
     * @return list<string>
     */
    private static function rulesForField(PaymentField $field): array
    {
        $rules = [$field->required ? 'required' : 'nullable'];
        $rules[] = self::typeRule($field->type);

        if ($field->digits !== null) {
            $rules[] = "digits:{$field->digits}";
        }

        if ($field->digitsOneOf !== null && $field->digitsOneOf !== []) {
            $alternatives = implode(
                '|',
                array_map(static fn (int $d): string => '\d{'.$d.'}', $field->digitsOneOf),
            );

            $rules[] = 'regex:/^('.$alternatives.')$/';
        }

        if ($field->regex !== null) {
            $rules[] = 'regex:'.$field->regex;
        }

        return $rules;
    }

    private static function typeRule(string $type): string
    {
        return match ($type) {
            'integer' => 'integer',
            'boolean' => 'boolean',
            'numeric' => 'numeric',
            'date' => 'date',
            default => 'string',
        };
    }
}
