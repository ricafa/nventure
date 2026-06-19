<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mtm_diario', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('posicao_id');
            $table->unsignedInteger('preco_ref_id');
            $table->date('data_calculo');
            $table->decimal('preco_mercado', 18, 6);
            $table->decimal('mtm_valor', 18, 2);
            $table->decimal('variacao_dia', 18, 2);
            $table->decimal('pl_acumulado', 18, 2);
            $table->unsignedInteger('execucao_id')->nullable();
            $table->timestamp('processado_em')->useCurrent();

            // RESTRICT (sem cascade) — preço/posição com MtM não some silenciosamente (RN-010a).
            $table->foreign('posicao_id')->references('id')->on('posicao')->restrictOnDelete();
            $table->foreign('preco_ref_id')->references('id')->on('preco_referencia')->restrictOnDelete();
            $table->foreign('execucao_id')->references('id')->on('motor_execucao')->nullOnDelete();

            $table->unique(['posicao_id', 'data_calculo']);
            $table->index('data_calculo', 'idx_mtm_data');
        });

        // Índice de performance §3.3 (ordenação DESC — fora do Schema builder).
        DB::statement('CREATE INDEX idx_mtm_posicao_data ON mtm_diario(posicao_id, data_calculo DESC)');
    }

    public function down(): void
    {
        Schema::dropIfExists('mtm_diario');
    }
};
