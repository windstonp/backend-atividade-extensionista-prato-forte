<?php

namespace App\Services\Progress;

use App\Models\User;
use App\Models\WeighIn;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;

/** RN34 — uma pesagem por data; registrar de novo na mesma data substitui. */
final class WeighInService
{
    /**
     * @return array{weighIn: WeighIn, replaced: bool}
     */
    public function record(User $user, float $kg, CarbonImmutable $date): array
    {
        $existente = $user->weighIns()->whereDate('date', $date)->first();
        if ($existente !== null) {
            $existente->update(['weight_kg' => $kg]);

            return ['weighIn' => $existente, 'replaced' => true];
        }

        try {
            return ['weighIn' => $user->weighIns()->create(['date' => $date->toDateString(), 'weight_kg' => $kg]), 'replaced' => false];
        } catch (UniqueConstraintViolationException) {
            // Outra aba gravou a mesma data entre a leitura e a escrita: vira atualização.
            $existente = $user->weighIns()->whereDate('date', $date)->sole();
            $existente->update(['weight_kg' => $kg]);

            return ['weighIn' => $existente, 'replaced' => true];
        }
    }
}
