<?php

namespace App\Console\Commands;

use App\Enums\PlanStatus;
use App\Models\MealPlan;
use Illuminate\Console\Command;

/** RN19 — plano pedido há mais de 3 min e ainda sem resposta vira `failed` `TIMEOUT`. */
class FailStalePlans extends Command
{
    protected $signature = 'plans:fail-stale';

    protected $description = 'Marca como falhou (TIMEOUT) os planos gerando há mais de 3 minutos';

    public function handle(): int
    {
        $count = MealPlan::whereIn('status', [PlanStatus::Pending, PlanStatus::Generating])
            ->where('created_at', '<', now()->subMinutes(3))
            ->update(['status' => PlanStatus::Failed, 'failure_reason' => 'TIMEOUT']);

        $this->info("{$count} plano(s) marcados como TIMEOUT.");

        return self::SUCCESS;
    }
}
