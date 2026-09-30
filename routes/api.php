<?php

use App\Enums\MealSlot;
use App\Http\Controllers\Api\V1\Auth\AccountController;
use App\Http\Controllers\Api\V1\Auth\MeController;
use App\Http\Controllers\Api\V1\Auth\PasswordController;
use App\Http\Controllers\Api\V1\Auth\PasswordResetController;
use App\Http\Controllers\Api\V1\Auth\RegisterController;
use App\Http\Controllers\Api\V1\Auth\SessionController;
use App\Http\Controllers\Api\V1\CatalogController;
use App\Http\Controllers\Api\V1\ConversationController;
use App\Http\Controllers\Api\V1\DayController;
use App\Http\Controllers\Api\V1\MessageActionController;
use App\Http\Controllers\Api\V1\MessageController;
use App\Http\Controllers\Api\V1\NutriController;
use App\Http\Controllers\Api\V1\OnboardingController;
use App\Http\Controllers\Api\V1\PlanController;
use App\Http\Controllers\Api\V1\PreferencesController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\WeighInController;
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
        Route::post('onboarding/complete', [OnboardingController::class, 'complete']);
        Route::get('plans/preview-targets', [PlanController::class, 'previewTargets']);
        Route::get('plans/active', [PlanController::class, 'active']);
        Route::get('plans/{plan}', [PlanController::class, 'show'])->whereNumber('plan');
        Route::post('plans', [PlanController::class, 'store'])->middleware('throttle:plans');
        Route::patch('profile/steps/{step}', [ProfileController::class, 'updateStep'])
            ->whereIn('step', ['objetivo', 'dados', 'atividade', 'preferencias', 'restricoes', 'rotina']);

        Route::middleware('onboarded')->group(function () {
            Route::get('profile', [ProfileController::class, 'show']);
            Route::put('profile/preferences', PreferencesController::class);
            Route::get('days/{date}', [DayController::class, 'show']);
            Route::patch('days/{date}/meals/{slot}', [DayController::class, 'toggleMeal'])
                ->whereIn('slot', array_map(fn (MealSlot $slot) => $slot->value, MealSlot::cases()));
            Route::get('days/{date}/items/{item}/substitutions', [DayController::class, 'substitutions'])->whereNumber('item');
            Route::post('days/{date}/items/{item}/swap', [DayController::class, 'swap'])->whereNumber('item');
            Route::post('days/{date}/undo', [DayController::class, 'undo']);
            Route::get('weigh-ins', [WeighInController::class, 'index']);
            Route::post('weigh-ins', [WeighInController::class, 'store']);
            Route::get('nutri/context', [NutriController::class, 'context']);
            Route::get('nutri/suggestions', [NutriController::class, 'suggestions']);
            Route::get('conversations', [ConversationController::class, 'index']);
            Route::post('conversations', [ConversationController::class, 'store']);
            Route::get('conversations/{conversation}', [ConversationController::class, 'show'])->whereNumber('conversation');
            Route::delete('conversations/{conversation}', [ConversationController::class, 'destroy'])->whereNumber('conversation');
            Route::get('conversations/{conversation}/messages', [MessageController::class, 'index'])->whereNumber('conversation');
            Route::post('conversations/{conversation}/messages', [MessageController::class, 'store'])
                ->whereNumber('conversation')->middleware('throttle:nutri');
            Route::post('messages/{message}/actions/{index}', [MessageActionController::class, 'store'])
                ->whereNumber('message')->whereNumber('index');
        });
    });
});
