<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('posicao', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('produto_id');
            $table->string('instrumento', 10);
            $table->string('mercado', 10);
            $table->string('lado', 10);
            $table->decimal('quantidade', 18, 4);
            $table->date('data_entrada');
            $table->date('data_vencimento');
            $table->string('contraparte', 100)->nullable();
            $table->string('status', 15)->default('ABERTA');
            $table->text('observacoes')->nullable();
            $table->timestamp('criado_em')->useCurrent();
            $table->string('criado_por', 60);

            $table->foreign('produto_id')->references('id')->on('produto');
            $table->index('produto_id', 'idx_posicao_produto');
        });

        // CHECKs de domínio (§3.2) a nível de banco.
        DB::statement("ALTER TABLE posicao ADD CONSTRAINT posicao_instrumento_check CHECK (instrumento IN ('FUTURO','NDF','OPCAO','OTC'))");
        DB::statement("ALTER TABLE posicao ADD CONSTRAINT posicao_mercado_check CHECK (mercado IN ('BOLSA','BALCAO'))");
        DB::statement("ALTER TABLE posicao ADD CONSTRAINT posicao_lado_check CHECK (lado IN ('COMPRADO','VENDIDO'))");
        DB::statement("ALTER TABLE posicao ADD CONSTRAINT posicao_status_check CHECK (status IN ('ABERTA','ENCERRADA','VENCIDA'))");
        DB::statement('ALTER TABLE posicao ADD CONSTRAINT posicao_quantidade_check CHECK (quantidade >= 0)');

        // Índice parcial §3.3 — só posições abertas.
        DB::statement("CREATE INDEX idx_posicao_status ON posicao(status) WHERE status = 'ABERTA'");
    }

    public function down(): void
    {
        Schema::dropIfExists('posicao');
    }
};
