<?php

use App\Models\Restriction;
use App\Models\User;
use App\Services\Nutri\NutriContextBuilder;
use App\Services\Nutri\SuggestionBuilder;
use Carbon\CarbonImmutable;

beforeEach(function () {
    seedCatalog();
    $this->travelTo(CarbonImmutable::parse('2026-09-28 11:00', 'America/Sao_Paulo')); // segunda, dia de treino
    $this->user = login(User::factory()->onboarded()->create());
    $this->user->restrictions()->sync([Restriction::where('slug', 'castanhas')->sole()->id, Restriction::where('slug', 'lactose')->sole()->id]);
    planoPronto($this->user);
});

it('mostra o que o Nutri está olhando: próxima refeição, restante, alergias e objetivo', function () {
    registrarRefeicao('cafe');
    registrarRefeicao('lanche');

    $linhas = $this->getJson('/api/v1/nutri/context')->assertOk()->json('data.lines');

    expect($linhas[0]['text'])->toStartWith('Seu almoço das ')->and($linhas[0]['tone'])->toBe('gema')
        ->and($linhas[1]['text'])->toMatch('/^[\d.]+ kcal e \d+ g de proteína ainda no plano de hoje$/')
        ->and(collect($linhas)->where('tone', 'alerta')->pluck('text')->all())->toBe(['Sua alergia a amendoim e castanhas'])
        ->and(collect($linhas)->last()['tone'])->toBe('mata');
});

it('sem próxima refeição, a primeira linha some', function () {
    foreach (['cafe', 'lanche', 'almoco', 'pre-treino', 'jantar'] as $slot) {
        registrarRefeicao($slot);
    }

    expect($this->getJson('/api/v1/nutri/context')->json('data.lines.0.text'))->not->toStartWith('Seu ');
});

it('o contexto da IA leva a refeição de hoje com food_id e só alimentos permitidos, sem dados pessoais', function () {
    $contexto = app(NutriContextBuilder::class)->forAi($this->user->fresh());
    $json = json_encode($contexto);

    expect($contexto['refeicoes_hoje'])->toHaveCount(5)
        ->and($contexto['refeicoes_hoje'][0]['itens'][0])->toHaveKeys(['food_id', 'nome', 'gramas'])
        ->and($contexto['alergias'])->toBe(['Amendoim e castanhas'])
        ->and(collect($contexto['alimentos_permitidos'])->pluck('nome')->implode(' '))->not->toMatch('/castanha|amendoim|leite/i')
        ->and($json)->not->toContain($this->user->email)
        ->and($json)->not->toContain($this->user->name);
});

it('sugestões de pergunta partem da próxima refeição; treino só em dia de treino', function () {
    $perguntas = collect($this->getJson('/api/v1/nutri/suggestions')->assertOk()->json('data'))->pluck('question');

    expect($perguntas->count())->toBeLessThanOrEqual(4)
        ->and($perguntas->first(fn ($p) => str_starts_with($p, 'O que comer antes do treino das 19:00?')))->not->toBeNull()
        ->and($perguntas->first(fn ($p) => str_starts_with($p, 'Não tenho ')))->not->toBeNull();

    $this->travelTo(CarbonImmutable::parse('2026-09-29 11:00', 'America/Sao_Paulo')); // terça, sem treino
    $perguntas = collect($this->getJson('/api/v1/nutri/suggestions')->json('data'))->pluck('question');
    expect($perguntas->first(fn ($p) => str_contains($p, 'treino')))->toBeNull();
});

it('reserva de continuação tem até 3 perguntas curtas', function () {
    $reserva = app(SuggestionBuilder::class)->continuations($this->user->fresh());

    expect($reserva)->not->toBeEmpty()->toHaveCount(min(3, count($reserva)));
    foreach ($reserva as $pergunta) {
        expect(mb_strlen($pergunta))->toBeLessThanOrEqual(60);
    }
});
