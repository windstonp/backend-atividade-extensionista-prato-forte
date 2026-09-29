<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Catalog\OnboardingCatalog;
use Illuminate\Http\JsonResponse;

class CatalogController extends Controller
{
    public function __invoke(OnboardingCatalog $catalog): JsonResponse
    {
        return response()->json(['data' => $catalog->toArray()]);
    }
}
