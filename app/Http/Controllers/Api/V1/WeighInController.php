<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\WeighInRequest;
use App\Http\Resources\WeighInResource;
use App\Models\User;
use App\Services\Progress\WeighInService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class WeighInController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        return WeighInResource::collection($user->weighIns()->orderBy('date')->get());
    }

    public function store(WeighInRequest $request, WeighInService $weighIns): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $date = $request->filled('date') ? CarbonImmutable::parse($request->string('date')->toString()) : CarbonImmutable::today();
        $result = $weighIns->record($user, (float) $request->input('weight_kg'), $date);

        return (new WeighInResource($result['weighIn']))
            ->additional(['meta' => ['replaced' => $result['replaced']]])
            ->response()
            ->setStatusCode($result['replaced'] ? 200 : 201);
    }
}
