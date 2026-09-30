<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\DayResource;
use App\Models\User;
use App\Services\Days\DayMaterializer;
use App\Services\Days\DayService;
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

    public function toggleMeal(Request $request, string $date, string $slot, DayService $service, DayMaterializer $days): DayResource
    {
        $data = $request->validate(['done' => ['required', 'boolean']], ['done.*' => 'Diga se a refeição foi feita.']);
        /** @var User $user */
        $user = $request->user();
        $day = DayDate::parse($date);

        $service->setDone($user, $day, $slot, (bool) $data['done']);

        return new DayResource($days->view($user, $day));
    }
}
