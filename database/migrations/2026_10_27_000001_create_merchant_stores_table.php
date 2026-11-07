<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Loja de cada lojista (pela conta da plataforma): o site e as páginas de política
     * (entrega, termos, trocas) capturadas em PDF. Captura uma vez e reaproveita nos
     * próximos MEDs da mesma loja; recaptura quando fica velha.
     */
    public function up(): void
    {
        Schema::create('merchant_stores', function (Blueprint $table) {
            $table->id();
            $table->string('merchant_account_id', 100)->unique();
            $table->string('name')->nullable();
            $table->string('website', 500)->nullable();
            // unicopag_register | kyc | sale | manual
            $table->string('website_source', 30)->nullable();
            $table->string('shipping_url', 500)->nullable();
            $table->string('terms_url', 500)->nullable();
            $table->string('exchange_url', 500)->nullable();
            // [{kind, url, title, disk, path, size, sha256, text}]
            $table->json('captures')->nullable();
            // ok | partial | failed | no_website
            $table->string('capture_status', 20)->nullable();
            $table->text('capture_error')->nullable();
            $table->timestamp('captured_at')->nullable();
            $table->timestamp('capture_attempted_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_stores');
    }
};
