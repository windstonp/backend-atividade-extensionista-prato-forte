<?php

use App\Enums\OnboardingStep;
use App\Models\Profile;

it('começa pelo objetivo', function () {
    expect((new Profile)->nextStep())->toBe(OnboardingStep::Objetivo);
});

it('aponta a primeira etapa ainda não salva, na ordem do fluxo', function () {
    $profile = new Profile(['completed_steps' => ['objetivo', 'dados', 'preferencias']]);

    expect($profile->nextStep())->toBe(OnboardingStep::Atividade)
        ->and($profile->isOnboarded())->toBeFalse();
});

it('volta ao resumo quando todas as etapas foram salvas mas não concluiu', function () {
    $steps = array_map(fn (OnboardingStep $step) => $step->value, OnboardingStep::cases());

    expect((new Profile(['completed_steps' => $steps]))->nextStep())->toBe(OnboardingStep::Resumo);
});

it('não tem próxima etapa depois de concluído', function () {
    $profile = new Profile(['onboarding_completed_at' => now()]);

    expect($profile->isOnboarded())->toBeTrue()
        ->and($profile->nextStep())->toBeNull();
});
