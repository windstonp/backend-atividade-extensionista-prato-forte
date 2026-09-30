<?php

use App\Models\User;
use App\Notifications\NutriTip;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    seedCatalog();
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2026-09-21 07:00', 'America/Sao_Paulo'));
    $this->user = User::factory()->onboarded()->create();
    $this->user->settings()->update(['notify_tips' => true]);
    $this->user->updatePushSubscription('https://fcm.googleapis.com/fcm/send/abc', 'BPublica', 'segredo', 'aes128gcm');
    planoPronto($this->user); // sem pesagem: a regra "sem-pesagem" vale a partir de 28/09
    $this->user->weighIns()->create(['date' => '2026-09-14', 'weight_kg' => 58.4]);
});

function dicasAs(string $quando): void
{
    test()->travelTo(CarbonImmutable::parse($quando, 'America/Sao_Paulo'));
    test()->artisan('notifications:tips')->assertSuccessful();
}

it('no máximo 2 dicas na semana e nunca a mesma regra em 14 dias (CA05)', function () {
    dicasAs('2026-09-22 18:00'); // terça: sem-pesagem
    dicasAs('2026-09-22 18:00'); // repetido: nada
    dicasAs('2026-09-25 18:00'); // sexta: sem-pesagem já saiu; outra regra? nenhuma ⇒ nada

    Notification::assertSentToTimes($this->user, NutriTip::class, 1);
    Notification::assertSentTo($this->user, NutriTip::class, fn (NutriTip $dica) => $dica->toWebPush($this->user, $dica)->toArray()['data']['url'] === '/evolucao/peso');
});

it('dicas desligadas (padrão) não enviam', function () {
    $this->user->settings()->update(['notify_tips' => false]);
    dicasAs('2026-09-22 18:00');

    Notification::assertNothingSent();
});

it('com duas dicas já na semana, a sexta não envia', function () {
    DB::table('sent_notifications')->insert([
        ['user_id' => $this->user->id, 'type' => 'tip', 'reference' => 'a', 'rule' => 'x', 'sent_at' => '2026-09-21 18:00:00'],
        ['user_id' => $this->user->id, 'type' => 'tip', 'reference' => 'b', 'rule' => 'y', 'sent_at' => '2026-09-22 18:00:00'],
    ]);
    dicasAs('2026-09-25 18:00');

    Notification::assertNothingSent();
});
