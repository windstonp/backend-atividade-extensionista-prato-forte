<?php

use App\Models\AiRequest;
use App\Models\Food;
use App\Models\PantryItem;
use App\Models\Restriction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;

/**
 * Tabelas com dados do usuário e a coluna que aponta para ele (RN06).
 * Plano novo criou tabela com user_id? Acrescente aqui.
 */
dataset('tabelas do usuário', [
    'users' => ['users', 'id'],
    'profiles' => ['profiles', 'user_id'],
    'user_settings' => ['user_settings', 'user_id'],
    'sessions' => ['sessions', 'user_id'],
    'weigh_ins' => ['weigh_ins', 'user_id'],
    'restriction_user' => ['restriction_user', 'user_id'],
    'pantry_item_user' => ['pantry_item_user', 'user_id'],
    'disliked_food_user' => ['disliked_food_user', 'user_id'],
    'ai_requests' => ['ai_requests', 'user_id'],
]);

it('não deixa nenhuma linha do usuário para trás (CA08)', function (string $table, string $column) {
    $user = login(User::factory()->onboarded()->create(['email' => 'camila@exemplo.com']));
    fakeSession($user, 'outro-celular');
    Password::createToken($user);
    seedCatalog();
    $user->restrictions()->attach(Restriction::first());
    $user->pantryItems()->attach(PantryItem::first());
    $user->dislikedFoods()->attach(Food::where('common_dislike', true)->first());
    $user->weighIns()->create(['date' => today(), 'weight_kg' => 58.4]);
    AiRequest::create(['user_id' => $user->id, 'purpose' => 'plan', 'model' => 'fake', 'duration_ms' => 1, 'status' => 'ok']);

    $this->deleteJson('/api/v1/me', ['password' => 'senha1234'])->assertNoContent();

    expect(DB::table($table)->where($column, $user->id)->exists())->toBeFalse("sobrou linha em {$table}")
        ->and(DB::table('password_reset_tokens')->where('email', 'camila@exemplo.com')->exists())->toBeFalse();
    $this->assertGuest('web');
})->with('tabelas do usuário');

it('libera o e-mail para um novo cadastro (CA08)', function () {
    login(User::factory()->create(['email' => 'camila.reus@gmail.com']));
    $this->deleteJson('/api/v1/me', ['password' => 'senha1234'])->assertNoContent();
    forgetServerState();

    $this->postJson('/api/v1/register', registerPayload())->assertCreated();
});

it('exige a senha correta e não apaga nada', function () {
    $user = login();

    $this->deleteJson('/api/v1/me', ['password' => 'errada123'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.password.0', 'A senha não confere.');

    expect(User::whereKey($user->id)->exists())->toBeTrue();
});
