<?php

namespace App\Services\Plans;

use App\Ai\AiClient;
use App\Ai\AiOptions;
use App\Ai\AiUnavailableException;
use App\Ai\Prompts\PlanPrompt;
use App\Ai\Schemas\InvalidAiResponse;
use App\Ai\Schemas\PlanResponse;
use App\Enums\MealSlot;
use App\Enums\PlanStatus;
use App\Models\Food;
use App\Models\MealPlan;
use App\Services\Foods\FoodFilter;
use App\Services\Nutrition\MealScheduler;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** Pipeline da geração (integracao-ia.md §3.3): prompt → IA → parse → ajuste → validação → 2ª tentativa → persistência. */
class PlanGenerator
{
    private const MAX_ATTEMPTS = 2;

    public function __construct(
        private readonly AiClient $ai,
        private readonly FoodFilter $filter,
        private readonly MealScheduler $scheduler,
        private readonly PortionAdjuster $adjuster,
        private readonly PlanValidator $validator,
        private readonly PlanService $plans,
    ) {}

    public function generate(MealPlan $plan): void
    {
        $plan->update(['status' => PlanStatus::Generating]);
        $user = $plan->user()->firstOrFail();
        $profile = $user->profile()->firstOrFail();

        $allowed = $this->filter->allowedFor($user);
        $pantry = $this->filter->pantryFoodIds($user);
        $training = substr((string) $profile->training_time, 0, 5);
        $times = $this->scheduler->schedule(
            substr((string) $profile->wake_time, 0, 5), $training, substr((string) $profile->sleep_time, 0, 5), $profile->training_days !== [],
        );

        // Minimização: nada de nome, e-mail ou texto livre (seguranca.md); "outras restrições" já saíram no filtro.
        $inputs = [
            'pessoa' => [
                'objetivo' => $profile->goal, 'sexo' => $profile->sex, 'idade' => $profile->age, 'altura_cm' => $profile->height_cm,
                'peso_kg' => $user->currentWeightKg(), 'atividade' => $profile->activity_level, 'trabalho' => $profile->work_posture,
                'almoco' => $profile->lunch_place,
            ],
            'metas_diarias' => [
                'kcal' => $plan->target_kcal, 'proteina_g' => $plan->target_protein_g,
                'carboidrato_g' => $plan->target_carbs_g, 'gordura_g' => $plan->target_fat_g,
            ],
            'horarios' => $times + ['treino' => $training],
            'distribuicao_kcal' => PlanPrompt::DISTRIBUICAO_KCAL,
            // v3: a conta já feita — modelos menores (Gemini Flash-Lite) erravam a proporção e estouravam o dia.
            'meta_kcal_por_refeicao' => array_map(fn (float $parte) => (int) round($plan->target_kcal * $parte), PlanPrompt::DISTRIBUICAO_KCAL),
            'alimentos_permitidos' => $allowed->map(fn (Food $food) => [
                'id' => $food->id, 'nome' => $food->name, 'grupo' => $food->group,
                'kcal_100g' => $food->kcal_per_100g, 'prot_100g' => $food->protein_per_100g,
                'carb_100g' => $food->carbs_per_100g, 'gord_100g' => $food->fat_per_100g,
                'pantry' => in_array($food->id, $pantry, true),
                'porcao_g' => $food->typical_portion_g,
            ])->values()->all(),
        ];
        $plan->update(['inputs' => $inputs + ['prompt_version' => PlanPrompt::VERSION]]);

        $messages = [
            ['role' => 'system', 'content' => PlanPrompt::system()],
            ['role' => 'user', 'content' => PlanPrompt::user($inputs)],
        ];
        $options = new AiOptions('plan', (string) config('services.ai.model_plan'), 1500, json: true, temperature: 0.4, userId: $user->id);

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            try {
                $result = $this->ai->chat($messages, $options);
            } catch (AiUnavailableException) {
                $this->fail($plan, 'AI_UNAVAILABLE');

                return;
            }
            $plan->increment('attempts');

            try {
                $meals = $this->adjuster->adjust(PlanResponse::parse($result->content), $allowed, $plan->target_kcal);
                $errors = $this->validator->validate($meals, $allowed, $plan->target_kcal, $plan->target_protein_g);
            } catch (InvalidAiResponse $e) {
                $meals = [];
                $errors = [$e->getMessage()];
            }

            if ($errors === []) {
                $this->persist($plan, $meals, $times);
                $this->plans->activate($plan);

                return;
            }

            // Só as regras que falharam (nunca o conteúdo da IA — RN44): é o que explica um AI_INVALID_RESPONSE.
            Log::warning('plan.invalid_attempt', ['plan_id' => $plan->id, 'attempt' => $attempt, 'errors' => $errors]);
            $messages[] = ['role' => 'assistant', 'content' => $result->content];
            $messages[] = ['role' => 'user', 'content' => PlanPrompt::correction($errors)];
        }

        $this->fail($plan, 'AI_INVALID_RESPONSE');
    }

    /**
     * @param  list<array{slot: string, items: list<array{food_id: int, grams: float}>}>  $meals
     * @param  array<string, string>  $times  slot => "HH:MM", em ordem de horário
     */
    private function persist(MealPlan $plan, array $meals, array $times): void
    {
        $order = array_keys($times);

        DB::transaction(function () use ($plan, $meals, $times, $order) {
            foreach ($meals as $meal) {
                $planMeal = $plan->meals()->create([
                    'slot' => $meal['slot'],
                    'name' => MealSlot::from($meal['slot'])->label(),
                    'time' => $times[$meal['slot']],
                    'position' => (int) array_search($meal['slot'], $order, true) + 1,
                ]);
                foreach ($meal['items'] as $i => $item) {
                    $planMeal->items()->create(['food_id' => $item['food_id'], 'grams' => $item['grams'], 'position' => $i + 1]);
                }
            }
        });
    }

    private function fail(MealPlan $plan, string $reason): void
    {
        $plan->update(['status' => PlanStatus::Failed, 'failure_reason' => $reason]);
    }
}
