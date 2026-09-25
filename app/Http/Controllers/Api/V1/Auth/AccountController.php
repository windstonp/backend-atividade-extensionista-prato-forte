<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Api\V1\Auth\Concerns\EndsCurrentSession;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\DeleteAccountRequest;
use App\Models\User;
use App\Services\Account\AccountService;
use Illuminate\Http\Response;

class AccountController extends Controller
{
    use EndsCurrentSession;

    /** RF06 */
    public function destroy(DeleteAccountRequest $request, AccountService $accounts): Response
    {
        /** @var User $user */
        $user = $request->user();

        $this->endCurrentSession($request);
        $accounts->delete($user);

        return response()->noContent();
    }
}
