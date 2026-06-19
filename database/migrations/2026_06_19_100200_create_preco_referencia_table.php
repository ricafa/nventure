<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('preco_referencia', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('produto_id');
            $table->date('data_preco');
            $table->decimal('preco_fechamento', 18, 6);
            $table->decimal('cambio_brl', 18, 6);
            // Reservadas (não usadas no MVP — §3.0).
            $table->decimal('vol_implicita', 8, 4)->nullable();
            $table->decimal('taxa_juros', 8, 4)->nullable();
            $table->timestamp('criado_em')->useCurrent();

            $table->foreign('produto_id')->references('id')->on('produto');
            $table->unique(['produto_id', 'data_preco']);
        });

        // Índice de performance §3.3 (ordenação DESC — fora do Schema builder).
        DB::statement('CREATE INDEX idx_preco_produto_data ON preco_referencia(produto_id, data_preco DESC)');
    }

    public function down(): void
    {
        Schema::dropIfExists('preco_referencia');
    }
};
