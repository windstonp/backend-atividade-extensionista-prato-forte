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
