<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Defesa com IA: o último rascunho do modelo (narrativa, argumentos, pontos
     * fracos, recomendação) e o texto da defesa que vai na carta - que parte do
     * rascunho, mas é do analista.
     */
    public function up(): void
    {
        Schema::table('disputes', function (Blueprint $table) {
            $table->json('ai_defense')->nullable();
            $table->timestamp('ai_defense_generated_at')->nullable();
            $table->text('defense_text')->nullable();
        });

        // O painel avisa no sininho quando o rascunho fica pronto.
        if (! Schema::hasTable('notifications')) {
            Schema::create('notifications', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('type');
                $table->morphs('notifiable');
                $table->text('data');
                $table->timestamp('read_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::table('disputes', function (Blueprint $table) {
            $table->dropColumn(['ai_defense', 'ai_defense_generated_at', 'defense_text']);
        });
    }
};
