<?php

use App\Jobs\GeneratePlanJob;

it('a fila só devolve um job depois do timeout do mais longo (geração do plano)', function () {
    $job = (new ReflectionClass(GeneratePlanJob::class))->getDefaultProperties()['timeout'];

    expect(config('queue.connections.database.retry_after'))->toBeGreaterThan($job);
});
