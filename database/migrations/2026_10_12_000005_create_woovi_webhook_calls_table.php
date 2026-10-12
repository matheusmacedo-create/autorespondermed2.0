<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Todo POST que a Woovi faz no webhook, cru, antes de qualquer processamento.
     * Se o processamento falhar, o corpo continua aqui para reprocessar - nada de
     * "falha sem rastro" (padrão 7 da base de conhecimento do KYC).
     */
    public function up(): void
    {
        Schema::create('woovi_webhook_calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('woovi_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event', 60)->nullable()->index();
            $table->boolean('signature_valid')->default(false);
            $table->longText('payload');
            $table->json('headers')->nullable();
            $table->foreignId('dispute_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('processed_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('woovi_webhook_calls');
    }
};
