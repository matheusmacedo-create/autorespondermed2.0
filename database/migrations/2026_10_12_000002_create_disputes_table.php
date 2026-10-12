<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Um caso de MED (ou chargeback) recebido numa conta Woovi. É o equivalente
     * do `onboardings` do KYC: tem link público por token para o seller se
     * defender e passa por uma fila de análise antes de ir para a Woovi.
     */
    public function up(): void
    {
        Schema::create('disputes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('woovi_account_id')->constrained()->cascadeOnDelete();
            // A Woovi identifica a disputa pelo endToEndId do Pix (GET /dispute/{id}
            // aceita o endToEndId). O `id` interno dela nem sempre vem no payload.
            $table->string('end_to_end_id', 64);
            $table->string('woovi_dispute_id')->nullable()->index();
            // MED | CHARGEBACK | DISPUTE
            $table->string('type', 20)->default('MED');
            // Status como a Woovi informa (OPENED, IN_REVIEW, ACCEPTED, ...).
            $table->string('woovi_status', 40)->nullable();
            // Status interno do fluxo de resposta (App\Enums\DisputeStatus).
            $table->string('status', 30)->default('pending_seller')->index();
            $table->unsignedBigInteger('value')->nullable()->comment('centavos');
            $table->string('dispute_reason')->nullable();
            $table->string('situation_type', 40)->nullable();
            $table->string('payer_name')->nullable();
            $table->string('payer_email')->nullable();
            $table->string('payer_phone', 30)->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('respond_by')->nullable()->index();

            // Link público do seller.
            $table->string('token', 64)->unique();
            $table->timestamp('token_expires_at')->nullable();
            $table->timestamp('seller_notified_at')->nullable();
            $table->timestamp('seller_opened_at')->nullable();

            // Defesa do seller.
            $table->text('seller_statement')->nullable();
            $table->string('seller_contact_name')->nullable();
            $table->timestamp('seller_submitted_at')->nullable();
            $table->json('seller_submission_metadata')->nullable();

            // Análise e envio.
            $table->text('analyst_notes')->nullable();
            $table->text('complement_request')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('evidence_sent_at')->nullable();
            $table->timestamp('closed_at')->nullable();

            $table->json('raw')->nullable();
            $table->timestamps();

            $table->unique(['woovi_account_id', 'end_to_end_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('disputes');
    }
};
