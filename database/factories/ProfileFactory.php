<?php

namespace Database\Factories;

use App\Enums\OnboardingStep;
use App\Models\Profile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Sempre use com ->for($user) (ou via UserFactory): o perfil não cria usuário sozinho.
 *
 * @extends Factory<Profile>
 */
class ProfileFactory extends Factory
{
    public function definition(): array
    {
        return [];
    }

    /** Todas as etapas respondidas (Camila do mock), sem concluir o onboarding. */
    public function answered(): static
    {
        return $this->state(fn () => [
            'goal' => 'ganhar-massa',
            'sex' => 'feminino',
            'age' => 27,
            'height_cm' => 164,
            'start_weight_kg' => 58.4,
            'goal_weight_kg' => 62.0,
            'goal_weight_source' => 'user',
            'activity_level' => 'moderado',
            'work_posture' => 'sentada',
            'wake_time' => '06:20',
            'training_time' => '19:00',
            'sleep_time' => '23:00',
            'training_days' => [1, 3, 5],
            'lunch_place' => 'marmita',
            'completed_steps' => ['objetivo', 'dados', 'atividade', 'preferencias', 'restricoes', 'rotina'],
        ]);
    }

    /** Perfil da Camila do mock, com onboarding concluído. */
    public function onboarded(): static
    {
        return $this->answered()->state(fn () => [
            'completed_steps' => array_map(fn (OnboardingStep $step) => $step->value, OnboardingStep::cases()),
            'onboarding_completed_at' => now(),
        ]);
    }
}
