<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cada mensagem de WhatsApp que o MED mandou ao seller pela Kapso: o template que
     * abre a conversa e o texto com o link que vai quando o seller responde. É por
     * esta tabela que o webhook da Kapso sabe se uma resposta é para o MED.
     */
    public function up(): void
    {
        Schema::create('whatsapp_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dispute_id')->constrained()->cascadeOnDelete();
            // template | link
            $table->string('type', 20);
            // Só dígitos, com 55.
            $table->string('recipient', 20)->index();
            $table->string('wamid')->nullable()->unique();
            // sent | failed
            $table->string('status', 20);
            // sent | delivered | read | failed (o que a Meta avisa depois)
            $table->string('delivery_status', 20)->nullable();
            $table->text('error')->nullable();
            // Template: quando o seller respondeu e quando o link foi em resposta.
            $table->timestamp('replied_at')->nullable();
            $table->timestamp('link_sent_at')->nullable();
            $table->timestamps();
        });

        Schema::create('whatsapp_opt_outs', function (Blueprint $table) {
            $table->id();
            $table->string('recipient', 20)->unique();
            $table->text('message')->nullable();
            $table->timestamp('requested_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_opt_outs');
        Schema::dropIfExists('whatsapp_messages');
    }
};
