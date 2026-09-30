<?php

use App\Models\User;

it('nada da conversa de outra pessoa existe para quem pergunta (RN43, CA10)', function (string $metodo, Closure $url) {
    seedCatalog();
    $dona = User::factory()->onboarded()->create();
    $conversa = conversaCom($dona, 'Dela', 'r', '2026-09-27 10:00');
    $mensagem = $conversa->messages()->where('role', 'assistant')->firstOrFail();
    login(User::factory()->onboarded()->create());

    $this->json($metodo, $url($conversa, $mensagem))->assertNotFound();
})->with([
    'abrir' => ['GET', fn ($c) => "/api/v1/conversations/{$c->id}"],
    'apagar' => ['DELETE', fn ($c) => "/api/v1/conversations/{$c->id}"],
    'mensagens' => ['GET', fn ($c) => "/api/v1/conversations/{$c->id}/messages"],
    'perguntar' => ['POST', fn ($c) => "/api/v1/conversations/{$c->id}/messages"],
    'ação' => ['POST', fn ($c, $m) => "/api/v1/messages/{$m->id}/actions/0"],
]);
