<?php

use App\Models\User;
use NotificationChannels\WebPush\PushSubscription;

beforeEach(fn () => $this->user = login(User::factory()->create()));

function inscricao(string $endpoint = 'https://fcm.googleapis.com/fcm/send/abc'): array
{
    return ['endpoint' => $endpoint, 'keys' => ['p256dh' => 'BPublica', 'auth' => 'segredo'], 'content_encoding' => 'aes128gcm'];
}

it('guarda a inscrição deste navegador (201) e o GET /settings conta', function () {
    $this->postJson('/api/v1/push-subscriptions', inscricao())->assertCreated()->assertExactJson(['data' => ['subscribed' => true]]);

    expect($this->getJson('/api/v1/settings')->json('data.push.subscriptions'))->toBe(1)
        ->and(PushSubscription::sole()->only(['endpoint', 'public_key', 'auth_token']))
        ->toBe(['endpoint' => 'https://fcm.googleapis.com/fcm/send/abc', 'public_key' => 'BPublica', 'auth_token' => 'segredo'])
        ->and(PushSubscription::sole()->content_encoding->value)->toBe('aes128gcm'); // o pacote guarda como enum
});

it('mesmo endpoint de novo atualiza, sem duplicar', function () {
    $this->postJson('/api/v1/push-subscriptions', inscricao())->assertCreated();
    $this->postJson('/api/v1/push-subscriptions', [...inscricao(), 'keys' => ['p256dh' => 'BNova', 'auth' => 'novo']])->assertCreated();

    expect(PushSubscription::count())->toBe(1)->and(PushSubscription::sole()->public_key)->toBe('BNova');
});

it('mesmo navegador, outra conta: a inscrição troca de dono', function () {
    $this->postJson('/api/v1/push-subscriptions', inscricao())->assertCreated();
    $outra = login(User::factory()->create());
    $this->postJson('/api/v1/push-subscriptions', inscricao())->assertCreated();

    expect(PushSubscription::sole()->subscribable_id)->toBe($outra->id)
        ->and($this->user->pushSubscriptions()->count())->toBe(0);
});

it('DELETE apaga só a deste navegador e é idempotente (CA07)', function () {
    $this->postJson('/api/v1/push-subscriptions', inscricao())->assertCreated();
    $this->postJson('/api/v1/push-subscriptions', inscricao('https://fcm.googleapis.com/fcm/send/outro'))->assertCreated();

    $this->deleteJson('/api/v1/push-subscriptions', ['endpoint' => 'https://fcm.googleapis.com/fcm/send/abc'])->assertNoContent();
    $this->deleteJson('/api/v1/push-subscriptions', ['endpoint' => 'https://fcm.googleapis.com/fcm/send/abc'])->assertNoContent();

    expect(PushSubscription::pluck('endpoint')->all())->toBe(['https://fcm.googleapis.com/fcm/send/outro']);
});

it('valida o corpo', function (array $corpo, string $campo) {
    $this->postJson('/api/v1/push-subscriptions', $corpo)->assertUnprocessable()->assertJsonValidationErrors($campo);
})->with([
    'http sem s' => [inscricao('http://push.example/abc'), 'endpoint'],
    'sem chaves' => [['endpoint' => 'https://push.example/abc'], 'keys.p256dh'],
    'codificação estranha' => [[...inscricao(), 'content_encoding' => 'gzip'], 'content_encoding'],
]);
