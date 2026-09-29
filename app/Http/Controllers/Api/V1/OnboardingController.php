<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\OnboardingResource;
use App\Models\User;
use Illuminate\Http\Request;

class OnboardingController extends Controller
{
    public function show(Request $request): OnboardingResource
    {
        /** @var User $user */
        $user = $request->user();

        return new OnboardingResource($user->load(OnboardingResource::RELATIONS));
    }
}
