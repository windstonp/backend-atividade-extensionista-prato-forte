<?php

use App\Models\User;
use App\Models\WeighIn;

beforeEach(fn () => seedCatalog());

it('salva as etapas em ordem e aponta a próxima (RN08)', function () {
    login(User::factory()->create(['name' => 'Camila Réus']));
    $sequencia = ['objetivo' => 'dados', 'dados' => 'atividade', 'atividade' => 'preferencias', 'preferencias' => 'restricoes', 'restricoes' => 'rotina', 'rotina' => 'resumo'];

    foreach ($sequencia as $step => $next) {
        $this->patchJson("/api/v1/profile/steps/{$step}", stepPayload($step))
            ->assertOk()
            ->assertJsonPath('data.next_step', $next)
            ->assertJsonPath('meta', ['plan_effect' => 'none', 'plan_id' => null, 'warnings' => []]);
    }

    $this->getJson('/api/v1/onboarding')
        ->assertOk()
        ->assertJsonPath('data.completed', false)
        ->assertJsonPath('data.completed_steps', array_keys($sequencia))
        ->assertJsonPath('data.answers', [
            'goal' => 'ganhar-massa', 'preferred_name' => 'Camila', 'age' => 27, 'height_cm' => 164, 'weight_kg' => 58.4,
            'sex' => 'feminino', 'goal_weight_kg' => 62, 'goal_weight_source' => 'user',
            'activity_level' => 'moderado', 'work_posture' => 'sentada',
            'pantry_items' => ['ovos', 'frango', 'arroz-e-feijao'], 'restrictions' => ['castanhas'], 'other_restrictions' => ['camarão', 'pimenta'],
            'wake_time' => '06:20', 'training_time' => '19:00', 'sleep_time' => '23:00', 'training_days' => [1, 3, 5], 'lunch_place' => 'marmita',
        ])
        ->assertJsonPath('data.healthy_weight_range', ['min' => 49.8, 'max' => 67]); // JSON: 67.0 vira 67
});

it('retoma de onde parou, com as respostas salvas (CA01)', function () {
    login();
    $this->patchJson('/api/v1/profile/steps/objetivo', stepPayload('objetivo'))->assertOk();
    $this->patchJson('/api/v1/profile/steps/dados', stepPayload('dados'))->assertOk();

    $this->getJson('/api/v1/me')->assertJsonPath('data.next_step', 'atividade');
    $this->getJson('/api/v1/onboarding')
        ->assertJsonPath('data.next_step', 'atividade')
        ->assertJsonPath('data.answers.age', 27)
        ->assertJsonPath('data.answers.weight_kg', 58.4);
});

it('usa o primeiro nome do cadastro como nome preferido até a pessoa escolher outro', function () {
    login(User::factory()->create(['name' => 'Rafael Lima Souza']));

    $this->getJson('/api/v1/onboarding')
        ->assertJsonPath('data.answers.preferred_name', 'Rafael')
        ->assertJsonPath('data.healthy_weight_range', null);
});

dataset('etapas inválidas', [
    'objetivo vazio' => ['objetivo', ['goal' => null], 'goal', 'Escolha um objetivo.'],
    'objetivo desconhecido' => ['objetivo', ['goal' => 'ficar-forte'], 'goal', 'Escolha um objetivo.'],
    'nome vazio' => ['dados', ['preferred_name' => ''], 'preferred_name', 'Diga como podemos te chamar (até 40 letras).'],
    'nome com 41 letras' => ['dados', ['preferred_name' => str_repeat('a', 41)], 'preferred_name', 'Diga como podemos te chamar (até 40 letras).'],
    'idade 17 (P2)' => ['dados', ['age' => 17], 'age', 'Use uma idade entre 18 e 100 anos.'],
    'idade 101' => ['dados', ['age' => 101], 'age', 'Use uma idade entre 18 e 100 anos.'],
    'idade quebrada' => ['dados', ['age' => 27.5], 'age', 'Use uma idade entre 18 e 100 anos.'],
    'altura 119' => ['dados', ['height_cm' => 119], 'height_cm', 'Use a altura em centímetros, entre 120 e 230.'],
    'altura 231' => ['dados', ['height_cm' => 231], 'height_cm', 'Use a altura em centímetros, entre 120 e 230.'],
    'peso 29,9' => ['dados', ['weight_kg' => 29.9], 'weight_kg', 'Use um peso entre 30 e 250 kg, com até uma casa decimal.'],
    'peso 250,1' => ['dados', ['weight_kg' => 250.1], 'weight_kg', 'Use um peso entre 30 e 250 kg, com até uma casa decimal.'],
    'peso com 2 casas' => ['dados', ['weight_kg' => 58.45], 'weight_kg', 'Use um peso entre 30 e 250 kg, com até uma casa decimal.'],
    'sexo desconhecido' => ['dados', ['sex' => 'outro'], 'sex', 'Escolha uma opção.'],
    'meta abaixo do peso ao ganhar (CA02)' => ['dados', ['goal_weight_kg' => 55.0], 'goal_weight_kg', 'Para ganhar massa, a meta precisa ser maior que o peso de hoje.'],
    'meta acima do limite' => ['dados', ['goal_weight_kg' => 251.0], 'goal_weight_kg', 'Use uma meta entre 30 e 250 kg.'],
    'atividade vazia' => ['atividade', ['activity_level' => null], 'activity_level', 'Escolha quantas vezes você treina.'],
    'trabalho desconhecido' => ['atividade', ['work_posture' => 'deitada'], 'work_posture', 'Escolha como é seu trabalho.'],
    'item de cozinha desconhecido' => ['preferencias', ['pantry_items' => ['caviar']], 'pantry_items.0', 'Confira os itens da cozinha.'],
    'restrição desconhecida' => ['restricoes', ['restrictions' => ['cebola']], 'restrictions.0', 'Confira as restrições.'],
    'mais de 10 outras' => ['restricoes', ['other_restrictions' => array_map(fn (int $i) => "item {$i}", range(1, 11))], 'other_restrictions', 'Use no máximo 10 itens.'],
    'outra curta demais' => ['restricoes', ['other_restrictions' => ['a']], 'other_restrictions.0', 'Cada item precisa ter de 2 a 60 letras.'],
    'outra repetida' => ['restricoes', ['other_restrictions' => ['Camarão', 'camarão']], 'other_restrictions.1', 'Esse item já está na lista.'],
    'hora sem formato' => ['rotina', ['wake_time' => '6h20'], 'wake_time', 'Use o formato 06:20.'],
    'dia 7' => ['rotina', ['training_days' => [7]], 'training_days.0', 'Confira os dias de treino.'],
    'dia repetido' => ['rotina', ['training_days' => [1, 1]], 'training_days.1', 'Confira os dias de treino.'],
    'almoço desconhecido' => ['rotina', ['lunch_place' => 'lanchonete'], 'lunch_place', 'Escolha onde você almoça.'],
    'treino antes de acordar (CA06)' => ['rotina', ['training_time' => '05:00'], 'training_time', 'O treino precisa estar entre a hora que você acorda e a que dorme.'],
    'dia acordado com menos de 12 h' => ['rotina', ['wake_time' => '09:00', 'training_time' => '12:00', 'sleep_time' => '20:00'], 'sleep_time', 'Seu dia acordado precisa ter pelo menos 12 horas.'],
]);

it('recusa o que foge das regras (§6), com a mensagem no campo', function (string $step, array $overrides, string $field, string $message) {
    login(User::factory()->answered()->create());

    $this->patchJson("/api/v1/profile/steps/{$step}", stepPayload($step, $overrides))
        ->assertUnprocessable()
        ->assertJsonPath('code', 'VALIDATION_ERROR')
        ->assertJsonValidationErrors([$field => $message]);
})->with('etapas inválidas');

it('aceita sono depois da meia-noite (RN12)', function () {
    login(User::factory()->answered()->create());

    $this->patchJson('/api/v1/profile/steps/rotina', stepPayload('rotina', ['wake_time' => '10:00', 'training_time' => '23:00', 'sleep_time' => '01:30']))
        ->assertOk()
        ->assertJsonPath('data.answers.sleep_time', '01:30');
});

it('aceita meta fora da faixa saudável e avisa (CA03)', function () {
    login(User::factory()->answered()->create());

    $this->patchJson('/api/v1/profile/steps/dados', stepPayload('dados', ['goal_weight_kg' => 75.0]))
        ->assertOk()
        ->assertJsonPath('data.answers.goal_weight_kg', 75)
        ->assertJsonPath('data.answers.goal_weight_source', 'user')
        ->assertJsonPath('meta.warnings', ['GOAL_WEIGHT_OUT_OF_HEALTHY_RANGE']);
});

it('recusa meta quando o objetivo não usa meta (RN10)', function () {
    $user = User::factory()->answered()->create();
    $user->profile->update(['goal' => 'manter-peso', 'goal_weight_kg' => null, 'goal_weight_source' => null]);
    login($user);

    $this->patchJson('/api/v1/profile/steps/dados', stepPayload('dados', ['goal_weight_kg' => 60.0]))
        ->assertJsonValidationErrors(['goal_weight_kg' => 'Esse objetivo não usa meta de peso.']);
});

it('durante o onboarding, a meta vazia espera a conclusão (RN10)', function () {
    login(User::factory()->answered()->create());

    $this->patchJson('/api/v1/profile/steps/dados', stepPayload('dados', ['goal_weight_kg' => null]))
        ->assertOk()
        ->assertJsonPath('data.answers.goal_weight_kg', null)
        ->assertJsonPath('data.answers.goal_weight_source', null);
});

it('trocar para um objetivo que não combina com a meta apaga a meta e avisa (RN11)', function () {
    login(User::factory()->answered()->create()); // ganhar massa, meta 62

    $this->patchJson('/api/v1/profile/steps/objetivo', ['goal' => 'perder-gordura'])
        ->assertOk()
        ->assertJsonPath('data.answers.goal', 'perder-gordura')
        ->assertJsonPath('data.answers.goal_weight_kg', null)
        ->assertJsonPath('meta.warnings', ['GOAL_WEIGHT_RESET']);
});

it('manter o objetivo não mexe na meta', function () {
    login(User::factory()->answered()->create());

    $this->patchJson('/api/v1/profile/steps/objetivo', ['goal' => 'ganhar-massa'])
        ->assertJsonPath('data.answers.goal_weight_kg', 62)
        ->assertJsonPath('meta.warnings', []);
});

it('depois do onboarding, trocar o objetivo já sugere a meta nova (RN11)', function () {
    login(User::factory()->onboarded()->create());

    $this->patchJson('/api/v1/profile/steps/objetivo', ['goal' => 'perder-gordura'])
        ->assertJsonPath('data.answers.goal_weight_kg', 55.5)
        ->assertJsonPath('data.answers.goal_weight_source', 'suggested')
        ->assertJsonPath('meta.warnings', ['GOAL_WEIGHT_RESET']);
});

it('depois do onboarding, o peso de hoje vira a pesagem do dia e o peso inicial fica (RN34)', function () {
    $user = login(User::factory()->onboarded()->create());

    $this->patchJson('/api/v1/profile/steps/dados', stepPayload('dados', ['weight_kg' => 59.0]))->assertJsonPath('data.answers.weight_kg', 59);
    $this->patchJson('/api/v1/profile/steps/dados', stepPayload('dados', ['weight_kg' => 59.3]))->assertJsonPath('data.answers.weight_kg', 59.3);

    expect(WeighIn::where('user_id', $user->id)->count())->toBe(1)
        ->and(WeighIn::where('user_id', $user->id)->sole()->weight_kg)->toBe(59.3)
        ->and((float) $user->profile->fresh()->start_weight_kg)->toBe(58.4);
});

it('depois do onboarding, meta vazia vira a sugerida na hora', function () {
    login(User::factory()->onboarded()->create());

    $this->patchJson('/api/v1/profile/steps/dados', stepPayload('dados', ['goal_weight_kg' => null]))
        ->assertJsonPath('data.answers.goal_weight_kg', 61.5)
        ->assertJsonPath('data.answers.goal_weight_source', 'suggested');
});

it('substitui a lista da cozinha e a das restrições a cada envio', function () {
    login(User::factory()->answered()->create());

    $this->patchJson('/api/v1/profile/steps/preferencias', ['pantry_items' => ['ovos', 'banana']])->assertOk();
    $this->patchJson('/api/v1/profile/steps/preferencias', ['pantry_items' => ['maca']])
        ->assertJsonPath('data.answers.pantry_items', ['maca']);

    $this->patchJson('/api/v1/profile/steps/restricoes', ['restrictions' => ['castanhas'], 'other_restrictions' => []])->assertOk();
    $this->patchJson('/api/v1/profile/steps/restricoes', ['restrictions' => [], 'other_restrictions' => ['  pimenta ']])
        ->assertJsonPath('data.answers.restrictions', [])
        ->assertJsonPath('data.answers.other_restrictions', ['pimenta']);
});

it('responde 404 para etapa que não existe ou que não se salva', function (string $step) {
    login();

    $this->patchJson("/api/v1/profile/steps/{$step}", [])->assertNotFound()->assertJsonPath('code', 'NOT_FOUND');
})->with(['resumo', 'bonus']);

it('exige login', function () {
    $this->patchJson('/api/v1/profile/steps/objetivo', stepPayload('objetivo'))->assertUnauthorized();
    $this->getJson('/api/v1/onboarding')->assertUnauthorized();
});

it('salvar os dados sem mudar o peso não inventa pesagem de hoje (RN34)', function () {
    $user = login(User::factory()->onboarded()->create());
    $user->weighIns()->create(['date' => today()->subDays(21), 'weight_kg' => 60.0]);

    $this->patchJson('/api/v1/profile/steps/dados', stepPayload('dados', ['preferred_name' => 'Mila', 'weight_kg' => 60.0]))->assertOk();

    expect(WeighIn::where('user_id', $user->id)->count())->toBe(1)
        ->and(WeighIn::where('user_id', $user->id)->sole()->date->isToday())->toBeFalse();
});

it('meta sugerida que ainda combina não anda junto com o peso', function () {
    $user = User::factory()->onboarded()->create();
    $user->profile->update(['goal_weight_kg' => 61.5, 'goal_weight_source' => 'suggested']);
    login($user);

    $this->patchJson('/api/v1/profile/steps/dados', stepPayload('dados', ['weight_kg' => 60.0, 'goal_weight_kg' => null]))
        ->assertJsonPath('data.answers.goal_weight_kg', 61.5)
        ->assertJsonPath('data.answers.goal_weight_source', 'suggested');
});
