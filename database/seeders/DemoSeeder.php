<?php

namespace Database\Seeders;

use App\Ai\AiClient;
use App\Ai\FakeAiClient;
use App\Ai\LoggingAiClient;
use App\Models\Food;
use App\Models\PantryItem;
use App\Models\Restriction;
use App\Models\User;
use App\Services\Days\DayMaterializer;
use App\Services\Days\EntryService;
use App\Services\Nutrition\DayTotals;
use App\Services\Plans\PlanService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Demonstração na Zfit (modelo-de-dados.md): a Camila do mock com um mês de uso e um usuário
 * parado no onboarding. Sem IA de verdade. Senha: demo1234. Rode sobre banco limpo:
 * php artisan migrate:fresh --seed (em local/staging).
 */
class DemoSeeder extends Seeder
{
    /** Padrão de constância do mock, de hoje − 27 até ontem (hoje fica "hoje"). */
    private const CONSTANCIA = [
        'c', 'c', 'p', 'c', 'c', 'v', 'c',
        'c', 'p', 'c', 'c', 'c', 'c', 'v',
        'c', 'c', 'c', 'p', 'c', 'c', 'c',
        'c', 'c', 'v', 'c', 'c', 'c',
    ];

    public function run(): void
    {
        // Contas com senha fraca: nunca em produção, nem chamado direto (db:seed --class=DemoSeeder).
        if (! app()->environment(['local', 'staging', 'testing'])) {
            throw new \RuntimeException('DemoSeeder não roda em produção.');
        }
        $this->call(CatalogSeeder::class);
        config(['queue.default' => 'sync']);
        app()->instance(AiClient::class, new LoggingAiClient(app(FakeAiClient::class)));
        $hoje = CarbonImmutable::today();

        $camila = User::factory()->onboarded()->create(['name' => 'Camila Réus', 'email' => 'camila@demo.pratoforte.test', 'password' => 'demo1234', 'created_at' => $hoje->subDays(40)]);
        $camila->profile->update(['preferred_name' => 'Camila', 'start_weight_kg' => 56.8, 'onboarding_completed_at' => $hoje->subDays(35)]);
        $camila->restrictions()->sync([Restriction::where('slug', 'castanhas')->sole()->id]);
        $camila->pantryItems()->sync(PantryItem::whereIn('slug', ['ovos', 'frango', 'arroz-e-feijao', 'batata-doce', 'banana', 'aveia', 'iogurte'])->pluck('id'));
        $camila->dislikedFoods()->sync(Food::whereIn('slug', ['figado-bovino', 'jilo'])->pluck('id'));

        $this->travel(fn () => app(PlanService::class)->requestGeneration($camila), $hoje->subDays(29));

        foreach ([56.8, 57.0, 57.5, 57.6, 58.0, 58.4] as $i => $kg) {
            $camila->weighIns()->create(['date' => $hoje->subDays(35 - $i * 7)->toDateString(), 'weight_kg' => $kg]);
        }

        $plano = $camila->activePlan()->with('meals.items.food')->sole();
        $dias = app(DayMaterializer::class);
        foreach (self::CONSTANCIA as $i => $status) {
            $data = $hoje->subDays(27 - $i);
            if ($status === 'v' && $i % 2 === 1) {
                continue; // dia sem abrir o app: nem materializa
            }
            $refeicoes = $dias->build($camila->fresh(), $plano, $data, save: true);
            $feitas = match ($status) {
                'c' => $refeicoes,
                'p' => $refeicoes->take(2),
                default => collect(),
            };
            foreach ($feitas as $meal) {
                // D13: refeição feita = registros iguais à sugestão, no horário da refeição.
                $quando = $data->setTimeFromTimeString((string) $meal->time);
                foreach ($meal->items()->with('food')->get() as $j => $item) {
                    $meal->entries()->forceCreate([
                        'user_id' => $meal->user_id, 'food_id' => $item->food_id, 'suggestion_item_id' => $item->id,
                        'name' => $item->food->name, 'measure' => $item->food->measure, 'amount' => $item->grams,
                        'position' => $j + 1, 'created_at' => $quando, 'updated_at' => $quando,
                        ...DayTotals::item($item->food, (float) $item->grams),
                    ]);
                }
                $meal->update(['done_at' => $quando]);
            }
        }

        // Hoje: o café registrado com mais aveia que a sugestão — a régua da refeição passa da meta (registro livre, D13).
        $cafe = $dias->mealsForWriting($camila->fresh(), $hoje)->firstWhere('slot', 'cafe');
        if ($cafe !== null && $cafe->entries->isEmpty()) {
            $entradas = $cafe->items->map(fn ($item) => ['suggestion_item_id' => $item->id,
                'amount' => (float) $item->grams + (str_contains($item->food->slug, 'aveia') ? 60 : 0)])->values()->all();
            app(EntryService::class)->add($camila->fresh(), $hoje, 'cafe', $entradas);
        }

        $conversas = [
            ['Posso trocar o arroz por batata?', 'Pode. No almoço, 230 g de batata-doce entram no lugar dos 150 g de arroz.', 'Camila perguntou sobre trocar arroz por batata-doce no almoço; aceitou a troca.'],
            ['O que comer antes do treino das 19:00?', 'Banana com aveia uma hora antes funciona bem para você.', 'Camila treina às 19:00; pré-treino sugerido: banana com aveia.'],
        ];
        foreach ($conversas as $j => [$pergunta, $resposta, $resumo]) {
            $quando = $hoje->subDays(3 - $j)->setTime(12, 0);
            $conversa = $camila->conversations()->create(['title' => $pergunta, 'summary' => $resumo, 'last_message_at' => $quando]);
            $conversa->messages()->create(['role' => 'user', 'content' => $pergunta, 'created_at' => $quando]);
            $conversa->messages()->create(['role' => 'assistant', 'content' => $resposta, 'created_at' => $quando->addSeconds(8)]);
        }

        User::factory()->withCompletedSteps(['objetivo', 'dados'])->create(['name' => 'Nina Souza', 'email' => 'novo@demo.pratoforte.test', 'password' => 'demo1234'])
            ->profile->update(['goal' => 'perder-gordura', 'preferred_name' => 'Nina', 'age' => 30, 'height_cm' => 170, 'start_weight_kg' => 70.0, 'sex' => 'feminino']);
    }

    /** Roda `$acao` como se fosse `$quando` (o plano "nasce" antes dos dias de uso) e devolve o relógio como estava. */
    private function travel(callable $acao, CarbonImmutable $quando): void
    {
        $antesImutavel = CarbonImmutable::getTestNow();
        $antes = Carbon::getTestNow();
        CarbonImmutable::setTestNow($quando);
        Carbon::setTestNow($quando);
        try {
            $acao();
        } finally {
            CarbonImmutable::setTestNow($antesImutavel);
            Carbon::setTestNow($antes);
        }
    }
}
