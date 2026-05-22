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
}
