<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\DayResource;
use App\Http\Resources\NutriMessageResource;
use App\Models\NutriMessage;
use App\Models\User;
use App\Services\Days\DayMaterializer;
use App\Services\Nutri\NutriActionService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class MessageActionController extends Controller
{
    public function store(Request $request, NutriMessage $message, int $index, NutriActionService $actions, DayMaterializer $days): JsonResponse
    {
        Gate::authorize('applyAction', $message);
        /** @var User $user */
        $user = $request->user();

        ['message' => $resolved, 'confirmation' => $confirmation] = $actions->resolve($user, $message, $index);

        $data = ['message' => ['id' => $resolved->id, 'actions' => [], 'actions_available' => false]];
        if ($confirmation !== null) {
            $data['confirmation'] = (new NutriMessageResource($confirmation))->resolve($request);
            $data['day'] = (new DayResource($days->view($user, CarbonImmutable::today())))->resolve($request);
        }

        return response()->json(['data' => $data]);
    }
}
