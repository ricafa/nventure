<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('posicao_opcao', function (Blueprint $table) {
            $table->unsignedInteger('posicao_id')->primary();
            $table->string('nome_estrutura', 60)->nullable();

            $table->foreign('posicao_id')->references('id')->on('posicao')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('posicao_opcao');
    }
};
