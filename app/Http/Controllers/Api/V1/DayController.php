<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\DayResource;
use App\Http\Resources\SubstitutionsResource;
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

    public function substitutions(Request $request, string $date, int $item, DayService $service): SubstitutionsResource
    {
        /** @var User $user */
        $user = $request->user();

        return new SubstitutionsResource($service->substitutions($user, DayDate::parse($date), $item));
    }

    public function swap(Request $request, string $date, int $item, DayService $service, DayMaterializer $days): DayResource
    {
        $data = $request->validate(['food_id' => ['required', 'integer']], ['food_id.*' => 'Escolha uma das opções.']);
        /** @var User $user */
        $user = $request->user();
        $day = DayDate::parse($date);

        $service->swap($user, $day, $item, (int) $data['food_id']);

        return new DayResource($days->view($user, $day));
    }

    public function undo(Request $request, string $date, DayService $service, DayMaterializer $days): DayResource
    {
        /** @var User $user */
        $user = $request->user();
        $day = DayDate::parse($date);

        $service->undo($user, $day);

        return new DayResource($days->view($user, $day));
    }
}
