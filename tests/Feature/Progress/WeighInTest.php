<?php

use App\Models\User;
use App\Models\WeighIn;
use App\Services\Progress\WeighInService;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-23 08:00', 'America/Sao_Paulo'));
    $this->user = login(User::factory()->onboarded()->create());
});

it('registra a pesagem de hoje (201) e o peso atual do perfil muda (CA08)', function () {
    $this->postJson('/api/v1/weigh-ins', ['weight_kg' => 58.6])
        ->assertCreated()
        ->assertExactJson(['data' => ['id' => WeighIn::sole()->id, 'date' => '2026-09-23', 'weight_kg' => 58.6], 'meta' => ['replaced' => false]]);

    expect($this->getJson('/api/v1/profile')->json('data.current_weight_kg'))->toBe(58.6);
});

it('registrar de novo no mesmo dia substitui (200, CA02)', function () {
    $this->postJson('/api/v1/weigh-ins', ['weight_kg' => 58.6])->assertCreated();
    $this->postJson('/api/v1/weigh-ins', ['weight_kg' => 58.9])->assertOk()->assertJsonPath('meta.replaced', true);

    expect(WeighIn::count())->toBe(1)->and(WeighIn::sole()->weight_kg)->toBe(58.9);
});

it('aceita data dos últimos 30 dias e lista em ordem crescente', function () {
    $this->postJson('/api/v1/weigh-ins', ['weight_kg' => 58.6])->assertCreated();
    $this->postJson('/api/v1/weigh-ins', ['weight_kg' => 58.1, 'date' => '2026-08-24'])->assertCreated();

    expect($this->getJson('/api/v1/weigh-ins')->assertOk()->json('data'))->toBe([
        ['id' => WeighIn::where('date', '2026-08-24')->sole()->id, 'date' => '2026-08-24', 'weight_kg' => 58.1],
        ['id' => WeighIn::where('date', '2026-09-23')->sole()->id, 'date' => '2026-09-23', 'weight_kg' => 58.6],
    ]);
});

it('recusa peso fora da faixa, mais de uma casa, data futura ou antiga demais', function (array $corpo, string $campo, string $mensagem) {
    $this->postJson('/api/v1/weigh-ins', $corpo)->assertUnprocessable()->assertJsonPath("errors.{$campo}.0", $mensagem);
})->with([
    'leve demais' => [['weight_kg' => 29.9], 'weight_kg', 'O peso precisa ficar entre 30 e 250 kg.'],
    'pesado demais' => [['weight_kg' => 250.1], 'weight_kg', 'O peso precisa ficar entre 30 e 250 kg.'],
    'duas casas' => [['weight_kg' => 58.65], 'weight_kg', 'Use no máximo uma casa decimal.'],
    'amanhã' => [['weight_kg' => 58.6, 'date' => '2026-09-24'], 'date', 'A pesagem não pode ser de um dia que ainda não chegou.'],
    '31 dias atrás' => [['weight_kg' => 58.6, 'date' => '2026-08-23'], 'date', 'Só dá para registrar pesagens dos últimos 30 dias.'],
]);

it('não mostra pesagens de outra pessoa', function () {
    User::factory()->onboarded()->create()->weighIns()->create(['date' => '2026-09-20', 'weight_kg' => 80.0]);

    expect($this->getJson('/api/v1/weigh-ins')->json('data'))->toBe([]);
});

it('onboarding incompleto recebe 409', function () {
    login(User::factory()->answered()->create());

    $this->postJson('/api/v1/weigh-ins', ['weight_kg' => 58.6])->assertStatus(409)->assertJsonPath('code', 'ONBOARDING_INCOMPLETE');
});

it('duas abas ao mesmo tempo: a segunda vira atualização, nunca erro', function () {
    $service = app(WeighInService::class);
    $hoje = CarbonImmutable::today();
    // A outra aba gravou entre a leitura e a escrita desta: o create estoura o índice único.
    WeighIn::creating(function (WeighIn $novo) use ($hoje) {
        WeighIn::flushEventListeners();
        $this->user->weighIns()->create(['date' => $hoje->toDateString(), 'weight_kg' => 58.0]);
        throw new UniqueConstraintViolationException('mysql', 'insert', [], new Exception('Duplicate entry'));
    });

    $resultado = $service->record($this->user, 58.7, $hoje);

    expect($resultado['replaced'])->toBeTrue()->and(WeighIn::count())->toBe(1)->and(WeighIn::sole()->weight_kg)->toBe(58.7);
});

it('peso que não é número: uma mensagem só, com o nome do campo em português', function () {
    $erros = $this->postJson('/api/v1/weigh-ins', ['weight_kg' => 'abc'])->assertUnprocessable()->json('errors.weight_kg');

    expect($erros)->toHaveCount(1)->and($erros[0])->not->toContain('weight')->toContain('peso');
});
