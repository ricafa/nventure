<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('posicao_otc', function (Blueprint $table) {
            $table->unsignedInteger('posicao_id')->primary();
            $table->decimal('preco_entrada', 18, 6);
            $table->string('indexador', 30);
            // premio_otc aceita negativo (§3.2.7) — sem CHECK de sinal.
            $table->decimal('premio_otc', 18, 6)->default(0);

            $table->foreign('posicao_id')->references('id')->on('posicao')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('posicao_otc');
    }
};
