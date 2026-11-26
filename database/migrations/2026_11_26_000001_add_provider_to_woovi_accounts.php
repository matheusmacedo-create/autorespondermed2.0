<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A conta passa a dizer de qual instituição é (Woovi ou Transfeera). A tabela e
     * as colunas `woovi_*` ficam com o nome antigo: renomear tudo mexeria em cada
     * tela, comando e integração de uma vez, sem ganho para a operação.
     *
     * Na Transfeera: `app_id` guarda "client_id:client_secret", `woovi_company_id` o
     * id da subconta (escopo do token, opcional) e `webhook_secret` o segredo que
     * assina os webhooks (devolvido no cadastro do webhook).
     */
    public function up(): void
    {
        Schema::table('woovi_accounts', function (Blueprint $table) {
            $table->string('provider', 20)->default('woovi')->index();
            // Criptografado pelo cast do model.
            $table->text('webhook_secret')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('woovi_accounts', function (Blueprint $table) {
            $table->dropIndex(['provider']);
            $table->dropColumn(['provider', 'webhook_secret']);
        });
    }
};
