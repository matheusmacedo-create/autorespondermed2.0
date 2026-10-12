<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dispute_evidences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dispute_id')->constrained()->cascadeOnDelete();
            // App\Enums\EvidenceKind
            $table->string('kind', 40);
            $table->string('description', 500);
            $table->string('disk', 30);
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size');
            // seller | analyst | system (a carta de defesa gerada)
            $table->string('uploaded_by', 20)->default('seller');
            // correlationID enviado à Woovi: torna o upload e o envio idempotentes.
            $table->uuid('correlation_id')->unique();
            $table->string('woovi_file_id')->nullable();
            $table->text('woovi_url')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dispute_evidences');
    }
};
