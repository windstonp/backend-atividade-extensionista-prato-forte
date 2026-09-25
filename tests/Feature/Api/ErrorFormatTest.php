<?php

use App\Enums\ErrorCode;
use App\Exceptions\DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::prefix('api/v1/_teste')->group(function () {
        Route::get('dominio', fn () => throw new DomainException(ErrorCode::NoActivePlan, ['plan_status' => 'generating', 'plan_id' => 42]));
        Route::get('quebra', fn () => throw new RuntimeException('segredo do banco'));
        Route::post('valida', fn (Request $request) => $request->validate(['altura' => 'required|integer']));
        Route::get('privado', fn () => 'ok')->middleware('auth:sanctum');
        Route::get('limitado', fn () => 'ok')->middleware('throttle:1,1');
    });
});

it('responde 404 NOT_FOUND para rota inexistente', function () {
    $this->getJson('/api/v1/nao-existe')
        ->assertNotFound()
        ->assertExactJson(['message' => 'Não encontramos o que você procurou.', 'code' => 'NOT_FOUND']);
});

it('renderiza exceção de domínio com status, código e detalhes', function () {
    $this->getJson('/api/v1/_teste/dominio')
        ->assertStatus(409)
        ->assertExactJson([
            'message' => 'Seu plano ainda não está pronto.',
            'code' => 'NO_ACTIVE_PLAN',
            'details' => ['plan_status' => 'generating', 'plan_id' => 42],
        ]);
});

it('renderiza validação com mensagem geral e erros por campo em português', function () {
    $this->postJson('/api/v1/_teste/valida', [])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Confira os campos destacados.')
        ->assertJsonPath('code', 'VALIDATION_ERROR')
        ->assertJsonPath('errors.altura.0', fn (string $message) => str_contains($message, 'obrigatório'));
});

it('responde JSON mesmo sem Accept: application/json', function () {
    $this->post('/api/v1/_teste/valida', [])
        ->assertUnprocessable()
        ->assertHeader('Content-Type', 'application/json')
        ->assertJsonPath('code', 'VALIDATION_ERROR');

    $this->get('/api/v1/_teste/privado')
        ->assertUnauthorized()
        ->assertJsonPath('code', 'UNAUTHENTICATED');
});

it('responde 401 UNAUTHENTICATED sem sessão', function () {
    $this->getJson('/api/v1/_teste/privado')
        ->assertUnauthorized()
        ->assertExactJson(['message' => 'Sua sessão expirou. Entre de novo.', 'code' => 'UNAUTHENTICATED']);
});

it('responde 429 com o tempo de espera', function () {
    $this->getJson('/api/v1/_teste/limitado')->assertOk();

    $this->getJson('/api/v1/_teste/limitado')
        ->assertTooManyRequests()
        ->assertJsonPath('code', 'TOO_MANY_REQUESTS')
        ->assertJsonPath('details.retry_after', fn (int $seconds) => $seconds > 0 && $seconds <= 60)
        ->assertHeader('Retry-After');
});

it('não expõe a mensagem da exceção em erro 500', function () {
    config(['app.debug' => false]);

    $this->getJson('/api/v1/_teste/quebra')
        ->assertStatus(500)
        ->assertExactJson(['message' => 'Algo deu errado do nosso lado. Tente de novo.', 'code' => 'SERVER_ERROR']);
});
