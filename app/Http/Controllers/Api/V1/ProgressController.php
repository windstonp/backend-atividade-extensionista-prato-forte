<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Progress\ProgressService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProgressController extends Controller
{
    public function __invoke(Request $request, ProgressService $progress): JsonResponse
    {
        $request->validate(['period' => ['sometimes', Rule::in(array_keys(ProgressService::PERIODOS))]]);
        /** @var User $user */
        $user = $request->user();

        return response()->json(['data' => $progress->show($user, $request->string('period', '6w')->toString(), CarbonImmutable::today())]);
    }
}
