<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\DayResource;
use App\Models\User;
use App\Services\Days\DayMaterializer;
use App\Services\Days\EntryService;
use App\Support\DayDate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Spec 09 §5 — registrar, editar e remover o que foi comido. Todas respondem o dia completo. */
class EntryController extends Controller
{
    private const AMOUNT = ['numeric', 'gt:0', 'max:2000', 'decimal:0,1'];

    public function store(Request $request, string $date, string $slot, EntryService $service, DayMaterializer $days): JsonResponse
    {
        $data = $request->validate([
            'entries' => ['required', 'array', 'min:1', 'max:10'],
            'entries.*' => ['array:suggestion_item_id,food_id,custom_food_id,amount'],
            'entries.*.suggestion_item_id' => ['required_without_all:entries.*.food_id,entries.*.custom_food_id', 'prohibits:entries.*.food_id,entries.*.custom_food_id', 'integer'],
            'entries.*.food_id' => ['required_without_all:entries.*.suggestion_item_id,entries.*.custom_food_id', 'prohibits:entries.*.custom_food_id', 'integer'],
            'entries.*.custom_food_id' => ['integer'],
            'entries.*.amount' => ['required_with:entries.*.food_id,entries.*.custom_food_id', ...self::AMOUNT],
        ], [
            'entries.required' => 'Escolha pelo menos um alimento.',
            'entries.max' => 'Registre até 10 alimentos de uma vez.',
            'entries.*.amount.*' => 'Informe uma quantidade entre 0,1 e 2000.',
            'entries.*.*' => 'Escolha um alimento.',
        ]);
        /** @var User $user */
        $user = $request->user();
        $day = DayDate::parse($date);

        $service->add($user, $day, $slot, $data['entries']);

        return (new DayResource($days->view($user, $day)))->response()->setStatusCode(201);
    }

    public function update(Request $request, string $date, int $entry, EntryService $service, DayMaterializer $days): DayResource
    {
        $data = $request->validate(['amount' => ['required', ...self::AMOUNT]], ['amount.*' => 'Informe uma quantidade entre 0,1 e 2000.']);
        /** @var User $user */
        $user = $request->user();
        $day = DayDate::parse($date);

        $service->update($user, $day, $entry, (float) $data['amount']);

        return new DayResource($days->view($user, $day));
    }

    public function destroy(Request $request, string $date, int $entry, EntryService $service, DayMaterializer $days): DayResource
    {
        /** @var User $user */
        $user = $request->user();
        $day = DayDate::parse($date);

        $service->remove($user, $day, $entry);

        return new DayResource($days->view($user, $day));
    }
}
