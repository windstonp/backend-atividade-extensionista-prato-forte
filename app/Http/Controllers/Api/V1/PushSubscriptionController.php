<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\PushSubscriptionRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class PushSubscriptionController extends Controller
{
    /** Upsert por endpoint; se era de outra conta neste navegador, passa a ser desta. */
    public function store(PushSubscriptionRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $user->updatePushSubscription(
            $request->string('endpoint')->toString(),
            $request->string('keys.p256dh')->toString(),
            $request->string('keys.auth')->toString(),
            $request->string('content_encoding', 'aes128gcm')->toString(),
        );

        return response()->json(['data' => ['subscribed' => true]], 201);
    }

    /** CA07 — sai no logout; idempotente. */
    public function destroy(PushSubscriptionRequest $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $user->deletePushSubscription($request->string('endpoint')->toString());

        return response()->noContent();
    }
}
