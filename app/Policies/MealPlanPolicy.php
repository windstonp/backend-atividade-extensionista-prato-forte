<?php

namespace App\Policies;

use App\Models\MealPlan;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/** RN43 — plano de outra pessoa não existe para quem pergunta (404, não 403). */
class MealPlanPolicy
{
    public function view(User $user, MealPlan $plan): Response
    {
        return $plan->user_id === $user->id ? Response::allow() : Response::denyAsNotFound();
    }
}
