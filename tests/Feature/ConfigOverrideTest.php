<?php

declare(strict_types=1);

namespace DPay\Tests\Feature;

use DPay\Dto\PaymentField;
use DPay\GatewayManager;
use DPay\Laravel\DPayServiceProvider;
use Orchestra\Testbench\TestCase;

final class ConfigOverrideTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [DPayServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('dpay.mock', true);
        $app['config']->set('dpay.api_key', 'k');
        $app['config']->set('dpay.gateways.edfali.enabled', true);

        // Override Edfali's default phone regex with a tighter one.
        $app['config']->set('dpay.gateways.edfali.required_fields', [
            [
                'key' => 'phone_number',
                'type' => 'string',
                'required' => true,
                'regex' => '/^09[1-6]\d{7}$/',
                'labels' => ['en' => 'Mobile', 'ar' => 'الجوال'],
                'placeholders' => ['en' => '091...', 'ar' => '091...'],
                'input_type' => 'tel',
            ],
        ]);

        // Give Moamalat a card field via config (without changing code).
        $app['config']->set('dpay.gateways.moamalat.enabled', true);
        $app['config']->set('dpay.gateways.moamalat.required_fields', [
            [
                'key' => 'card_number',
                'type' => 'string',
                'required' => true,
                'digits' => 16,
                'labels' => ['en' => 'Card', 'ar' => 'البطاقة'],
                'input_type' => 'number',
            ],
        ]);
    }

    public function test_config_override_replaces_edfali_default_regex(): void
    {
        $manager = $this->app->make(GatewayManager::class);
        $fields = $manager->provider('edfali')->requiredFields();

        self::assertCount(1, $fields);
        self::assertSame('/^09[1-6]\d{7}$/', $fields[0]->regex);
        self::assertSame('Mobile', $fields[0]->label('en'));
        self::assertSame('الجوال', $fields[0]->label('ar'));
    }

    public function test_config_override_can_add_a_field_to_moamalat(): void
    {
        $manager = $this->app->make(GatewayManager::class);
        $fields = $manager->provider('moamalat')->requiredFields();

        self::assertCount(1, $fields);
        self::assertSame('card_number', $fields[0]->key);
        self::assertSame(16, $fields[0]->digits);
    }

    public function test_describe_returns_overridden_fields_in_json_shape(): void
    {
        $manager = $this->app->make(GatewayManager::class);
        $rows = $manager->describe();

        $edfali = array_values(array_filter($rows, fn ($r) => $r['code'] === 'edfali'))[0];
        self::assertSame('/^09[1-6]\d{7}$/', $edfali['required_fields'][0]['regex']);
    }
}
