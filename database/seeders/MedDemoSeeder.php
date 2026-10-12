<?php

namespace Database\Seeders;

use App\Enums\DisputeStatus;
use App\Enums\EvidenceKind;
use App\Models\Dispute;
use App\Models\DisputeEvidence;
use App\Models\User;
use App\Models\WooviAccount;
use Illuminate\Database\Seeder;

/**
 * Dados de demonstração para ambiente local (`php artisan db:seed --class=MedDemoSeeder`).
 * Login: admin@unicopag.test / password. Não rodar em produção.
 */
class MedDemoSeeder extends Seeder
{
    public function run(): void
    {
        User::query()->firstOrCreate(
            ['email' => 'admin@unicopag.test'],
            ['name' => 'Analista MED', 'password' => 'password'],
        );

        $loja = WooviAccount::factory()->create([
            'name' => 'Loja do José Eletrônicos LTDA',
            'tax_id' => '12345678000190',
            'contact_name' => 'José',
            'contact_email' => 'jose@lojadojose.com.br',
        ]);

        $academia = WooviAccount::factory()->create([
            'name' => 'Academia Corpo em Forma',
            'tax_id' => '98765432000110',
            'contact_name' => 'Ana',
            'contact_email' => 'ana@corpoemforma.com.br',
        ]);

        $submitted = Dispute::factory()->for($loja)->submitted()->create([
            'value' => 189900,
            'dispute_reason' => 'Golpe',
            'opened_at' => now()->subHours(60),
            'respond_by' => now()->addHours(6),
        ]);

        DisputeEvidence::factory()->for($submitted)->create([
            'kind' => EvidenceKind::INVOICE,
            'description' => 'Nota fiscal 4521 da venda do celular em nome do comprador',
            'original_name' => 'nf-4521.pdf',
        ]);
        DisputeEvidence::factory()->for($submitted)->create([
            'kind' => EvidenceKind::CONVERSATION,
            'description' => 'Conversa no WhatsApp combinando a retirada na loja',
            'original_name' => 'whatsapp.pdf',
        ]);

        Dispute::factory()->for($academia)->create([
            'value' => 29900,
            'dispute_reason' => 'Serviço não prestado',
            'opened_at' => now()->subHours(10),
            'respond_by' => now()->addHours(62),
        ]);

        Dispute::factory()->for($loja)->create([
            'value' => 54000,
            'opened_at' => now()->subHours(66),
            'respond_by' => now()->addHours(6),
        ]);

        Dispute::factory()->for($academia)->create([
            'status' => DisputeStatus::REJECTED,
            'woovi_status' => 'REJECTED',
            'value' => 12000,
            'closed_at' => now()->subDays(3),
        ]);
    }
}
