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
        User::factory()->withCompletedSteps(['objetivo', 'dados'])
            ->create(['name' => 'Nina Souza', 'email' => 'novo@e2e.pratoforte.test']);

        // Uma conta por navegador: o E2E-10 troca a senha, e o login aceita só 5 tentativas
        // por minuto por e-mail — os dois navegadores juntos na mesma conta passariam disso.
        foreach (['chromium', 'webkit'] as $navegador) {
            User::factory()->onboarded()->create(['name' => 'Camila Réus', 'email' => "concluido-{$navegador}@e2e.pratoforte.test"]);
            User::factory()->onboarded()->create(['name' => 'Rafa Lima', 'email' => "senha-{$navegador}@e2e.pratoforte.test"]);
        }
    }
}
