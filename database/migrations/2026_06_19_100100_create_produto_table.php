<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('produto', function (Blueprint $table) {
            $table->increments('id');
            $table->string('nome', 60)->unique();
            $table->string('unidade', 20);
            $table->string('bolsa_ref', 20);
            $table->string('moeda_cotacao', 3);
            $table->boolean('ativo')->default(true);
            $table->timestamp('criado_em')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('produto');
    }
};
