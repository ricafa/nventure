<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('posicao_futuro', function (Blueprint $table) {
            $table->unsignedInteger('posicao_id')->primary();
            $table->decimal('preco_entrada', 18, 6);
            $table->string('codigo_contrato', 20);

            $table->foreign('posicao_id')->references('id')->on('posicao')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('posicao_futuro');
    }
};
