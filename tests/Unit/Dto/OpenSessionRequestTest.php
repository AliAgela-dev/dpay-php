<?php

declare(strict_types=1);

namespace DPay\Tests\Unit\Dto;

use DPay\Dto\OpenSessionRequest;
use PHPUnit\Framework\TestCase;

final class OpenSessionRequestTest extends TestCase
{
    public function test_decimal_amount_is_never_truncated(): void
    {
        $body = (new OpenSessionRequest(payMethod: 'edfali', amount: 10.50))->toBody();

        self::assertSame(10.5, $body['amount']);
        self::assertSame('{"pay_method":"edfali","amount":10.5}', json_encode($body));
    }

    public function test_whole_amount_serialises_without_a_decimal_point(): void
    {
        $body = (new OpenSessionRequest(payMethod: 'edfali', amount: 100.0))->toBody();

        self::assertSame('{"pay_method":"edfali","amount":100}', json_encode($body));
    }

    public function test_description_is_top_level_not_nested_under_data(): void
    {
        $body = (new OpenSessionRequest(
            payMethod: 'mobicash',
            amount: 10,
            cardNumber: '7279627',
            description: 'Order #1234',
        ))->toBody();

        self::assertSame('Order #1234', $body['description']);
        self::assertArrayNotHasKey('data', $body);
    }

    public function test_description_and_data_are_independent_top_level_keys(): void
    {
        $body = (new OpenSessionRequest(
            payMethod: 'mobicash',
            amount: 10,
            cardNumber: '7279627',
            description: 'Order #1234',
            data: ['order_id' => 'ORD-001'],
        ))->toBody();

        self::assertSame('Order #1234', $body['description']);
        self::assertSame(['order_id' => 'ORD-001'], $body['data']);
        // The regression guard: description must never be folded back into data.
        self::assertArrayNotHasKey('description', $body['data']);
    }

    public function test_a_decimal_amount_survives_a_full_multi_field_body(): void
    {
        $body = (new OpenSessionRequest(
            payMethod: 'mobicash',
            amount: 10.50,
            cardNumber: '7279627',
            description: 'Order #1234',
            data: ['order_id' => 'ORD-001'],
        ))->toBody();

        self::assertSame(
            '{"pay_method":"mobicash","amount":10.5,"card_number":"7279627",'
            .'"description":"Order #1234","data":{"order_id":"ORD-001"}}',
            json_encode($body),
        );
    }

    public function test_data_carries_free_form_metadata(): void
    {
        $body = (new OpenSessionRequest(
            payMethod: 'edfali',
            amount: 50,
            data: ['order_id' => 'ORD-001'],
        ))->toBody();

        self::assertSame(['order_id' => 'ORD-001'], $body['data']);
    }

    public function test_empty_data_is_omitted(): void
    {
        $body = (new OpenSessionRequest(payMethod: 'edfali', amount: 50, data: []))->toBody();

        self::assertArrayNotHasKey('data', $body);
    }

    public function test_category_zero_survives_the_null_filter(): void
    {
        $body = (new OpenSessionRequest(
            payMethod: 'sadad',
            amount: 100,
            customerMobile: '0912345678',
            birthYear: '1994',
            category: 0,
        ))->toBody();

        self::assertArrayHasKey('category', $body);
        self::assertSame(0, $body['category']);
    }

    /**
     * Golden bodies copied verbatim from the official Postman collection.
     *
     * @return iterable<string, array{OpenSessionRequest, string}>
     */
    public static function goldenBodies(): iterable
    {
        yield 'edfali' => [
            new OpenSessionRequest(payMethod: 'edfali', amount: 100, customerMobile: '0912345678'),
            '{"pay_method":"edfali","amount":100,"customer_mobile":"0912345678"}',
        ];

        yield 'mobicash' => [
            new OpenSessionRequest(payMethod: 'mobicash', amount: 10, cardNumber: '7279627', description: 'Order #1234'),
            '{"pay_method":"mobicash","amount":10,"card_number":"7279627","description":"Order #1234"}',
        ];

        yield 'masrefypay same bank' => [
            new OpenSessionRequest(payMethod: 'masrefypay', amount: 50, cardNumber: '1234567'),
            '{"pay_method":"masrefypay","amount":50,"card_number":"1234567"}',
        ];

        yield 'masrefypay cross bank' => [
            new OpenSessionRequest(payMethod: 'masrefypay', amount: 50, cardNumber: '331234567'),
            '{"pay_method":"masrefypay","amount":50,"card_number":"331234567"}',
        ];

        yield 'yousrpay' => [
            new OpenSessionRequest(payMethod: 'yousrpay', amount: 50, cardNumber: '1234567'),
            '{"pay_method":"yousrpay","amount":50,"card_number":"1234567"}',
        ];

        yield 'saharapay' => [
            new OpenSessionRequest(payMethod: 'saharapay', amount: 50, cardNumber: '1234567'),
            '{"pay_method":"saharapay","amount":50,"card_number":"1234567"}',
        ];

        yield 'sadad' => [
            new OpenSessionRequest(payMethod: 'sadad', amount: 100, customerMobile: '0912345678', birthYear: '1994', category: 20),
            '{"pay_method":"sadad","amount":100,"customer_mobile":"0912345678","birth_year":"1994","category":20}',
        ];

        yield 'moamalat' => [
            new OpenSessionRequest(payMethod: 'moamalat', amount: 200),
            '{"pay_method":"moamalat","amount":200}',
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('goldenBodies')]
    public function test_body_matches_the_official_postman_example(OpenSessionRequest $request, string $expected): void
    {
        self::assertSame($expected, json_encode($request->toBody()));
    }
}
