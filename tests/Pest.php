<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class)
    ->beforeEach(function () {
        // O Sanctum só abre sessão para requisições vindas do front ("stateful"), como no navegador.
        $this->withHeaders(['Origin' => 'http://localhost:3000', 'Referer' => 'http://localhost:3000/']);
    })
    ->in('Feature');

uses(TestCase::class)->in('Unit');
