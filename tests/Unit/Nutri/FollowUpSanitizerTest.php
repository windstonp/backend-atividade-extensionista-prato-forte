<?php

use App\Models\Restriction;
use App\Models\User;
use App\Services\Nutri\FollowUpSanitizer;

beforeEach(function () {
    seedCatalog();
    $this->user = User::factory()->onboarded()->create();
    $this->user->restrictions()->sync([Restriction::where('slug', 'castanhas')->sole()->id]);
});

it('filtra vazias, duplicadas, longas, repetição da pergunta e alimento proibido; mantém 3 (RN45)', function () {
    $limpas = app(FollowUpSanitizer::class)->clean([
        '', '  ', 'E no jantar?', 'e no JANTAR?', str_repeat('a', 61),
        'Posso trocar o arroz?', 'Posso pôr castanha-do-pará no lanche?', 'Por que mais fibra?', 'E o café?', 'E a ceia?',
    ], 'Posso trocar o arroz?', $this->user);

    expect($limpas)->toBe(['E no jantar?', 'Por que mais fibra?', 'E o café?']);
});

it('pega nome curto, alias e plural do alimento proibido (revisão 05A)', function (string $restricao, string $sugestao) {
    $this->user->restrictions()->sync([Restriction::where('slug', $restricao)->sole()->id]);

    expect(app(FollowUpSanitizer::class)->clean([$sugestao, 'E no jantar?'], 'Oi', $this->user->fresh()))->toBe(['E no jantar?']);
})->with([
    'glúten: pão' => ['gluten', 'Posso comer pão no café?'],
    'frutos do mar: plural' => ['frutos-do-mar', 'Posso comer camarões?'],
    'sem animal: ovo' => ['sem-animal', 'E se eu comer um ovo?'],
]);

it('não barra sugestão só por parecer com alimento permitido', function () {
    expect(app(FollowUpSanitizer::class)->clean(['E o arroz do almoço?'], 'Oi', $this->user))->toBe(['E o arroz do almoço?']);
});

it('"outras restrições" digitadas também valem', function () {
    $this->user->profile->update(['other_restrictions' => ['beterraba']]);

    expect(app(FollowUpSanitizer::class)->clean(['Posso comer beterrabas?'], 'Oi', $this->user->fresh()))->toBe([]);
});

it('alimento do catálogo ampliado ligado à alergia continua barrado; o resto do catálogo ampliado não (RN45, RN52)', function () {
    $limpas = app(FollowUpSanitizer::class)->clean(['Posso comer paçoca no lanche?', 'E um pé-de-moleque?', 'E o cuscuz?'], 'Oi', $this->user);

    expect($limpas)->toBe(['E o cuscuz?']);
});
