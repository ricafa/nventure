<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('motor_execucao', function (Blueprint $table) {
            $table->increments('id');
            $table->date('data_calculo');
            $table->string('disparado_por', 60);
            $table->timestamp('iniciado_em')->useCurrent();
            // Nullable enquanto a execução está em andamento (§3.2.9).
            $table->timestamp('finalizado_em')->nullable();
            $table->integer('total_posicoes')->nullable();
            $table->integer('sucessos')->nullable();
            // JSONB (não json/text) — uma das razões de o MVP exigir Postgres (§3.0).
            $table->jsonb('falhas')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('motor_execucao');
    }
};
