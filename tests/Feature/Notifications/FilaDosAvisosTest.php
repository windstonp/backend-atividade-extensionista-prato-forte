<?php

use App\Models\User;
use App\Notifications\MealReminder;
use App\Notifications\NutriTip;
use App\Notifications\WeeklySummary;
use App\Services\Days\DayMaterializer;
use App\Services\Notifications\TipSelector;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

it('os avisos vão para a fila "notifications" e somem se a conta não existir mais', function ($aviso) {
    Queue::fake();
    $user = User::factory()->create();
    $user->updatePushSubscription('https://fcm.googleapis.com/fcm/send/abc', 'BPublica', 'segredo', 'aes128gcm');

    $user->notify($aviso);

    Queue::assertPushedOn('notifications', SendQueuedNotifications::class);
    expect($aviso->deleteWhenMissingModels)->toBeTrue();
})->with([
    'lembrete' => [fn () => new MealReminder('almoco', 'Almoço', '12:30', 'Arroz')],
    'resumo' => [fn () => new WeeklySummary('1 de 7 dias completos')],
    'dica' => [fn () => new NutriTip('Segue assim!', '/evolucao')],
]);

it('a sequência das dicas não varre mais de 400 dias', function () {
    seedCatalog();
    $this->travelTo(CarbonImmutable::parse('2026-09-28 18:00', 'America/Sao_Paulo'));
    $user = User::factory()->onboarded()->create();
    planoPronto($user);
    $user->weighIns()->create(['date' => today()->toDateString(), 'weight_kg' => 58.4]); // as regras de antes não respondem: chega na sequência
    DB::enableQueryLog();
    app(TipSelector::class)->pick($user, CarbonImmutable::today());

    $consulta = collect(DB::getQueryLog())->first(fn ($q) => str_contains($q['query'], 'sum(done_at is not null)'));
    expect($consulta['query'])->toContain('`date` >=');
});

it('quem dorme depois da meia-noite recebe o lembrete das 23:15 (jantar 23:30)', function () {
    seedCatalog();
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2026-09-28 07:00', 'America/Sao_Paulo'));
    $user = User::factory()->onboarded()->create();
    $user->profile->update(['wake_time' => '08:00', 'sleep_time' => '01:00']);
    $user->updatePushSubscription('https://fcm.googleapis.com/fcm/send/abc', 'BPublica', 'segredo', 'aes128gcm');
    planoPronto($user);
    $this->getJson('/api/v1/days/today'); // sem sessão: materializa pelo comando
    $jantar = $user->dayMeals()->whereDate('date', today())->where('slot', 'jantar')->first()
        ?? collect(app(DayMaterializer::class)->meals($user, CarbonImmutable::today()))->firstWhere('slot', 'jantar');
    $jantar->update(['time' => '23:30:00']);

    $this->travelTo(CarbonImmutable::parse('2026-09-28 23:15', 'America/Sao_Paulo'));
    $this->artisan('notifications:meal-reminders')->assertSuccessful();

    Notification::assertSentTo($user, MealReminder::class, fn (MealReminder $a) => $a->slot === 'jantar');
});
