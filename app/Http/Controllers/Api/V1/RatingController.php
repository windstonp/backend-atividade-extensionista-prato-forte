<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\RatingRequest;
use App\Models\User;
use App\Services\Validation\RatingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class RatingController extends Controller
{
    public function update(RatingRequest $request, RatingService $ratings): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $rating = $ratings->rate(
            $user,
            $request->string('rateable_type')->toString(),
            $request->integer('rateable_id'),
            $request->string('value')->toString(),
            $request->input('comment'),
        );

        return response()->json(['data' => [
            'rateable_type' => $rating->rateable_type,
            'rateable_id' => $rating->rateable_id,
            'value' => $rating->value,
            'comment' => $rating->comment,
        ]]);
    }

    public function destroy(RatingRequest $request, RatingService $ratings): Response
    {
        /** @var User $user */
        $user = $request->user();
        $ratings->remove($user, $request->string('rateable_type')->toString(), $request->integer('rateable_id'));

        return response()->noContent();
    }
}
