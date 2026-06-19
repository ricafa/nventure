<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('posicao_opcao_perna', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('posicao_id');
            $table->smallInteger('sequencia');
            $table->string('tipo_opcao', 4);
            $table->string('estilo', 10);
            $table->decimal('strike', 18, 6);
            $table->decimal('premio_pago', 18, 6);
            $table->decimal('quantidade', 18, 4);
            $table->string('lado', 10);

            $table->foreign('posicao_id')->references('posicao_id')->on('posicao_opcao')->cascadeOnDelete();
            $table->unique(['posicao_id', 'sequencia']);
        });

        DB::statement("ALTER TABLE posicao_opcao_perna ADD CONSTRAINT perna_tipo_opcao_check CHECK (tipo_opcao IN ('CALL','PUT'))");
        DB::statement("ALTER TABLE posicao_opcao_perna ADD CONSTRAINT perna_estilo_check CHECK (estilo IN ('EUROPEIA','AMERICANA'))");
        DB::statement("ALTER TABLE posicao_opcao_perna ADD CONSTRAINT perna_lado_check CHECK (lado IN ('COMPRADO','VENDIDO'))");
        DB::statement('ALTER TABLE posicao_opcao_perna ADD CONSTRAINT perna_sequencia_check CHECK (sequencia > 0)');
        DB::statement('ALTER TABLE posicao_opcao_perna ADD CONSTRAINT perna_strike_check CHECK (strike > 0)');
        DB::statement('ALTER TABLE posicao_opcao_perna ADD CONSTRAINT perna_premio_pago_check CHECK (premio_pago >= 0)');
        DB::statement('ALTER TABLE posicao_opcao_perna ADD CONSTRAINT perna_quantidade_check CHECK (quantidade > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('posicao_opcao_perna');
    }
};
