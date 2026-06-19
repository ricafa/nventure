<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('posicao_ndf', function (Blueprint $table) {
            $table->unsignedInteger('posicao_id')->primary();
            $table->decimal('taxa_contratada', 18, 6);
            $table->decimal('valor_nocional', 18, 2);
            $table->string('moeda_nocional', 3);

            $table->foreign('posicao_id')->references('id')->on('posicao')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('posicao_ndf');
    }
};
