<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Consolidação §3.2.10 (Caminho A): a `usuario` é a única tabela que já
        // existia parcialmente; como o projeto está pré-produção, o esquema
        // definitivo é refletido aqui (sem migration de ALTER separada).
        // PK/FK INTEGER (SERIAL) e `criado_em` sem $table->timestamps() seguem a
        // convenção §3.0 comum às 12 tabelas.
        Schema::create('usuario', function (Blueprint $table) {
            $table->increments('id');
            $table->string('login', 60)->unique();
            $table->string('nome', 120);
            $table->string('senha_hash', 255);
            $table->string('perfil', 20);
            $table->boolean('ativo')->default(true);
            $table->timestamp('criado_em')->useCurrent();
            $table->rememberToken();
        });

        DB::statement("ALTER TABLE usuario ADD CONSTRAINT usuario_perfil_check CHECK (perfil IN ('OPERADOR', 'GESTOR', 'ADMIN'))");

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('login')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('usuario');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
