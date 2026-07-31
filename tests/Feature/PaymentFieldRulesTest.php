<?php

declare(strict_types=1);

namespace DPay\Tests\Feature;

use DPay\Client\DPayClient;
use DPay\Config\DPayConfig;
use DPay\Dto\PaymentField;
use DPay\Http\Transport;
use DPay\Laravel\PaymentFieldRules;
use DPay\Providers\EdfaliProvider;
use DPay\Providers\MobiCashProvider;
use DPay\Providers\MoamalatProvider;
use DPay\Tests\Unit\Support\FakeHttpClient;
use Illuminate\Validation\Factory;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;

final class PaymentFieldRulesTest extends TestCase
{
    private function client(): DPayClient
    {
        $f = new Psr17Factory();
        $config = new DPayConfig();

        return new DPayClient(
            config: $config,
            transport: new Transport($config, new FakeHttpClient(), $f, $f),
        );
    }

    private function validator(): Factory
    {
        return new Factory(new Translator(new ArrayLoader(), 'en'));
    }

    public function test_for_produces_expected_rules_per_provider(): void
    {
        $edfali = new EdfaliProvider($this->client(), 'edfali');

        self::assertSame(
            ['fields.phone_number' => ['required', 'string', 'regex:/^09\d{8}$/']],
            PaymentFieldRules::for($edfali, 'fields'),
        );

        $mobicash = new MobiCashProvider($this->client(), 'mobicash');

        self::assertSame(
            ['fields.card_number' => ['required', 'string', 'digits:7']],
            PaymentFieldRules::for($mobicash, 'fields'),
        );

        $moamalat = new MoamalatProvider($this->client(), 'moamalat');
        self::assertSame([], PaymentFieldRules::for($moamalat));
    }

    public function test_attributes_for_translates_label_to_requested_locale(): void
    {
        $edfali = new EdfaliProvider($this->client(), 'edfali');

        self::assertSame(
            ['fields.phone_number' => 'Phone Number'],
            PaymentFieldRules::attributesFor($edfali, 'en'),
        );
        self::assertSame(
            ['fields.phone_number' => 'رقم الهاتف'],
            PaymentFieldRules::attributesFor($edfali, 'ar'),
        );
    }

    public function test_rules_are_valid_against_a_real_validator(): void
    {
        $validator = $this->validator();
        $rules = PaymentFieldRules::for(new EdfaliProvider($this->client(), 'edfali'));

        // Good input
        $v = $validator->make(['fields' => ['phone_number' => '0911234567']], $rules);
        self::assertFalse($v->fails(), 'valid Libyan mobile should pass');

        // Bad input — wrong prefix
        $v = $validator->make(['fields' => ['phone_number' => '0811234567']], $rules);
        self::assertTrue($v->fails(), 'phone not starting with 09 must fail');

        // Bad input — missing
        $v = $validator->make(['fields' => []], $rules);
        self::assertTrue($v->fails(), 'missing required field must fail');
    }

    public function test_rules_with_nullable_when_field_optional(): void
    {
        $provider = new EdfaliProvider(
            $this->client(),
            'edfali',
            requiredFields: [new PaymentField(key: 'phone_number', required: false)],
        );

        self::assertSame(
            ['fields.phone_number' => ['nullable', 'string']],
            PaymentFieldRules::for($provider),
        );
    }

    public function test_digits_one_of_becomes_an_alternation_regex(): void
    {
        $provider = new \DPay\Providers\SaharaPayProvider(
            $this->createMock(\DPay\Client\DPayClientInterface::class),
            'saharapay',
            true,
            [\DPay\Dto\PaymentField::bankCardNumber()],
        );

        $rules = \DPay\Laravel\PaymentFieldRules::for($provider);

        self::assertContains('regex:/^(\d{7}|\d{9})$/', $rules['fields.card_number']);
    }

    public function test_nine_digit_cross_bank_card_passes_validation(): void
    {
        $provider = new \DPay\Providers\SaharaPayProvider(
            $this->createMock(\DPay\Client\DPayClientInterface::class),
            'saharapay',
            true,
            [\DPay\Dto\PaymentField::bankCardNumber()],
        );

        $validator = $this->validator()->make(
            ['fields' => ['card_number' => '661234567']],
            \DPay\Laravel\PaymentFieldRules::for($provider),
        );

        self::assertTrue($validator->passes(), 'A 9-digit OnePay card must be accepted.');
    }

    public function test_eight_digit_card_is_rejected(): void
    {
        $provider = new \DPay\Providers\SaharaPayProvider(
            $this->createMock(\DPay\Client\DPayClientInterface::class),
            'saharapay',
            true,
            [\DPay\Dto\PaymentField::bankCardNumber()],
        );

        $validator = $this->validator()->make(
            ['fields' => ['card_number' => '12345678']],
            \DPay\Laravel\PaymentFieldRules::for($provider),
        );

        self::assertFalse($validator->passes(), '8 digits is neither same-bank nor cross-bank.');
    }

    public function test_an_empty_digits_one_of_emits_no_regex_rule(): void
    {
        $provider = new \DPay\Providers\SaharaPayProvider(
            $this->createMock(\DPay\Client\DPayClientInterface::class),
            'saharapay',
            true,
            [new PaymentField(key: 'card_number', digitsOneOf: [])],
        );

        foreach (\DPay\Laravel\PaymentFieldRules::for($provider)['fields.card_number'] as $rule) {
            self::assertStringNotContainsString('regex:', $rule);
        }
    }
}
