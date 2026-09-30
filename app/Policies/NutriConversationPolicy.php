<?php

namespace App\Policies;

use App\Models\NutriConversation;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/** RN43 — conversa de outra pessoa não existe para quem pergunta. */
class NutriConversationPolicy
{
    public function view(User $user, NutriConversation $conversation): Response
    {
        return $conversation->user_id === $user->id ? Response::allow() : Response::denyAsNotFound();
    }
}
