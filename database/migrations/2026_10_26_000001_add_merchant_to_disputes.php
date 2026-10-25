<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lojista do caso, vindo da venda no unico-api. Na conta mestre todos os MEDs caem
     * na mesma conta Woovi, então quem recebeu o Pix (e quem é avisado) é do caso, não
     * da conta.
     */
    public function up(): void
    {
        Schema::table('disputes', function (Blueprint $table) {
            $table->string('merchant_account_id', 100)->nullable()->index();
            $table->json('merchant')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('disputes', function (Blueprint $table) {
            $table->dropIndex(['merchant_account_id']);
            $table->dropColumn(['merchant_account_id', 'merchant']);
        });
    }
};
