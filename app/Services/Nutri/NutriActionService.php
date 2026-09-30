<?php

namespace App\Services\Nutri;

use App\Enums\ErrorCode;
use App\Enums\ItemSource;
use App\Enums\MealSlot;
use App\Exceptions\DomainException;
use App\Models\NutriMessage;
use App\Models\User;
use App\Services\Days\DayService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** RF21 — aplica ou dispensa a ação de uma resposta, uma vez, no mesmo dia (RN31). */
class NutriActionService
{
    public function __construct(private readonly DayService $days) {}

    /** @return array{message: NutriMessage, confirmation: NutriMessage|null} */
    public function resolve(User $user, NutriMessage $message, int $index): array
    {
        return DB::transaction(function () use ($user, $message, $index) {
            /** @var NutriMessage $locked */
            $locked = NutriMessage::whereKey($message->id)->lockForUpdate()->firstOrFail(); // duplo toque: um aplica
            $action = collect($locked->actions ?? [])->firstWhere('index', $index);
            if (! is_array($action) || ! in_array($action['kind'] ?? null, ['substituir', 'aplicar-refeicao', 'dispensar'], true)) {
                throw new NotFoundHttpException;
            }
            if ($locked->actions_resolved_at !== null) {
                throw new DomainException(ErrorCode::ActionAlreadyApplied);
            }
            if (! $locked->created_at->isToday()) {
                throw new DomainException(ErrorCode::ActionExpired, [], 'Essa sugestão era para '.$locked->created_at->format('d/m').'.');
            }

            $confirmation = null;
            $today = CarbonImmutable::today();
            $card = $locked->card ?? [];
            if ($action['kind'] === 'substituir') {
                $this->days->replaceFood($user, $today, $card['slot'], $card['from']['food_id'], $card['to']['food_id'], (float) $card['to']['grams'], ItemSource::Nutri);
                $confirmation = $this->confirm($locked, $card['slot'], 'vai com '.mb_strtolower($card['to']['name']));
            } elseif ($action['kind'] === 'aplicar-refeicao') {
                $items = array_map(fn (array $i) => ['food_id' => (int) $i['food_id'], 'grams' => (float) $i['grams']], $card['items']);
                $this->days->applyMeal($user, $today, $card['slot'], $items, ItemSource::Nutri);
                $confirmation = $this->confirm($locked, $card['slot'], 'foi trocado');
            }

            $locked->update(['actions_resolved_at' => now(), 'resolved_action_index' => $index]);

            return ['message' => $locked, 'confirmation' => $confirmation];
        });
    }

    private function confirm(NutriMessage $message, string $slot, string $what): NutriMessage
    {
        $name = mb_strtolower(MealSlot::from($slot)->label());
        $confirmation = $message->conversation->messages()->create([
            'role' => 'assistant',
            'content' => "Feito. Seu {$name} de hoje {$what}.",
            'actions' => [['index' => 0, 'kind' => 'ver-refeicao', 'label' => 'Ver a refeição', 'slot' => $slot]],
            'follow_up_suggestions' => [],
        ]);
        $message->conversation->update(['last_message_at' => now()]);

        return $confirmation;
    }
}
