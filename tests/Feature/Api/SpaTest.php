<?php

it('entrega o cookie CSRF para o front', function () {
    $this->get('/sanctum/csrf-cookie')
        ->assertNoContent()
        ->assertCookie('XSRF-TOKEN');
});

it('libera CORS com credenciais só para o front', function () {
    $this->getJson('/api/v1/me')
        ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:3000')
        ->assertHeader('Access-Control-Allow-Credentials', 'true');

    // Com uma só origem liberada, o CORS responde sempre com ela; o navegador bloqueia qualquer outra.
    $origin = $this->withHeader('Origin', 'https://site-malicioso.test')
        ->getJson('/api/v1/me')
        ->headers->get('Access-Control-Allow-Origin');

    expect($origin)->not->toBe('https://site-malicioso.test');
});
