<?php

use App\Enums\ErrorCode;

it('tem status HTTP de erro e mensagem em português para todo código', function (ErrorCode $code) {
    expect($code->status())->toBeGreaterThanOrEqual(400)->toBeLessThan(600)
        ->and($code->message())->not->toBeEmpty();
})->with(fn () => ErrorCode::cases());

it('usa os status da tabela de códigos', function () {
    expect(ErrorCode::Unauthenticated->status())->toBe(401)
        ->and(ErrorCode::NotFound->status())->toBe(404)
        ->and(ErrorCode::NoActivePlan->status())->toBe(409)
        ->and(ErrorCode::InvalidCredentials->status())->toBe(422)
        ->and(ErrorCode::TooManyRequests->status())->toBe(429)
        ->and(ErrorCode::ServerError->status())->toBe(500)
        ->and(ErrorCode::AiUnavailable->status())->toBe(503);
});
