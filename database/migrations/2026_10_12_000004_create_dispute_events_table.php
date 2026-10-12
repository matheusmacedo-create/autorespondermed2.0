<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Linha do tempo do caso (append-only), no molde do onboarding_events do KYC:
     * tudo que aconteceu com o MED - webhook, aviso ao seller, defesa, envio à
     * Woovi, falha - fica visível na ficha, não só no log de arquivo.
     */
    public function up(): void
    {
        Schema::create('dispute_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dispute_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('woovi_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event', 60)->index();
            $table->string('level', 10)->default('info');
            $table->string('source', 20)->default('system');
            $table->text('message');
            $table->json('context')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('occurred_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dispute_events');
    }
};
