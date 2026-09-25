<?php

use Illuminate\Support\Facades\DB;

it('usa o fuso e o idioma de Capivari de Baixo', function () {
    expect(config('app.timezone'))->toBe('America/Sao_Paulo')
        ->and(app()->getLocale())->toBe('pt_BR');
});

it('testa contra MySQL 8 com collation sem acento e sem caixa', function () {
    expect(DB::connection()->getDriverName())->toBe('mysql')
        ->and(DB::connection()->getConfig('collation'))->toBe('utf8mb4_0900_ai_ci')
        ->and(DB::connection()->getDatabaseName())->toBe('prato_forte_test');
});
