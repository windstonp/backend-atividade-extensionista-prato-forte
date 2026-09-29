<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Contas fixas dos testes E2E do front (senha de todas: senha1234).
 * Rode sobre banco limpo: php artisan migrate:fresh --seeder=E2ESeeder --force
 */
class E2ESeeder extends Seeder
{
    public function run(): void
    {
        User::factory()->onboarded()->create(['name' => 'Camila Réus', 'email' => 'concluido@e2e.pratoforte.test']);

        User::factory()->withCompletedSteps(['objetivo', 'dados'])
            ->create(['name' => 'Nina Souza', 'email' => 'novo@e2e.pratoforte.test']);

        // Uma conta por navegador: o E2E-10 troca a senha, e os projetos rodam em paralelo no CI.
        foreach (['chromium', 'webkit'] as $navegador) {
            User::factory()->onboarded()->create(['name' => 'Rafa Lima', 'email' => "senha-{$navegador}@e2e.pratoforte.test"]);
        }
    }
}
