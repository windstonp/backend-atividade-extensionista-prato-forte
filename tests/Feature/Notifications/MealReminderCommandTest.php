<?php

use App\Models\User;
use App\Notifications\MealReminder;
use App\Services\Days\DayMaterializer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    seedCatalog();
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2026-09-28 07:00', 'America/Sao_Paulo')); // segunda
    $this->user = User::factory()->onboarded()->create(); // acorda 06:20, dorme 23:00
    $this->user->updatePushSubscription('https://fcm.googleapis.com/fcm/send/abc', 'BPublica', 'segredo', 'aes128gcm');
    planoPronto($this->user);
    $this->almoco = collect(app(DayMaterializer::class)->meals($this->user, CarbonImmutable::today()))->firstWhere('slot', 'almoco');
});

function rodarAs(string $hora): void
{
    test()->travelTo(CarbonImmutable::parse("2026-09-28 {$hora}", 'America/Sao_Paulo'));
    test()->artisan('notifications:meal-reminders')->assertSuccessful();
}

it('15 minutos antes do almoço não feito, avisa uma vez (CA03)', function () {
    $quinzeAntes = CarbonImmutable::parse('2026-09-28 '.substr($this->almoco->time, 0, 5), 'America/Sao_Paulo')->subMinutes(15)->format('H:i');

    rodarAs($quinzeAntes);
    rodarAs($quinzeAntes); // cron repetido no mesmo minuto
    rodarAs(CarbonImmutable::parse("2026-09-28 {$quinzeAntes}")->addMinute()->format('H:i'));

    Notification::assertSentToTimes($this->user, MealReminder::class, 1);
    Notification::assertSentTo($this->user, MealReminder::class, function (MealReminder $aviso) {
        $push = $aviso->toWebPush($this->user, $aviso)->toArray();

        return $push['title'] === 'Almoço às '.substr($this->almoco->time, 0, 5)
            && $push['data']['url'] === '/dieta/almoco'
            && $push['tag'] === 'refeicao-almoco'
            && $push['body'] !== '';
    });
});

it('refeição já feita não avisa (CA04)', function () {
    $this->almoco->update(['done_at' => now()]);
    rodarAs(CarbonImmutable::parse('2026-09-28 '.substr($this->almoco->time, 0, 5))->subMinutes(15)->format('H:i'));

    Notification::assertNothingSent();
});

it('não avisa sem inscrição, com aviso desligado, sem onboarding ou fora da janela', function (Closure $preparar) {
    $preparar($this->user);
    rodarAs(CarbonImmutable::parse('2026-09-28 '.substr($this->almoco->time, 0, 5))->subMinutes(15)->format('H:i'));

    Notification::assertNothingSent();
})->with([
    'sem inscrição' => [fn (User $u) => $u->pushSubscriptions()->delete()],
    'desligado' => [fn (User $u) => $u->settings()->update(['notify_meal_reminders' => false])],
    'sem onboarding' => [fn (User $u) => $u->profile()->update(['onboarding_completed_at' => null])],
    'dorme cedo' => [fn (User $u) => $u->profile()->update(['wake_time' => '06:00', 'sleep_time' => '11:00'])],
]);

it('sem plano ativo: pula sem erro', function () {
    $this->user->mealPlans()->update(['is_active' => false]);
    $this->user->dayMeals()->delete();

    rodarAs('12:15');

    Notification::assertNothingSent();
});

it('dia ainda não materializado: usa a prévia do plano e não grava o dia', function () {
    $this->user->dayMeals()->delete();
    rodarAs(CarbonImmutable::parse('2026-09-28 '.substr($this->almoco->time, 0, 5))->subMinutes(15)->format('H:i'));

    Notification::assertSentToTimes($this->user, MealReminder::class, 1);
    expect($this->user->dayMeals()->count())->toBe(0);
});
