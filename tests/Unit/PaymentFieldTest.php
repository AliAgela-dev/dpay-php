<?php

declare(strict_types=1);

namespace DPay\Tests\Unit;

use DPay\Dto\PaymentField;
use PHPUnit\Framework\TestCase;

final class PaymentFieldTest extends TestCase
{
    public function test_phone_number_default_matches_health_portal_seeder(): void
    {
        $f = PaymentField::phoneNumber();

        self::assertSame('phone_number', $f->key);
        self::assertSame('string', $f->type);
        self::assertTrue($f->required);
        self::assertSame('/^09\d{8}$/', $f->regex);
        self::assertNull($f->digits);
        self::assertSame('tel', $f->inputType);
        self::assertSame('Phone Number', $f->label('en'));
        self::assertSame('رقم الهاتف', $f->label('ar'));
        self::assertSame('09xxxxxxxx', $f->placeholder('en'));
    }

    public function test_card_number_7_digits_default(): void
    {
        $f = PaymentField::cardNumber(digits: 7);

        self::assertSame('card_number', $f->key);
        self::assertSame(7, $f->digits);
        self::assertNull($f->regex);
        self::assertSame('number', $f->inputType);
        self::assertSame('Card Number', $f->label('en'));
        self::assertSame('رقم البطاقة', $f->label('ar'));
        self::assertSame('#######', $f->placeholder('en'));
    }

    public function test_card_number_16_digits_uses_grouped_placeholder(): void
    {
        $f = PaymentField::cardNumber(digits: 16);

        self::assertSame(16, $f->digits);
        self::assertSame('#### #### #### ####', $f->placeholder('en'));
    }

    public function test_label_falls_back_to_english_then_key(): void
    {
        $f = new PaymentField(key: 'foo', labels: ['en' => 'Foo']);

        self::assertSame('Foo', $f->label('ar'));    // ar missing -> en
        self::assertSame('Foo', $f->label('en'));

        $bare = new PaymentField(key: 'bar');
        self::assertSame('bar', $bare->label('en'));  // no labels -> key
    }

    public function test_to_array_and_from_array_roundtrip(): void
    {
        $original = PaymentField::phoneNumber();
        $rebuilt = PaymentField::fromArray($original->toArray());

        self::assertSame($original->key, $rebuilt->key);
        self::assertSame($original->regex, $rebuilt->regex);
        self::assertSame($original->labels, $rebuilt->labels);
        self::assertSame($original->placeholders, $rebuilt->placeholders);
        self::assertSame($original->inputType, $rebuilt->inputType);
    }

    public function test_to_array_includes_all_keys_even_when_null(): void
    {
        $arr = PaymentField::phoneNumber()->toArray();

        // regex set, digits null — but both keys must be present for JSON consumers.
        self::assertArrayHasKey('regex', $arr);
        self::assertArrayHasKey('digits', $arr);
        self::assertNull($arr['digits']);
    }

    public function test_wire_name_defaults_to_the_key(): void
    {
        self::assertSame('phone_number', (new PaymentField(key: 'phone_number'))->wireName());
    }

    public function test_send_as_overrides_the_wire_name(): void
    {
        $field = new PaymentField(key: 'phone_number', sendAs: 'customer_mobile');

        self::assertSame('customer_mobile', $field->wireName());
    }

    public function test_bank_card_number_accepts_seven_or_nine_digits(): void
    {
        $field = PaymentField::bankCardNumber();

        self::assertSame([7, 9], $field->digitsOneOf);
        self::assertNull($field->digits);
        self::assertSame('card_number', $field->wireName());
    }

    public function test_birth_year_is_a_four_digit_field_sent_as_birth_year(): void
    {
        $field = PaymentField::birthYear();

        self::assertSame(4, $field->digits);
        self::assertSame('birth_year', $field->wireName());
    }

    public function test_sadad_category_is_optional_and_integer(): void
    {
        $field = PaymentField::sadadCategory();

        self::assertFalse($field->required);
        self::assertSame('integer', $field->type);
        self::assertSame('category', $field->wireName());
    }

    public function test_to_array_exposes_send_as_and_digits_one_of(): void
    {
        $array = PaymentField::bankCardNumber()->toArray();

        self::assertSame([7, 9], $array['digits_one_of']);
        self::assertSame('card_number', $array['send_as']);
    }

    public function test_from_array_round_trips_the_new_keys(): void
    {
        $field = PaymentField::fromArray(PaymentField::bankCardNumber()->toArray());

        self::assertSame([7, 9], $field->digitsOneOf);
        self::assertSame('card_number', $field->wireName());
    }

    public function test_setting_both_digits_and_digits_one_of_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new PaymentField(key: 'card_number', digits: 7, digitsOneOf: [9]);
    }

    public function test_from_array_casts_string_digit_values(): void
    {
        // Config and JSON deliver strings, not ints.
        $field = PaymentField::fromArray(['key' => 'card_number', 'digits_one_of' => ['7', '9']]);

        self::assertSame([7, 9], $field->digitsOneOf);
    }

    public function test_an_explicit_send_as_survives_a_round_trip(): void
    {
        // The existing round-trip test uses a field whose sendAs is null, so it
        // cannot distinguish "override preserved" from "collapsed and redefaulted".
        $original = new PaymentField(key: 'phone_number', sendAs: 'customer_mobile');

        self::assertSame('customer_mobile', PaymentField::fromArray($original->toArray())->wireName());
    }
}
