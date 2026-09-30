<?php

namespace App\Providers;

use App\Ai\AiClient;
use App\Ai\FakeAiClient;
use App\Ai\LoggingAiClient;
use App\Ai\OpenAiCompatibleClient;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(FakeAiClient::class, fn () => new FakeAiClient(config('services.ai.fake_fail_plan_for', [])));

        // Toda chamada passa pelo log sem conteúdo (RN44). Sem AI_DRIVER=openai, a IA é a falsa.
        $this->app->singleton(AiClient::class, fn ($app) => new LoggingAiClient(
            config('services.ai.driver') === 'openai'
                ? new OpenAiCompatibleClient((string) config('services.ai.base_url'), config('services.ai.key'), (int) config('services.ai.timeout'))
                : $app->make(FakeAiClient::class),
        ));
    }

    public function boot(): void
    {
        // Pega N+1 e atributo inexistente em dev/teste.
        Model::shouldBeStrict(! $this->app->isProduction());

        $this->configureRateLimiting();
    }

    /** Limites de `specs/00-fundacao/seguranca.md` §8. */
    private function configureRateLimiting(): void
    {
        $emailAndIp = function (Request $request): string {
            $email = $request->input('email');

            return (is_string($email) ? Str::lower(trim($email)) : '').'|'.$request->ip();
        };

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by($request->user()?->getAuthIdentifier() ?: $request->ip()));
        RateLimiter::for('plans', fn (Request $request) => Limit::perDay(5)->by('plans|'.$request->user()?->getAuthIdentifier()));
        RateLimiter::for('register', fn (Request $request) => Limit::perMinute(3)->by($request->ip()));
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)->by($emailAndIp($request)));
        RateLimiter::for('password', fn (Request $request) => $request->is('api/v1/password/forgot')
            ? Limit::perHour(3)->by('forgot|'.$emailAndIp($request))
            : Limit::perHour(5)->by('reset|'.$emailAndIp($request)));
    }
}
