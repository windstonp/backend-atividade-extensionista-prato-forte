<?php

namespace App\Jobs;

use App\Enums\PlanStatus;
use App\Models\MealPlan;
use App\Services\Plans\PlanGenerator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** Gera o plano fora do request (RF09). Uma tentativa de job; as 2 tentativas com a IA ficam no gerador. */
class GeneratePlanJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 170;

    public function __construct(public readonly int $planId) {}

    public function handle(PlanGenerator $generator): void
    {
        $plan = MealPlan::find($this->planId);
        if ($plan === null || $plan->status !== PlanStatus::Pending) {
            return; // apagado, ou já marcado como TIMEOUT pelo plans:fail-stale
        }

        $generator->generate($plan);
    }

    public function failed(?Throwable $exception): void
    {
        MealPlan::whereKey($this->planId)
            ->whereIn('status', [PlanStatus::Pending, PlanStatus::Generating])
            ->update(['status' => PlanStatus::Failed, 'failure_reason' => 'AI_UNAVAILABLE']);
    }
}
