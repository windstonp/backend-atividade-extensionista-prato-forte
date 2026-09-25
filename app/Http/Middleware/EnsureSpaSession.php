<?php

namespace App\Http\Middleware;

use App\Enums\ErrorCode;
use App\Exceptions\DomainException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cadastro e login abrem sessão; só funcionam para o front (Sanctum "stateful").
 * Sem sessão (curl, fetch no servidor, SANCTUM_STATEFUL_DOMAINS errado), recusa antes de gravar qualquer coisa.
 */
class EnsureSpaSession
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasSession()) {
            throw new DomainException(ErrorCode::Forbidden);
        }

        return $next($request);
    }
}
