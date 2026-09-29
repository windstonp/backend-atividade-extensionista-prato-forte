<?php

beforeEach(fn () => seedCatalog());

it('lista as opções de todas as etapas, sem exigir onboarding', function () {
    login();

    $this->getJson('/api/v1/catalog/onboarding')
        ->assertOk()
        ->assertJsonCount(4, 'data.goals')
        ->assertJsonPath('data.goals.0', ['value' => 'ganhar-massa', 'label' => 'Ganhar massa magra', 'description' => 'Comer um pouco acima do gasto, com proteína alta todo dia.'])
        ->assertJsonPath('data.activity_levels.2', ['value' => 'moderado', 'label' => '3 ou 4 vezes na semana', 'description' => 'O ritmo da maior parte do pessoal da Zfit.'])
        ->assertJsonPath('data.work_postures.1', ['value' => 'em-pe', 'label' => 'Em pé'])
        ->assertJsonPath('data.restrictions.2', ['slug' => 'castanhas', 'label' => 'Amendoim e castanhas', 'is_allergy' => true])
        ->assertJsonCount(6, 'data.restrictions')
        ->assertJsonCount(3, 'data.pantry')
        ->assertJsonPath('data.pantry.0.category', 'proteinas')
        ->assertJsonPath('data.pantry.0.label', 'Proteínas')
        ->assertJsonPath('data.pantry.0.items.0', ['slug' => 'ovos', 'label' => 'Ovos'])
        ->assertJsonPath('data.pantry.2.items.*.slug', ['banana', 'mamao', 'maca', 'laranja'])
        ->assertJsonPath('data.dislike_options.*.name', ['Berinjela', 'Beterraba', 'Fígado bovino', 'Jiló', 'Peixe assado'])
        ->assertJsonPath('data.lunch_places.1', ['value' => 'marmita', 'label' => 'Marmita no trabalho']);
});

it('exige login', function () {
    $this->getJson('/api/v1/catalog/onboarding')->assertUnauthorized();
});
