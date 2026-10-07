<?php

use App\Models\DayMeal;
use App\Models\MealEntry;
use App\Models\User;
use Carbon\CarbonImmutable;

beforeEach(function () {
    seedCatalog();
    $this->travelTo(CarbonImmutable::parse('2026-10-07 13:00', 'America/Sao_Paulo'));
    $this->user = login(User::factory()->onboarded()->create());
    planoPronto($this->user);
    $this->getJson('/api/v1/days/today');
});

it('refeição feita antes da mudança ganha registros iguais à sugestão; o consumido não muda (CA44)', function () {
    $almoco = DayMeal::where('slot', 'almoco')->sole();
    $almoco->update(['done_at' => now()]);
    $esperado = $almoco->load('items.food')->target();

    $migration = require database_path('migrations/2026_10_07_000200_convert_done_meals_to_entries.php');
    $migration->up();
    $migration->up(); // idempotente

    expect(MealEntry::count())->toBe($almoco->items->count())
        ->and($almoco->fresh()->load('entries')->consumed())->toBe($esperado)
        ->and(MealEntry::whereNull('suggestion_item_id')->count())->toBe(0);
});
