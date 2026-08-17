<?php

declare(strict_types=1);

namespace DPay\Tests\Unit\Dto;

use DPay\Dto\PayMethod;
use PHPUnit\Framework\TestCase;

final class PayMethodTest extends TestCase
{
    /**
     * Verbatim from the official Postman collection's "List Payment Methods"
     * success example.
     *
     * @return array<string, mixed>
     */
    private function goldenBody(): array
    {
        return [
            'name' => 'EDFali',
            'slug' => 'edfali',
            'icon' => 'edfali.png',
            'logo_url' => 'https://dpay.ly/assets/img/logos/edfali.svg',
            'active' => true,
            'fee' => 2.5,
            'min_deposit' => 1,
            'max_deposit' => 5000,
        ];
    }

    public function test_it_maps_every_documented_field(): void
    {
        $m = PayMethod::fromArray($this->goldenBody());

        self::assertSame('EDFali', $m->name);
        self::assertSame('edfali', $m->slug);
        self::assertTrue($m->active);
        self::assertSame(2.5, $m->fee);
        self::assertSame(1.0, $m->minDeposit);
        self::assertSame(5000.0, $m->maxDeposit);
        self::assertSame('https://dpay.ly/assets/img/logos/edfali.svg', $m->logoUrl);
        self::assertSame('edfali.png', $m->icon);
    }

    public function test_deposit_limits_are_floats_even_when_json_gives_integers(): void
    {
        // DPay sends min_deposit/max_deposit as JSON integers. Amounts in this
        // SDK are floats end-to-end, so comparing a float amount against an int
        // limit must not depend on PHP's juggling.
        $m = PayMethod::fromArray($this->goldenBody());

        self::assertIsFloat($m->minDeposit);
        self::assertIsFloat($m->maxDeposit);
    }

    public function test_the_raw_body_is_preserved_for_unmapped_fields(): void
    {
        $body = $this->goldenBody() + ['some_future_field' => 'kept'];

        self::assertSame('kept', PayMethod::fromArray($body)->raw['some_future_field']);
    }

    public function test_missing_optional_fields_degrade_rather_than_crash(): void
    {
        // A gateway list entry with only the essentials must not fatal — the
        // SDK's rule everywhere else is that unmapped/absent fields degrade.
        $m = PayMethod::fromArray(['slug' => 'mobicash']);

        self::assertSame('mobicash', $m->slug);
        self::assertSame('', $m->name);
        self::assertFalse($m->active);
        self::assertNull($m->logoUrl);
        self::assertSame(0.0, $m->minDeposit);
        self::assertSame(0.0, $m->maxDeposit);
    }

    public function test_non_scalar_values_do_not_trigger_a_conversion_warning(): void
    {
        // json_decode can hand back anything; the suite runs failOnWarning.
        $m = PayMethod::fromArray([
            'slug' => 'edfali',
            'name' => ['unexpected' => 'array'],
            'logo_url' => ['also' => 'array'],
        ]);

        self::assertSame('', $m->name);
        self::assertNull($m->logoUrl);
    }

    public function test_to_array_round_trips_the_documented_shape(): void
    {
        $body = $this->goldenBody();

        self::assertSame($body, PayMethod::fromArray($body)->toArray());
    }
}
