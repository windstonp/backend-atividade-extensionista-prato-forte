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
        $profiles->updatePreferences($user, $request->validated());

        // plan_effect: RN21 (regeneração ao mudar restrição) entra com o plano, no Plano 04.
        return (new ProfileResource($user->fresh(ProfileResource::RELATIONS)))
            ->additional(['meta' => ['plan_effect' => 'none', 'plan_id' => null]])
            ->response();
    }
}
