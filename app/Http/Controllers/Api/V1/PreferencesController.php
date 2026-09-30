<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Profile\PreferencesRequest;
use App\Http\Resources\ProfileResource;
use App\Models\User;
use App\Services\Profile\ProfileService;
use Illuminate\Http\JsonResponse;

class PreferencesController extends Controller
{
    public function __invoke(PreferencesRequest $request, ProfileService $profiles): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $result = $profiles->updatePreferences($user, $request->validated());

        return (new ProfileResource($user->fresh(ProfileResource::RELATIONS)))
            ->additional(['meta' => ['plan_effect' => $result['effect']->value, 'plan_id' => $result['plan_id']]])
            ->response();
    }
}
