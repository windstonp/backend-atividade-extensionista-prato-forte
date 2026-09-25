<?php

use App\Models\Profile;
use App\Models\User;
use App\Models\UserSetting;
use Illuminate\Support\Facades\DB;

it('grava o e-mail em minúsculas e sem espaços (RN01)', function () {
    $user = User::factory()->create(['email' => '  Camila.Reus@Gmail.COM ']);

    expect($user->fresh()->email)->toBe('camila.reus@gmail.com');
});

it('nasce com perfil vazio e configurações padrão', function () {
    $user = User::factory()->create();

    expect($user->profile()->count())->toBe(1)
        ->and($user->profile->completed_steps)->toBe([])
        ->and($user->profile->isOnboarded())->toBeFalse()
        ->and($user->settings->unit_system)->toBe('metric')
        ->and($user->settings->notify_meal_reminders)->toBeTrue()
        ->and($user->settings->notify_tips)->toBeFalse();
});

it('cria o perfil completo com onboarded()', function () {
    $user = User::factory()->onboarded()->create();

    expect(Profile::where('user_id', $user->id)->count())->toBe(1)
        ->and($user->profile->isOnboarded())->toBeTrue();
});

it('apaga perfil e configurações junto com o usuário (RN06)', function () {
    $user = User::factory()->create();

    $user->delete();

    expect(Profile::count())->toBe(0)
        ->and(UserSetting::count())->toBe(0);
});

it('não expõe senha nem token ao serializar', function () {
    expect(User::factory()->create()->toArray())->not->toHaveKeys(['password', 'remember_token']);
});

it('encerra as sessões do usuário, podendo poupar a atual', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    fakeSession($user, 'celular');
    fakeSession($user, 'notebook');
    fakeSession($other, 'de-outra-pessoa');

    $user->endSessions(except: 'notebook');

    expect(DB::table('sessions')->pluck('id')->sort()->values()->all())->toBe(['de-outra-pessoa', 'notebook']);
});
