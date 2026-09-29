<?php

use App\Http\Controllers\Api\V1\Auth\AccountController;
use App\Http\Controllers\Api\V1\Auth\MeController;
use App\Http\Controllers\Api\V1\Auth\PasswordController;
use App\Http\Controllers\Api\V1\Auth\PasswordResetController;
use App\Http\Controllers\Api\V1\Auth\RegisterController;
use App\Http\Controllers\Api\V1\Auth\SessionController;
use App\Http\Controllers\Api\V1\CatalogController;
use App\Http\Controllers\Api\V1\OnboardingController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Middleware\EnsureSpaSession;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('register', RegisterController::class)->middleware(['throttle:register', EnsureSpaSession::class]);
    Route::post('login', [SessionController::class, 'store'])->middleware(['throttle:login', EnsureSpaSession::class]);
    Route::post('password/forgot', [PasswordResetController::class, 'forgot'])->middleware('throttle:password');
    Route::post('password/reset', [PasswordResetController::class, 'reset'])->middleware('throttle:password');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [SessionController::class, 'destroy']);
        Route::get('me', MeController::class);
        Route::put('me/password', PasswordController::class);
        Route::delete('me', [AccountController::class, 'destroy']);

        Route::get('catalog/onboarding', CatalogController::class);
        Route::get('onboarding', [OnboardingController::class, 'show']);
        Route::patch('profile/steps/{step}', [ProfileController::class, 'updateStep'])
            ->whereIn('step', ['objetivo', 'dados', 'atividade', 'preferencias', 'restricoes', 'rotina']);
    });
});
