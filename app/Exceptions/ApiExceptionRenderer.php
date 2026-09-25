<?php

namespace App\Exceptions;

use App\Enums\ErrorCode;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/** Único ponto que decide o corpo de erro de `/api/*` (`api-convencoes-e-erros.md` §3). */
final class ApiExceptionRenderer
{
    public function render(Throwable $e): ?JsonResponse
    {
        return match (true) {
            $e instanceof DomainException => $this->respond($e->errorCode, $e->getMessage(), details: $e->details),
            $e instanceof ValidationException => $this->respond(ErrorCode::ValidationError, errors: $e->errors()),
            $e instanceof AuthenticationException => $this->respond(ErrorCode::Unauthenticated),
            $e instanceof HttpExceptionInterface => $this->fromHttp($e),
            (bool) config('app.debug') => null, // em dev, deixa o Laravel mostrar a exceção
            default => $this->respond(ErrorCode::ServerError),
        };
    }

    private function fromHttp(HttpExceptionInterface $e): JsonResponse
    {
        $status = $e->getStatusCode();

        if ($status === 429) {
            $retryAfter = (int) ($e->getHeaders()['Retry-After'] ?? 60);

            return $this->respond(
                ErrorCode::TooManyRequests,
                "Muitas tentativas seguidas. Tente de novo em {$retryAfter} segundos.",
                details: ['retry_after' => $retryAfter],
            )->withHeaders($e->getHeaders());
        }

        return match ($status) {
            401 => $this->respond(ErrorCode::Unauthenticated),
            403, 419 => $this->respond(ErrorCode::Forbidden),
            404, 405 => $this->respond(ErrorCode::NotFound),
            default => $this->respond(ErrorCode::ServerError, status: $status),
        };
    }

    /**
     * @param  array<string, array<int, string>>  $errors
     * @param  array<string, mixed>  $details
     */
    private function respond(ErrorCode $code, ?string $message = null, array $errors = [], array $details = [], ?int $status = null): JsonResponse
    {
        $body = ['message' => $message ?? $code->message(), 'code' => $code->value];

        if ($errors !== []) {
            $body['errors'] = $errors;
        }

        if ($details !== []) {
            $body['details'] = $details;
        }

        return new JsonResponse($body, $status ?? $code->status());
    }
}
