<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ConversationResource;
use App\Models\NutriConversation;
use App\Models\User;
use App\Services\Nutri\ConversationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class ConversationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $page = $user->conversations()->withMessages()->orderByDesc('last_message_at')->orderByDesc('id')->cursorPaginate(15);

        return response()->json([
            'data' => ConversationResource::collection($page->items())->resolve($request),
            'meta' => ['next_cursor' => $page->nextCursor()?->encode(), 'per_page' => 15],
        ]);
    }

    public function store(Request $request, ConversationService $service): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        ['conversation' => $conversation, 'created' => $created] = $service->start($user);

        return (new ConversationResource($conversation))->response()->setStatusCode($created ? 201 : 200);
    }

    public function show(NutriConversation $conversation): ConversationResource
    {
        Gate::authorize('view', $conversation);

        return new ConversationResource($conversation);
    }

    public function destroy(NutriConversation $conversation): Response
    {
        Gate::authorize('view', $conversation);
        $conversation->delete(); // mensagens caem em cascata; o resumo vai junto (RN28)

        return response()->noContent();
    }
}
