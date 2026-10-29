<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * O caso é a disputa da Woovi, não o Pix: o mesmo endToEndId pode ter um MED e um
     * chargeback, ou um MED cancelado e outro aberto depois. A unicidade passa para o id
     * interno da Woovi; o endToEndId fica só indexado.
     */
    public function up(): void
    {
        Schema::table('disputes', function (Blueprint $table) {
            // Antes de soltar o índice único: no MySQL a chave estrangeira da conta precisa
            // de um índice que comece por woovi_account_id.
            $table->index(['woovi_account_id', 'end_to_end_id']);
            $table->unique(['woovi_account_id', 'woovi_dispute_id']);
        });

        Schema::table('disputes', function (Blueprint $table) {
            $table->dropUnique(['woovi_account_id', 'end_to_end_id']);
        });
    }

    public function down(): void
    {
        Schema::table('disputes', function (Blueprint $table) {
            $table->unique(['woovi_account_id', 'end_to_end_id']);
        });

        Schema::table('disputes', function (Blueprint $table) {
            $table->dropUnique(['woovi_account_id', 'woovi_dispute_id']);
            $table->dropIndex(['woovi_account_id', 'end_to_end_id']);
        });
    }
};
