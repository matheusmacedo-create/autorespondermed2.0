<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Conta Woovi de um seller (subconta criada pelo unico-api via
     * /account-register). O MED cai na conta do seller, e só o AppID daquela
     * conta lê a disputa e envia evidência - por isso uma linha por conta.
     */
    public function up(): void
    {
        Schema::create('woovi_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('tax_id', 20)->nullable()->index();
            // accounts.id no unico-api: é por ele que o MED volta para a plataforma.
            $table->string('unicopag_account_id')->nullable()->unique();
            $table->string('woovi_company_id')->nullable()->index();
            // AppID da Woovi (header Authorization). Criptografado pelo cast do model.
            $table->text('app_id');
            // manual | unico_api | woovi_app
            $table->string('source', 20)->default('manual');
            // Segmento secreto da URL do webhook desta conta: o payload de disputa
            // da Woovi não diz de qual conta veio, então a URL é que diz.
            $table->string('webhook_key', 64)->unique();
            $table->string('contact_name')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('contact_phone', 30)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('webhooks_registered_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('woovi_accounts');
    }
};
