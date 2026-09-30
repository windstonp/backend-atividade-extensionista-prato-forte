<?php

use App\Ai\Schemas\NutriResponse;

it('lê o JSON completo', function () {
    $r = NutriResponse::parse('{"reply":"Pode.","follow_up":"Mais fibra.","suggestions":["E no jantar?"],"action":{"type":"substituir","slot":"almoco","from_food_id":1,"to_food_id":2}}');

    expect($r->reply)->toBe('Pode.')->and($r->followUp)->toBe('Mais fibra.')
        ->and($r->suggestions)->toBe(['E no jantar?'])->and($r->action['type'])->toBe('substituir');
});

it('aceita JSON com texto em volta', function () {
    expect(NutriResponse::parse("Claro!\n```json\n{\"reply\":\"Pode.\"}\n```")->reply)->toBe('Pode.');
});

it('não-JSON vira só texto, sem ação', function () {
    $r = NutriResponse::parse('Pode trocar sim.');
    expect($r->reply)->toBe('Pode trocar sim.')->and($r->action)->toBeNull()->and($r->suggestions)->toBe([]);
});

it('campos de tipo errado são ignorados; resposta vazia vira a padrão', function () {
    $r = NutriResponse::parse('{"reply":"Oi","suggestions":"não é lista","action":"x","follow_up":3}');
    expect($r->suggestions)->toBe([])->and($r->action)->toBeNull()->and($r->followUp)->toBeNull();

    expect(NutriResponse::parse('  ')->reply)->toBe('Não consegui montar uma resposta agora. Pode perguntar de novo?')
        ->and(NutriResponse::parse('{"reply":""}')->reply)->toBe('Não consegui montar uma resposta agora. Pode perguntar de novo?');
});

it('JSON cortado no meio não vira texto cru na tela (revisão 05A)', function () {
    expect(NutriResponse::parse('{"reply":"Pode. No seu almoço os 150 g de arroz","suggestions":["E no'))->reply->toBe('Pode. No seu almoço os 150 g de arroz');
    expect(NutriResponse::parse('{"suggestions":["E no'))->reply->toBe(NutriResponse::FALLBACK);
});
