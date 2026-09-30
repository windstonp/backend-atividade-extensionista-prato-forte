<?php

use App\Jobs\GeneratePlanJob;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

beforeEach(fn () => seedCatalog());

it('pede um plano e responde 202 (RF09)', function () {
    Queue::fake();
    login(User::factory()->onboarded()->create());

    $response = $this->postJson('/api/v1/plans')->assertAccepted()->assertJsonPath('data.status', 'pending');

    Queue::assertPushed(GeneratePlanJob::class, fn ($job) => $job->planId === $response->json('data.id'));
});

it('recusa um segundo pedido enquanto o primeiro gera (RN19)', function () {
    Queue::fake();
    login(User::factory()->onboarded()->create());
    $primeiro = $this->postJson('/api/v1/plans')->json('data.id');

    $this->postJson('/api/v1/plans')
        ->assertStatus(409)
        ->assertJsonPath('code', 'PLAN_ALREADY_GENERATING')
        ->assertJsonPath('details.plan_id', $primeiro);
});

it('recusa antes de concluir o onboarding (RN07)', function () {
    login(User::factory()->answered()->create());

    $this->postJson('/api/v1/plans')->assertStatus(409)->assertJsonPath('code', 'ONBOARDING_INCOMPLETE');
});

it('limita a 5 gerações por dia (RN19)', function () {
    login(User::factory()->onboarded()->create());

    foreach (range(1, 5) as $_) {
        $this->postJson('/api/v1/plans')->assertAccepted();
    }

    $this->postJson('/api/v1/plans')->assertStatus(429)->assertJsonPath('code', 'TOO_MANY_REQUESTS');
});

it('mostra o plano pronto com metas e refeições, e o ativo com os itens', function () {
    login(User::factory()->onboarded()->create());
    $id = $this->postJson('/api/v1/plans')->json('data.id');

    $this->getJson("/api/v1/plans/{$id}")
        ->assertOk()
        ->assertJsonPath('data.status', 'ready')
        ->assertJsonPath('data.is_active', true)
        ->assertJsonPath('data.targets', ['kcal' => 2250, 'protein_g' => 115, 'carbs_g' => 305, 'fat_g' => 65])
        ->assertJsonPath('data.meals.*.time', ['07:00', '10:00', '12:30', '17:30', '20:30'])
        ->assertJsonPath('data.rating', null)
        ->assertJsonStructure(['data' => ['ready_at', 'meals' => [['slot', 'name', 'time', 'calories', 'summary']]]])
        ->assertJsonMissingPath('data.meals.0.items');

    $this->getJson('/api/v1/plans/active')
        ->assertOk()
        ->assertJsonPath('data.id', $id)
        ->assertJsonStructure(['data' => ['meals' => [['items' => [['food_id', 'name', 'grams', 'amount', 'calories', 'macros' => ['protein', 'carbs', 'fat']]]]]]]);
});

it('mostra o motivo quando falhou', function () {
    fakeAi()->failNext('plan');
    login(User::factory()->onboarded()->create());
    $id = $this->postJson('/api/v1/plans')->json('data.id');

    $this->getJson("/api/v1/plans/{$id}")
        ->assertExactJson(['data' => ['id' => $id, 'status' => 'failed', 'is_active' => false, 'failure_reason' => 'AI_UNAVAILABLE']]);
});

it('não mostra o plano de outra pessoa: 404 (CA12, RN43)', function () {
    login(User::factory()->onboarded()->create());
    $daAna = $this->postJson('/api/v1/plans')->json('data.id');

    login(User::factory()->onboarded()->create());

    $this->getJson("/api/v1/plans/{$daAna}")->assertNotFound()->assertJsonPath('code', 'NOT_FOUND');
});

it('sem plano ativo responde NO_ACTIVE_PLAN', function () {
    login(User::factory()->onboarded()->create());

    $this->getJson('/api/v1/plans/active')->assertStatus(409)->assertJsonPath('code', 'NO_ACTIVE_PLAN');
});
