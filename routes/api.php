<?php

use App\Http\Controllers\Api\V1\Auth\RegisterController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('register', RegisterController::class)->middleware('throttle:register');

    Route::middleware('auth:sanctum')->group(function () {
        //
    });
});
