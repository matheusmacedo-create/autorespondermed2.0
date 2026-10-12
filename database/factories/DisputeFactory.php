<?php

namespace Database\Factories;

use App\Enums\DisputeStatus;
use App\Models\Dispute;
use App\Models\WooviAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Dispute>
 */
class DisputeFactory extends Factory
{
    public function definition(): array
    {
        $openedAt = now()->subHours(2);

        return [
            'woovi_account_id' => WooviAccount::factory(),
            'end_to_end_id' => 'E'.fake()->unique()->regexify('[A-Z0-9]{31}'),
            'type' => 'MED',
            'woovi_status' => 'OPENED',
            'status' => DisputeStatus::PENDING_SELLER,
            'value' => 15000,
            'dispute_reason' => 'Golpe',
            'opened_at' => $openedAt,
            'respond_by' => $openedAt->copy()->addHours(72),
            'token_expires_at' => $openedAt->copy()->addHours(96),
        ];
    }

    public function submitted(): static
    {
        return $this->state(fn () => [
            'status' => DisputeStatus::SELLER_SUBMITTED,
            'seller_statement' => 'Vendi um celular ao comprador e entreguei em mãos, com recibo assinado.',
            'seller_contact_name' => 'Maria Seller',
            'seller_submitted_at' => now(),
        ]);
    }
}
