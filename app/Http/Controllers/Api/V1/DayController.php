<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\DayResource;
use App\Models\User;
use App\Services\Days\DayMaterializer;
use App\Support\DayDate;
use Illuminate\Http\Request;

class DayController extends Controller
{
    public function show(Request $request, string $date, DayMaterializer $days): DayResource
    {
        /** @var User $user */
        $user = $request->user();

        return new DayResource($days->view($user, DayDate::parse($date)));
    }
}
