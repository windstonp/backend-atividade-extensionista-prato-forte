<?php

use App\Ai\Schemas\InvalidAiResponse;
use App\Ai\Schemas\PlanResponse;

it('lê o JSON do contrato, convertendo números', function () {
    $meals = PlanResponse::parse('{"meals":[{"slot":"cafe","items":[{"food_id":"31","grams":150},{"food_id":40,"grams":"50.5"}]}]}');

    expect($meals)->toBe([['slot' => 'cafe', 'items' => [['food_id' => 31, 'grams' => 150.0], ['food_id' => 40, 'grams' => 50.5]]]]);
});

it('acha o objeto JSON mesmo com texto em volta', function () {
    $meals = PlanResponse::parse("Aqui está o plano:\n```json\n{\"meals\":[{\"slot\":\"jantar\",\"items\":[{\"food_id\":1,\"grams\":100}]}]}\n```\nBom apetite!");

    expect($meals[0]['slot'])->toBe('jantar');
});

it('recusa o que não é o contrato, dizendo o motivo', function (string $content, string $motivo) {
    expect(fn () => PlanResponse::parse($content))->toThrow(InvalidAiResponse::class, $motivo);
})->with([
    'texto solto' => ['não sei montar', 'JSON'],
    'sem meals' => ['{"refeicoes":[]}', '"meals"'],
    'item sem food_id' => ['{"meals":[{"slot":"cafe","items":[{"grams":100}]}]}', 'food_id'],
    'slot que não é texto' => ['{"meals":[{"slot":3,"items":[]}]}', 'slot'],
]);

it('aceita a lista de refeições solta ou embrulhada em outra chave (modelos menores fazem isso)', function (string $content) {
    expect(PlanResponse::parse($content)[0]['slot'])->toBe('cafe');
})->with([
    'lista solta' => ['[{"slot":"cafe","items":[{"food_id":1,"grams":100}]}]'],
    'embrulhada' => ['{"plano":{"meals":[{"slot":"cafe","items":[{"food_id":1,"grams":100}]}]}}'],
]);
