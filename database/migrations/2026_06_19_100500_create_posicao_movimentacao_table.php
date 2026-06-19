<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('posicao_movimentacao', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('posicao_id');
            $table->string('tipo', 10);
            $table->date('data_movimentacao');
            $table->decimal('quantidade', 18, 4);
            $table->decimal('preco', 18, 6);
            $table->timestamp('criado_em')->useCurrent();
            $table->string('criado_por', 60);

            $table->foreign('posicao_id')->references('id')->on('posicao')->cascadeOnDelete();
            $table->index(['posicao_id', 'data_movimentacao', 'id'], 'idx_mov_posicao_data');
        });

        DB::statement("ALTER TABLE posicao_movimentacao ADD CONSTRAINT mov_tipo_check CHECK (tipo IN ('ABERTURA','AUMENTO','REDUCAO'))");
        DB::statement('ALTER TABLE posicao_movimentacao ADD CONSTRAINT mov_quantidade_check CHECK (quantidade > 0)');
        DB::statement('ALTER TABLE posicao_movimentacao ADD CONSTRAINT mov_preco_check CHECK (preco > 0)');

        // RN-020 — exatamente uma ABERTURA por posição (índice único parcial; exige Postgres).
        DB::statement("CREATE UNIQUE INDEX uq_mov_abertura ON posicao_movimentacao(posicao_id) WHERE tipo = 'ABERTURA'");
    }

    public function down(): void
    {
        Schema::dropIfExists('posicao_movimentacao');
    }
};
