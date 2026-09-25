<?php

namespace Database\Factories;

use App\Models\Profile;
use App\Models\User;
use App\Models\UserSetting;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/** @extends Factory<User> */
class UserFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => static::$password ??= Hash::make('senha1234'),
            'consented_at' => now(),
            'terms_version' => config('prato.terms_version'),
            'remember_token' => Str::random(10),
        ];
    }

    /** Todo usuário tem perfil e configurações, como no cadastro real. */
    public function configure(): static
    {
        return $this->afterCreating(function (User $user) {
            if (! $user->profile()->exists()) {
                Profile::factory()->for($user)->create();
            }

            if (! $user->settings()->exists()) {
                UserSetting::factory()->for($user)->create();
            }
        });
    }

    public function onboarded(): static
    {
        return $this->has(Profile::factory()->onboarded(), 'profile');
    }

    /** @param  list<string>  $steps */
    public function withCompletedSteps(array $steps): static
    {
        return $this->has(Profile::factory()->state(['completed_steps' => $steps]), 'profile');
    }
}
