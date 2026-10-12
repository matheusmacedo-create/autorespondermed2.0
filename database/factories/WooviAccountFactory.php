<?php

namespace Database\Factories;

use App\Enums\WooviAccountSource;
use App\Models\WooviAccount;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<WooviAccount>
 */
class WooviAccountFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'tax_id' => fake()->numerify('##############'),
            'unicopag_account_id' => (string) fake()->unique()->numberBetween(1000, 999999),
            'app_id' => 'Q2xpZW50X0lkXz'.Str::random(32),
            'source' => WooviAccountSource::MANUAL,
            'contact_name' => fake()->name(),
            'contact_email' => fake()->unique()->safeEmail(),
            'contact_phone' => '+5521999990000',
            'is_active' => true,
        ];
    }
}
