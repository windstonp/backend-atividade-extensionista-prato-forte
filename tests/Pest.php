<?php

use App\Ai\FakeAiClient;
use App\Models\DayMealItem;
use App\Models\MealPlan;
use App\Models\NutriConversation;
use App\Models\User;
use App\Services\Plans\PlanService;
use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class)
    ->beforeEach(function () {
        // O Sanctum só abre sessão para requisições vindas do front ("stateful"), como no navegador.
        $this->withHeaders(['Origin' => 'http://localhost:3000', 'Referer' => 'http://localhost:3000/']);
    })
    ->in('Feature');

uses(TestCase::class)->in('Unit');

/** Autentica um usuário (criado se não vier) na guarda de sessão. Senha das factories: senha1234. */
function login(?User $user = null): User
{
    $user ??= User::factory()->create();
    app('auth')->forgetGuards(); // trocar de usuário no meio do teste: o guard do Sanctum guarda o anterior
    test()->actingAs($user, 'web');

    return $user;
}

/** Simula outra sessão aberta do usuário (outro celular ou navegador). */
function fakeSession(User $user, string $id): void
{
    DB::table('sessions')->insert([
        'id' => $id,
        'user_id' => $user->id,
        'ip_address' => null,
        'user_agent' => null,
        'payload' => '',
        'last_activity' => now()->timestamp,
    ]);
}

/** Corpo válido de cadastro; sobrescreva o que o teste quiser variar. */
function registerPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Camila Réus',
        'email' => 'camila.reus@gmail.com',
        'password' => 'senha1234',
        'password_confirmation' => 'senha1234',
        'terms_accepted' => true,
        'terms_version' => '2026-09',
    ], $overrides);
}

/**
 * Esquece o estado que o processo de teste guarda entre requisições (guardas e atributos da sessão),
 * para a próxima requisição depender só dos cookies — como uma requisição nova do navegador.
 */
function forgetServerState(): void
{
    app('session.store')->flush();
    app('auth')->forgetGuards();
    app()->forgetInstance('auth.driver'); // Guard::class, que o driver de sessão usa para gravar user_id
}

/** As próximas requisições mandam o cookie de sessão desta resposta, como o navegador faria. */
function followSession(TestResponse $response): void
{
    $name = config('session.cookie');
    test()->withCredentials()->withCookie($name, $response->getCookie($name)->getValue());
    forgetServerState();
}

/** Catálogo de referência (restrições, cozinha e alimentos), como em produção. */
function seedCatalog(): void
{
    test()->seed(CatalogSeeder::class);
}

/** Corpo válido de cada etapa do onboarding (dados da Camila do mock); sobrescreva o que o teste variar. */
function stepPayload(string $step, array $overrides = []): array
{
    $payloads = [
        'objetivo' => ['goal' => 'ganhar-massa'],
        'dados' => ['preferred_name' => 'Camila', 'age' => 27, 'height_cm' => 164, 'weight_kg' => 58.4, 'sex' => 'feminino', 'goal_weight_kg' => 62.0],
        'atividade' => ['activity_level' => 'moderado', 'work_posture' => 'sentada'],
        'preferencias' => ['pantry_items' => ['ovos', 'frango', 'arroz-e-feijao']],
        'restricoes' => ['restrictions' => ['castanhas'], 'other_restrictions' => ['camarão', 'pimenta']],
        'rotina' => ['wake_time' => '06:20', 'training_time' => '19:00', 'sleep_time' => '23:00', 'training_days' => [1, 3, 5], 'lunch_place' => 'marmita'],
    ];

    return array_merge($payloads[$step], $overrides);
}

/** A IA falsa usada em todos os testes (roteiros com queue()/failNext()). */
function fakeAi(): FakeAiClient
{
    return app(FakeAiClient::class);
}

/** Pede e gera (fila síncrona + IA falsa) o plano do usuário; devolve o plano já ativo. */
function planoPronto(User $user): MealPlan
{
    return app(PlanService::class)->requestGeneration($user)->fresh();
}

/** Primeiro item de hoje cujo alimento é do grupo dado (materializa o dia se preciso). */
function itemDeHoje(string $grupo): DayMealItem
{
    test()->getJson('/api/v1/days/today')->assertOk();

    return DayMealItem::with('food')
        ->whereHas('food', fn ($q) => $q->where('group', $grupo))
        ->whereHas('dayMeal', fn ($q) => $q->where('user_id', auth('web')->id())->whereDate('date', today()))
        ->orderBy('id')
        ->firstOrFail();
}

/** Troca o item pela primeira opção da folha e devolve a opção escolhida. */
function trocarPelaPrimeiraOpcao(int $itemId): array
{
    $opcao = test()->getJson("/api/v1/days/today/items/{$itemId}/substitutions")->json('data.options.0');
    test()->postJson("/api/v1/days/today/items/{$itemId}/swap", ['food_id' => $opcao['food_id']])->assertOk();

    return $opcao;
}

/** Itens de uma refeição de hoje como a API mostra (sem ids, que mudam ao desfazer). */
function itensDaRefeicao(string $slot): array
{
    $meal = collect(test()->getJson('/api/v1/days/today')->json('data.meals'))->firstWhere('slot', $slot);

    return array_map(fn ($i) => [$i['food_id'], $i['grams'], $i['source'], $i['replaced_from']], $meal['items']);
}

/** Conversa com uma pergunta e uma resposta, a última em `$quando`. */
function conversaCom(User $user, string $titulo, string $ultima, string $quando): NutriConversation
{
    $conversa = $user->conversations()->create(['title' => $titulo, 'last_message_at' => CarbonImmutable::parse($quando)]);
    $conversa->messages()->create(['role' => 'user', 'content' => $titulo]);
    $conversa->messages()->create(['role' => 'assistant', 'content' => $ultima]);

    return $conversa;
}
