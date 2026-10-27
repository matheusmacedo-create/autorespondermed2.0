<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Quando a Woovi recusou evidência por fileId nesta conta (falta a feature
     * DISPUTE_EVIDENCE_FILE_ID): os próximos envios vão direto pelo link do arquivo
     * na Woovi, e de tempos em tempos o fileId é tentado de novo.
     */
    public function up(): void
    {
        Schema::table('woovi_accounts', function (Blueprint $table) {
            $table->timestamp('evidence_file_id_refused_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('woovi_accounts', function (Blueprint $table) {
            $table->dropColumn('evidence_file_id_refused_at');
        });
    }
};
