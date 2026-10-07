<?php

use App\Models\User;
use App\Notifications\WeeklySummary;
use App\Services\Notifications\WeeklySummaryBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    seedCatalog();
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2026-09-21 07:00', 'America/Sao_Paulo')); // segunda
    $this->user = User::factory()->onboarded()->create();
    $this->user->updatePushSubscription('https://fcm.googleapis.com/fcm/send/abc', 'BPublica', 'segredo', 'aes128gcm');
    planoPronto($this->user);
});

/** Marca refeições num dia da semana de 21 a 27/09. */
function feitasEm(string $data, array $slots): void
{
    test()->travelTo(CarbonImmutable::parse("{$data} 21:00", 'America/Sao_Paulo'));
    login(test()->user);
    test()->getJson('/api/v1/days/today')->assertOk();
    foreach ($slots as $slot) {
        registrarRefeicao($slot);
    }
}

it('monta "{n} de 7 dias completos · média de {p} g de proteína · peso +x kg"', function () {
    $todas = ['cafe', 'lanche', 'almoco', 'pre-treino', 'jantar'];
    feitasEm('2026-09-21', $todas);
    feitasEm('2026-09-22', ['cafe']);
    $this->user->weighIns()->create(['date' => '2026-09-14', 'weight_kg' => 58.0]);
    $this->user->weighIns()->create(['date' => '2026-09-26', 'weight_kg' => 58.4]);

    $texto = app(WeeklySummaryBuilder::class)->body($this->user->fresh(), CarbonImmutable::parse('2026-09-27'));

    expect($texto)->toMatch('/^1 de 7 dias completos · média de \d+ g de proteína · peso \+0,4 kg$/');
});

it('sem pesagem na semana, sem a parte do peso; sem refeição feita, sem resumo', function () {
    feitasEm('2026-09-23', ['cafe']);

    expect(app(WeeklySummaryBuilder::class)->body($this->user->fresh(), CarbonImmutable::parse('2026-09-27')))->toMatch('/^0 de 7 dias completos · média de \d+ g de proteína$/')
        ->and(app(WeeklySummaryBuilder::class)->body(User::factory()->onboarded()->create(), CarbonImmutable::parse('2026-09-27')))->toBeNull();
});

it('domingo 20:00 envia uma vez, com título e link da Evolução', function () {
    feitasEm('2026-09-23', ['cafe']);
    $this->travelTo(CarbonImmutable::parse('2026-09-27 20:00', 'America/Sao_Paulo'));

    $this->artisan('notifications:weekly-summary')->assertSuccessful();
    $this->artisan('notifications:weekly-summary')->assertSuccessful();

    Notification::assertSentToTimes($this->user, WeeklySummary::class, 1);
    Notification::assertSentTo($this->user, WeeklySummary::class, function (WeeklySummary $aviso) {
        $push = $aviso->toWebPush($this->user, $aviso)->toArray();

        return $push['title'] === 'Sua semana no Prato Forte' && $push['data']['url'] === '/evolucao' && $push['tag'] === 'resumo-semana';
    });
});

it('quem já dormiu às 20:00 não recebe', function () {
    feitasEm('2026-09-23', ['cafe']);
    $this->user->profile()->update(['wake_time' => '05:00', 'sleep_time' => '19:30']);
    $this->travelTo(CarbonImmutable::parse('2026-09-27 20:00', 'America/Sao_Paulo'));

    $this->artisan('notifications:weekly-summary')->assertSuccessful();

    Notification::assertNothingSent();
});
