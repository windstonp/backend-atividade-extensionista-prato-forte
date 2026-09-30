<?php

use App\Services\Notifications\AwakeWindow;
use Carbon\CarbonImmutable;

it('dentro e fora da janela acordado', function (string $acorda, string $dorme, string $hora, bool $dentro) {
    expect(AwakeWindow::contains($acorda, $dorme, CarbonImmutable::parse("2026-09-28 {$hora}")))->toBe($dentro);
})->with([
    'meio da manhã' => ['06:20', '23:00', '10:00', true],
    'antes de acordar' => ['06:20', '23:00', '06:05', false],
    'na hora de acordar' => ['06:20', '23:00', '06:20', true],
    'depois de dormir' => ['06:20', '23:00', '23:15', false],
    'dorme depois da meia-noite, 23:15' => ['08:00', '01:00', '23:15', true],
    'dorme depois da meia-noite, 00:30' => ['08:00', '01:00', '00:30', true],
    'dorme depois da meia-noite, 03:00' => ['08:00', '01:00', '03:00', false],
    'com segundos do banco' => ['06:20:00', '23:00:00', '12:15', true],
]);
