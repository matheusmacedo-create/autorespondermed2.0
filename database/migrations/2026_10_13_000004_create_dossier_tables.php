<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Dossiê automático: o que o MED puxa sozinho (Woovi, unico-api, KYC) antes de o
     * seller responder, uma linha por fonte. O payload traz dados do pagador e fica
     * criptografado (cast no model) - não espalhar PII em texto claro além do que
     * `disputes.raw` já guarda.
     */
    public function up(): void
    {
        Schema::create('dispute_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dispute_id')->constrained()->cascadeOnDelete();
            // App\Enums\DossierSource
            $table->string('source', 40);
            // App\Enums\SourceStatus
            $table->string('status', 20);
            $table->longText('payload')->nullable();
            $table->text('error')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('fetched_at')->nullable();
            $table->timestamps();

            $table->unique(['dispute_id', 'source']);
        });

        Schema::table('disputes', function (Blueprint $table) {
            // null | ready | partial | failed
            $table->string('dossier_status', 20)->nullable()->after('closed_at');
            $table->timestamp('dossier_built_at')->nullable()->after('dossier_status');
            // Resultado da conferência (sem PII): checks, pronto, bloqueios.
            $table->json('dossier_match')->nullable()->after('dossier_built_at');
            // O que o seller informou além dos arquivos: nº do pedido, rastreio.
            $table->json('seller_details')->nullable()->after('seller_submission_metadata');
        });

        Schema::table('dispute_evidences', function (Blueprint $table) {
            // sha256 do conteúdo: arquivo idêntico ao que o caso já tem não entra de novo.
            $table->char('content_hash', 64)->nullable()->after('size');
            // Documento gerado pelo sistema: chave de upsert (sale_record, woovi_receipt,
            // defense_letter). Null para arquivo do seller ou do analista.
            $table->string('source', 40)->nullable()->after('uploaded_by');

            $table->index(['dispute_id', 'content_hash']);
            $table->index(['dispute_id', 'source']);
        });

        // Cartas já geradas passam a ser achadas pela chave de upsert.
        DB::table('dispute_evidences')->where('kind', 'defense_letter')->update(['source' => 'defense_letter']);

        Schema::table('woovi_accounts', function (Blueprint $table) {
            // Opt-in por conta do envio automático depois da defesa do seller.
            $table->boolean('auto_send')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('woovi_accounts', fn (Blueprint $table) => $table->dropColumn('auto_send'));
        Schema::table('dispute_evidences', function (Blueprint $table) {
            $table->dropIndex(['dispute_id', 'content_hash']);
            $table->dropIndex(['dispute_id', 'source']);
            $table->dropColumn(['content_hash', 'source']);
        });
        Schema::table('disputes', fn (Blueprint $table) => $table->dropColumn(['dossier_status', 'dossier_built_at', 'dossier_match', 'seller_details']));
        Schema::dropIfExists('dispute_sources');
    }
};
