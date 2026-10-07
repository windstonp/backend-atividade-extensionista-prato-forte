<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\NutriMessageResource;
use App\Models\NutriConversation;
use App\Models\User;
use App\Services\Nutri\NutriChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class MessageController extends Controller
{
    public function index(Request $request, NutriConversation $conversation): JsonResponse
    {
        Gate::authorize('view', $conversation);
        $page = $conversation->messages()->with('rating')->orderByDesc('id')->cursorPaginate(30);

        return response()->json([
            'data' => NutriMessageResource::collection($page->items())->resolve($request),
            'meta' => ['next_cursor' => $page->nextCursor()?->encode(), 'per_page' => 30],
        ]);
    }

    public function store(Request $request, NutriConversation $conversation, NutriChatService $chat): JsonResponse
    {
        Gate::authorize('view', $conversation);
        if (is_string($request->input('content'))) {
            $request->merge(['content' => trim($request->input('content'))]); // não-texto: o validador responde 422
        }
        $data = $request->validate(
            ['content' => ['required', 'string', 'min:1', 'max:1000']],
            ['content.required' => 'Escreva sua pergunta.', 'content.max' => 'Sua pergunta passou de 1.000 caracteres.'],
        );
        /** @var User $user */
        $user = $request->user();

        ['user' => $question, 'assistant' => $answer] = $chat->send($user, $conversation, $data['content']);

        return response()->json(['data' => [
            'user_message' => (new NutriMessageResource($question))->resolve($request),
            'assistant_message' => (new NutriMessageResource($answer))->resolve($request),
        ]], 201);
    }
}
