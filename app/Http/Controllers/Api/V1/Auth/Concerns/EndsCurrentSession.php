<?php

namespace App\Http\Controllers\Api\V1\Auth\Concerns;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

trait EndsCurrentSession
{
    /** Sai da conta nesta sessão e troca a sessão por uma nova, anônima. */
    protected function endCurrentSession(Request $request): void
    {
        Auth::guard('web')->logout();
        // `auth:sanctum` deixa a guarda "sanctum" como padrão, com o usuário em cache; é ela que
        // o driver de sessão consulta para gravar `sessions.user_id`. Sem isto a sessão nova
        // (anônima) seria gravada ainda com o id do usuário.
        Auth::forgetUser();

        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }
}
