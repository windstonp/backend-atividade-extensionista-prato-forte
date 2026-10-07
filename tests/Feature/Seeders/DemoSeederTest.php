<?php

use App\Models\User;
use App\Services\Progress\ProgressService;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\Hash;

beforeEach(fn () => $this->travelTo(CarbonImmutable::parse('2026-10-05 09:00', 'America/Sao_Paulo')));

it('cria a Camila do mock com plano, pesagens, 28 dias de uso e duas conversas', function () {
    $this->seed(DemoSeeder::class);

    $camila = User::where('email', 'camila@demo.pratoforte.test')->sole();
    expect(Hash::check('demo1234', $camila->password))->toBeTrue()
        ->and($camila->profile->onboarding_completed_at)->not->toBeNull()
        ->and($camila->activePlan()->exists())->toBeTrue()
        ->and($camila->restrictions()->pluck('slug')->all())->toBe(['castanhas'])
        ->and($camila->dislikedFoods()->pluck('slug')->sort()->values()->all())->toBe(['figado-bovino', 'jilo'])
        ->and($camila->weighIns()->orderBy('date')->pluck('weight_kg')->all())->toBe([56.8, 57.0, 57.5, 57.6, 58.0, 58.4])
        ->and($camila->conversations()->count())->toBe(2)
        ->and($camila->conversations()->whereNotNull('summary')->count())->toBe(2);

    $progresso = app(ProgressService::class)->show($camila, '6w', CarbonImmutable::today());
    expect($progresso['adherence']['complete_days'])->toBe(21)->and($progresso['adherence']['streak'])->toBe(3)
        ->and($progresso['weight']['forecast'])->not->toBeNull();
});

it('cria o usuário parado na etapa atividade', function () {
    $this->seed(DemoSeeder::class);

    $novo = User::where('email', 'novo@demo.pratoforte.test')->sole();
    expect($novo->profile->onboarding_completed_at)->toBeNull()
        ->and($novo->profile->nextStep()?->value)->toBe('atividade');
});

it('o DatabaseSeeder só chama a demonstração fora de produção', function () {
    app()->detectEnvironment(fn () => 'production');
    $this->artisan('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true])->assertSuccessful(); // em produção o db:seed pede confirmação
    expect(User::where('email', 'camila@demo.pratoforte.test')->exists())->toBeFalse();

    app()->detectEnvironment(fn () => 'local');
    $this->artisan('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true])->assertSuccessful();
    expect(User::where('email', 'camila@demo.pratoforte.test')->exists())->toBeTrue();
});

it('o DemoSeeder se recusa a rodar em produção mesmo chamado direto', function () {
    app()->detectEnvironment(fn () => 'production');

    expect(fn () => app(DemoSeeder::class)->run())->toThrow(RuntimeException::class, 'DemoSeeder não roda em produção.')
        ->and(User::where('email', 'camila@demo.pratoforte.test')->exists())->toBeFalse();
});
