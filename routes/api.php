<?php

use App\Http\Controllers\Api\V1\Auth\AccountController;
use App\Http\Controllers\Api\V1\Auth\MeController;
use App\Http\Controllers\Api\V1\Auth\PasswordController;
use App\Http\Controllers\Api\V1\Auth\PasswordResetController;
use App\Http\Controllers\Api\V1\Auth\RegisterController;
use App\Http\Controllers\Api\V1\Auth\SessionController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('register', RegisterController::class)->middleware('throttle:register');
    Route::post('login', [SessionController::class, 'store'])->middleware('throttle:login');
    Route::post('password/forgot', [PasswordResetController::class, 'forgot'])->middleware('throttle:password');
    Route::post('password/reset', [PasswordResetController::class, 'reset'])->middleware('throttle:password');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [SessionController::class, 'destroy']);
        Route::get('me', MeController::class);
        Route::put('me/password', PasswordController::class);
        Route::delete('me', [AccountController::class, 'destroy']);
    });
});
