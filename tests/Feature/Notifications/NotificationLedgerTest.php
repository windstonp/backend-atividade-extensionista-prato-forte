<?php

use App\Models\User;
use App\Services\Notifications\NotificationLedger;
use Carbon\CarbonImmutable;

it('reserva uma vez só por referência', function () {
    $user = User::factory()->create();
    $ledger = app(NotificationLedger::class);

    expect($ledger->claim($user, 'meal_reminder', '2026-09-28:almoco'))->toBeTrue()
        ->and($ledger->claim($user, 'meal_reminder', '2026-09-28:almoco'))->toBeFalse()
        ->and($ledger->claim($user, 'meal_reminder', '2026-09-28:jantar'))->toBeTrue()
        ->and($ledger->claim(User::factory()->create(), 'meal_reminder', '2026-09-28:almoco'))->toBeTrue();
});

it('conta envios desde uma data e lembra a regra da dica', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-29 18:00'));
    $user = User::factory()->create();
    $ledger = app(NotificationLedger::class);
    $ledger->claim($user, 'tip', '2026-W40:2', 'sem-pesagem');

    expect($ledger->countSince($user, 'tip', CarbonImmutable::parse('2026-09-28')))->toBe(1)
        ->and($ledger->ruleSentSince($user, 'sem-pesagem', CarbonImmutable::parse('2026-09-15')))->toBeTrue()
        ->and($ledger->ruleSentSince($user, 'proteina', CarbonImmutable::parse('2026-09-15')))->toBeFalse();
});
