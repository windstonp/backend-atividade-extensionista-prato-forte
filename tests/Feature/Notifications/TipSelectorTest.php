<?php

use App\Models\User;
use App\Services\Notifications\NotificationLedger;
use App\Services\Notifications\TipSelector;
use Carbon\CarbonImmutable;

beforeEach(function () {
    seedCatalog();
    $this->travelTo(CarbonImmutable::parse('2026-09-21 07:00', 'America/Sao_Paulo'));
    $this->user = login(User::factory()->onboarded()->create()); // meta 62 kg
    planoPronto($this->user);
    $this->user->weighIns()->create(['date' => '2026-09-21', 'weight_kg' => 58.4]);
});

function diaCom(string $data, array $slots): void
{
    test()->travelTo(CarbonImmutable::parse("{$data} 21:00", 'America/Sao_Paulo'));
    test()->getJson('/api/v1/days/today')->assertOk();
    foreach ($slots as $slot) {
        registrarRefeicao($slot);
    }
}

$todas = ['cafe', 'lanche', 'almoco', 'pre-treino', 'jantar'];

it('proteína abaixo de 90% nos últimos 7 dias vem primeiro', function () {
    diaCom('2026-09-22', ['cafe']);

    expect(app(TipSelector::class)->pick($this->user->fresh(), CarbonImmutable::parse('2026-09-25')))
        ->toBe(['rule' => 'proteina', 'text' => 'Faltou proteína nesta semana. Um ovo a mais no café já ajuda.', 'url' => '/nutri']);
});

it('mesma refeição sem marcar 3 vezes', function () use ($todas) {
    foreach (['2026-09-21', '2026-09-22', '2026-09-23'] as $data) {
        diaCom($data, array_values(array_diff($todas, ['lanche'])));
    }
    app(NotificationLedger::class)->claim($this->user, 'tip', '2026-W39:5', 'proteina'); // sem o lanche a proteína pode ficar < 90%: tira a regra 1 do caminho

    expect(app(TipSelector::class)->pick($this->user->fresh(), CarbonImmutable::parse('2026-09-25')))
        ->toMatchArray(['rule' => 'refeicao-esquecida', 'url' => '/nutri'])
        ->and(app(TipSelector::class)->pick($this->user->fresh(), CarbonImmutable::parse('2026-09-25'))['text'])->toMatch('/^O lanche( da manhã)? ficou de fora 3 vezes esta semana\. Quer pedir ao Nutri uma opção mais prática\?$/');
});

it('meta definida e nenhuma pesagem há 7 dias', function () use ($todas) {
    diaCom('2026-09-28', $todas);

    expect(app(TipSelector::class)->pick($this->user->fresh(), CarbonImmutable::parse('2026-09-29'))['rule'] ?? null)->toBe('sem-pesagem');
});

it('sequência de 5 dias completos', function () use ($todas) {
    foreach (['2026-09-21', '2026-09-22', '2026-09-23', '2026-09-24', '2026-09-25'] as $data) {
        diaCom($data, $todas);
    }

    expect(app(TipSelector::class)->pick($this->user->fresh(), CarbonImmutable::parse('2026-09-26')))
        ->toBe(['rule' => 'sequencia', 'text' => '5 dias seguidos com tudo feito. Segue assim!', 'url' => '/evolucao']);
});

it('regra enviada nos últimos 14 dias é pulada; nenhuma aplicável ⇒ null', function () {
    diaCom('2026-09-22', ['cafe']);
    app(NotificationLedger::class)->claim($this->user, 'tip', '2026-W39:2', 'proteina');

    expect(app(TipSelector::class)->pick($this->user->fresh(), CarbonImmutable::parse('2026-09-25')))->toBeNull();
});
