<?php

use Illuminate\Routing\Route as RouteDefinition;
use Illuminate\Support\Facades\Route;

it('responde 401 em toda rota protegida quando não há sessão (RN07)', function () {
    // Valores válidos para os parâmetros restritos das rotas dos próximos planos.
    $params = ['date' => 'today', 'slot' => 'almoco', 'step' => 'objetivo'];

    $protected = collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RouteDefinition $route) => str_starts_with($route->uri(), 'api/v1/')
            && in_array('auth:sanctum', $route->gatherMiddleware(), true));

    expect($protected)->not->toBeEmpty();

    foreach ($protected as $route) {
        $method = collect($route->methods())->reject(fn (string $m) => $m === 'HEAD')->first();
        $uri = preg_replace_callback('/\{(\w+)\??\}/', fn (array $m) => $params[$m[1]] ?? '1', $route->uri());

        expect($this->json($method, '/'.$uri)->status())->toBe(401, "{$method} /{$uri}");
    }
});
