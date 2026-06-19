<?php

namespace Database\Seeders;

use App\Models\Usuario;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        if ($this->command?->getLaravel()->environment('production')) {
            return;
        }

        $usuarios = [
            ['login' => 'admin', 'nome' => 'Administrador Demo', 'perfil' => 'ADMIN'],
            ['login' => 'gestor', 'nome' => 'Gestor Demo', 'perfil' => 'GESTOR'],
            ['login' => 'operador', 'nome' => 'Operador Demo', 'perfil' => 'OPERADOR'],
        ];

        foreach ($usuarios as $usuario) {
            Usuario::query()->updateOrCreate(
                ['login' => $usuario['login']],
                $usuario + ['senha_hash' => 'password', 'ativo' => true],
            );
        }
    }
}
