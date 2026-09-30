<?php

use App\Models\MealPlan;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;

function planoDeTeste(User $user, array $extra = []): MealPlan
{
    return $user->mealPlans()->create(array_merge([
        'status' => 'ready', 'target_kcal' => 2250, 'target_protein_g' => 115, 'target_carbs_g' => 305, 'target_fat_g' => 65, 'inputs' => [],
    ], $extra));
}

it('permite um só plano ativo por usuário (coluna gerada única)', function () {
    $user = User::factory()->create();
    planoDeTeste($user, ['is_active' => true]);

    expect(fn () => planoDeTeste($user, ['is_active' => true]))->toThrow(UniqueConstraintViolationException::class);
});

it('deixa vários planos inativos e um ativo por usuário diferente', function () {
    $ana = User::factory()->create();
    $bia = User::factory()->create();
    planoDeTeste($ana);
    planoDeTeste($ana);
    planoDeTeste($ana, ['is_active' => true]);
    planoDeTeste($bia, ['is_active' => true]);

    expect($ana->activePlan()->count())->toBe(1)->and(MealPlan::count())->toBe(4);
});
