<?php

namespace Database\Seeders;

use App\Ai\AiClient;
use App\Ai\FakeAiClient;
use App\Ai\LoggingAiClient;
use App\Models\Restriction;
use App\Models\User;
use App\Services\Plans\PlanService;
use Illuminate\Database\Seeder;

/**
 * Contas fixas dos testes E2E do front (senha de todas: senha1234).
 * Rode sobre banco limpo: php artisan migrate:fresh --seeder=E2ESeeder --force
 */
class E2ESeeder extends Seeder
{
    public function run(): void
    {
        $this->call(CatalogSeeder::class);

        $novo = User::factory()->withCompletedSteps(['objetivo', 'dados'])
            ->create(['name' => 'Nina Souza', 'email' => 'novo@e2e.pratoforte.test']);
        $novo->profile->update([
            'goal' => 'perder-gordura', 'preferred_name' => 'Nina', 'age' => 30, 'height_cm' => 170, 'start_weight_kg' => 70.0, 'sex' => 'feminino',
        ]);

        // Contas concluídas já com plano pronto: IA falsa e fila síncrona só durante o seed,
        // para nunca chamar a IA de verdade nem depender do worker.
        config(['queue.default' => 'sync']);
        app()->instance(AiClient::class, new LoggingAiClient(app(FakeAiClient::class)));
        $planos = app(PlanService::class);

        // Uma conta por navegador: o E2E-10 troca a senha, e o login aceita só 5 tentativas
        // por minuto por e-mail — os dois navegadores juntos na mesma conta passariam disso.
        foreach (['chromium', 'webkit'] as $navegador) {
            foreach ([['Camila Réus', 'concluido'], ['Rafa Lima', 'senha']] as [$nome, $conta]) {
                $user = User::factory()->onboarded()->create(['name' => $nome, 'email' => "{$conta}-{$navegador}@e2e.pratoforte.test"]);
                $planos->requestGeneration($user);
            }

            // 04B: uma conta por teste que muda estado, para não passar do limite de login.
            foreach ([['Dora Dias', 'dia'], ['Ana Alves', 'alergia'], ['Mauro Mendes', 'mudanca'], ['Nara Nunes', 'nutri']] as [$nome, $conta]) {
                $user = User::factory()->onboarded()->create(['name' => $nome, 'email' => "{$conta}-{$navegador}@e2e.pratoforte.test"]);
                if ($conta === 'alergia') {
                    $user->restrictions()->sync([Restriction::where('slug', 'castanhas')->sole()->id]);
                }
                $planos->requestGeneration($user);
            }

            // E2E-07: parada no resumo; com AI_FAKE_FAIL_PLAN_FOR, a primeira geração falha.
            User::factory()->answered()->create(['name' => 'Fábio Faria', 'email' => "falha-{$navegador}@e2e.pratoforte.test"]);
        }
    }
}
