<?php

namespace App\Policies;

use App\Models\NutriMessage;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/** Só as respostas do Nutri, e só do dono, têm ações (RN31, RN43). */
class NutriMessagePolicy
{
    public function applyAction(User $user, NutriMessage $message): Response
    {
        return $message->role === 'assistant' && $message->conversation->user_id === $user->id
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
