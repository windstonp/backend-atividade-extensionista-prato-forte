# Arquitetura do backend (Laravel 12)

Objetivo: arquitetura **convencional** do Laravel, que qualquer desenvolvedor Laravel reconheça, com a lógica de domínio em classes pequenas e testáveis. Sem repositories, sem DDD, sem pacotes de "arquitetura".

## 1. Stack e pacotes

| Item | Escolha | Por quê |
|---|---|---|
| PHP / Laravel | PHP 8.3, Laravel 12 | 🟢 Laravel; versões estáveis atuais |
| Banco | MySQL 8.0 | 🟢 |
| Autenticação | `laravel/sanctum` (modo SPA) | ✅ D7 — ver `autenticacao-autorizacao.md` |
| Fila | driver `database` | sem custo nem serviço extra (Redis dispensável na escala da validação) |
| Push | `laravel-notification-channels/webpush` | Web Push com VAPID, gratuito |
| HTTP para IA | `Illuminate\Support\Facades\Http` | sem SDK; API OpenAI-compatível via REST |
| Testes | Pest 3 + `pestphp/pest-plugin-laravel` | padrão do Laravel 12 |
| Estilo | Laravel Pint | padrão do Laravel |
| Análise estática | Larastan nível 6 | pega erros de tipo cedo; 🟡 opcional se atrasar o time |
| E-mail local | Mailpit (Docker) | captura e-mails de recuperação de senha em dev/E2E |

Fuso: `APP_TIMEZONE=America/Sao_Paulo` 🟡 (comunidade local; "hoje" é sempre o dia de Capivari de Baixo).
Locale: `APP_LOCALE=pt_BR`, com `lang/pt_BR/validation.php` (mensagens de validação em português).

## 2. Camadas e responsabilidades

```
Rota (routes/api.php)
  └─▶ Middleware (auth:sanctum, onboarded, throttle)
        └─▶ Form Request      — valida formato e regras de campo; authorize() delega à Policy quando há recurso
              └─▶ Controller  — fino: chama um Service/Action, devolve Resource
                    └─▶ Service / Action — regra de negócio, transação, disparo de Job
                          └─▶ Model Eloquent — relações, casts, scopes
                    └─▶ Resource — formato de saída (única fonte do contrato JSON)
```

Regras de estilo:
- **Controller**: sem regra de negócio, ≤ ~15 linhas por método.
- **Service** quando há orquestração com estado (banco, fila); **classe pura** (sem Eloquent, sem I/O) para cálculo — são as unidades mais testadas.
- Services recebem dependências por construtor (container do Laravel); nada de `new` de serviço dentro de controller.
- **Sem Repositories** — Eloquent já é a camada de acesso a dados; um repository só duplicaria a API. Consultas reutilizadas viram *scopes* ou métodos no Model.
- **Sem Events/Listeners no MVP** — cada efeito colateral tem um único dono e é chamado explicitamente (mais fácil de ler e testar). Reavaliar se surgirem 2+ reações ao mesmo fato.

## 3. Estrutura de diretórios

```
backend/
├── app/
│   ├── Ai/
│   │   ├── AiClient.php                    # interface: chat(array $messages, AiOptions): AiResult
│   │   ├── OpenAiCompatibleClient.php      # HTTP para /chat/completions (aimlapi.com, OpenAI…)
│   │   ├── FakeAiClient.php                # respostas roteirizadas (testes, E2E, demo)
│   │   ├── AiRequestLogger.php             # grava ai_requests (sem conteúdo)
│   │   ├── Prompts/
│   │   │   ├── PlanPrompt.php
│   │   │   ├── NutriPrompt.php
│   │   │   └── SummaryPrompt.php
│   │   └── Schemas/                        # contratos JSON esperados + parser
│   │       ├── PlanResponse.php
│   │       └── NutriResponse.php
│   ├── Console/Commands/
│   │   ├── SendMealReminders.php           # a cada minuto
│   │   ├── SendWeeklySummary.php           # domingo 20:00
│   │   ├── SendNutriTips.php               # ter/sex 18:00
│   │   ├── FailStalePlans.php              # a cada 5 min (RN19 timeout)
│   │   └── ExportValidationData.php        # validacao:exportar
│   ├── Enums/
│   │   ├── Goal.php  ActivityLevel.php  WorkPosture.php  Sex.php  LunchPlace.php
│   │   ├── MealSlot.php  FoodGroup.php  PlanStatus.php  ItemSource.php  DayChangeType.php
│   │   ├── MessageRole.php  NutriActionType.php  RatingValue.php  UnitSystem.php
│   │   ├── OnboardingStep.php  PlanEffect.php  AdherenceStatus.php  ErrorCode.php
│   ├── Exceptions/
│   │   └── DomainException.php             # code (ErrorCode) + status HTTP + details
│   ├── Http/
│   │   ├── Controllers/Api/V1/
│   │   │   ├── Auth/  RegisterController  SessionController  PasswordResetController  PasswordController  AccountController  MeController
│   │   │   ├── CatalogController  OnboardingController  ProfileController  PreferencesController
│   │   │   ├── PlanController  DayController  DayMealController  SwapController  SubstitutionController  UndoController
│   │   │   ├── ConversationController  MessageController  MessageActionController  NutriController
│   │   │   ├── WeighInController  ProgressController
│   │   │   ├── SettingsController  PushSubscriptionController
│   │   │   └── RatingController  UsabilityResponseController
│   │   ├── Middleware/
│   │   │   └── EnsureOnboardingCompleted.php
│   │   ├── Requests/                       # um Form Request por ação de escrita (lista nas specs de feature)
│   │   └── Resources/                      # UserResource, ProfileResource, DayResource, DayMealResource, …
│   ├── Jobs/
│   │   ├── GeneratePlanJob.php
│   │   └── SummarizeConversationJob.php
│   ├── Models/
│   │   ├── User  Profile  UserSetting
│   │   ├── Food  Restriction  PantryItem
│   │   ├── MealPlan  PlanMeal  PlanMealItem
│   │   ├── DayMeal  DayMealItem  DayMealChange
│   │   ├── WeighIn
│   │   ├── NutriConversation  NutriMessage
│   │   ├── Rating  UsabilityResponse  SentNotification  AiRequest
│   ├── Notifications/
│   │   ├── MealReminder.php  WeeklySummary.php  NutriTip.php   # canal WebPush
│   │   └── ResetPasswordNotification.php                        # e-mail pt-BR, link para o front
│   ├── Policies/
│   │   ├── MealPlanPolicy  NutriConversationPolicy  NutriMessagePolicy  WeighInPolicy  RatingPolicy
│   ├── Providers/
│   │   └── AppServiceProvider.php          # bind AiClient, morph map, rate limiters, Password::defaults
│   └── Services/
│       ├── Nutrition/
│       │   ├── NutritionCalculator.php     # RN13 (puro)
│       │   ├── MealScheduler.php           # RN14 (puro)
│       │   ├── DayTotals.php               # RN24 (puro)
│       │   ├── PortionFormatter.php        # "150 g, mais ou menos 5 colheres de sopa" (puro)
│       │   └── GoalWeightResolver.php      # RN10 (puro)
│       ├── Foods/
│       │   ├── FoodFilter.php              # RN16
│       │   └── SubstitutionFinder.php      # RN25 (puro sobre coleções)
│       ├── Plans/
│       │   ├── PlanService.php             # pedir geração, ativar, regenerar (RN19–RN21)
│       │   ├── PlanGenerator.php           # prompt → IA → parse → ajuste → validação → persistência
│       │   ├── PortionAdjuster.php         # RN18 (puro)
│       │   └── PlanValidator.php           # RN18 (puro)
│       ├── Days/
│       │   ├── DayMaterializer.php         # RN22, RN15
│       │   └── DayService.php              # marcar, trocar, aplicar, desfazer (RN23, RN26, RN27)
│       ├── Profile/
│       │   ├── OnboardingService.php       # RN08
│       │   └── ProfileService.php          # RN10, RN11, RN21
│       ├── Nutri/
│       │   ├── ConversationService.php     # RN28, RN30
│       │   ├── NutriContextBuilder.php     # RN29
│       │   ├── NutriChatService.php        # enviar pergunta, chamar IA, gravar resposta
│       │   ├── NutriActionValidator.php    # RN31
│       │   ├── NutriActionService.php      # aplicar ação
│       │   └── SuggestionBuilder.php       # sugestões de perguntas
│       ├── Progress/
│       │   ├── WeighInService.php          # RN34
│       │   ├── WeightForecast.php          # RN35 (puro)
│       │   ├── AdherenceCalculator.php     # RN36 (puro)
│       │   └── ProgressService.php         # RN37
│       ├── Notifications/
│       │   └── TipSelector.php             # regras de dicas (spec 06)
│       └── Validation/
│           ├── SusScore.php                # RN41 (puro)
│           └── ValidationExporter.php      # RN42
├── config/
│   ├── services.php                        # ai.driver, ai.base_url, ai.key, ai.model, ai.timeout
│   ├── validacao.php                       # rodada, data de início/fim
│   └── webpush.php
├── database/
│   ├── data/foods.csv
│   ├── factories/
│   ├── migrations/
│   └── seeders/  DatabaseSeeder  RestrictionSeeder  PantryItemSeeder  FoodSeeder  DemoSeeder  TestFoodSeeder  E2ESeeder
├── lang/pt_BR/  validation.php  passwords.php  auth.php
├── routes/
│   ├── api.php                             # prefixo /api/v1
│   └── console.php                         # agendamentos (Schedule::command…)
└── tests/
    ├── Pest.php
    ├── Unit/      (espelha app/Services, app/Ai/Schemas)
    ├── Feature/   (um arquivo por controller/fluxo)
    └── Fixtures/ai/   (respostas JSON da IA: válidas, inválidas, com alimento proibido)
```

## 4. Models — pontos de atenção

- `User`: `$fillable = ['name','email','password','consented_at','terms_version']`; casts `password => hashed`; `$hidden = ['password','remember_token']`; relações de §10 do modelo de dados; `activePlan()` (`hasOne` com `where is_active`); `latestWeighIn()`.
- `Profile`: casts de enum para cada campo enumerado; `training_days`, `other_restrictions`, `completed_steps` como `array`; accessor `isOnboarded()`.
- `Food`: scope `active()`; métodos puros `macrosFor(float $grams): Macros`.
- `MealPlan`: scope `ready()`, `active()`; `$guarded = ['id']` **não** — usar `$fillable` explícito em todos os models (ver `seguranca.md`).
- `NutriMessage`: casts `card`, `actions` como `array`.
- Morph map em `AppServiceProvider`: `Relation::enforceMorphMap(['nutri_message' => NutriMessage::class, 'meal_plan' => MealPlan::class, 'user' => User::class])`.
- `Model::shouldBeStrict(! app()->isProduction())` — pega *lazy loading* (N+1) e atributos inexistentes em dev/teste.

## 5. Enums

PHP 8.1+ Backed Enums (string), com método `label(): string` em português quando a UI precisa do texto:

| Enum | Casos |
|---|---|
| `Goal` | `ganhar-massa`, `perder-gordura`, `manter-peso`, `mais-disposicao` |
| `ActivityLevel` | `parado`, `leve`, `moderado`, `intenso` (+ `factor()`) |
| `WorkPosture` | `sentada`, `em-pe`, `peso-pesado` (+ `factorBonus()`) |
| `Sex` | `feminino`, `masculino`, `nao-dizer` |
| `LunchPlace` | `casa`, `marmita`, `restaurante` |
| `MealSlot` | `cafe`, `lanche`, `almoco`, `pre-treino`, `jantar` (+ `defaultName()`) |
| `FoodGroup` | `proteina`, `carboidrato`, `leguminosa`, `laticinio`, `fruta`, `vegetal`, `gordura`, `bebida`, `outros` (+ `primaryMacro()`) |
| `PlanStatus` | `pending`, `generating`, `ready`, `failed` |
| `ItemSource` | `plan`, `manual`, `nutri` |
| `DayChangeType` | `swap`, `apply_meal` |
| `MessageRole` | `user`, `assistant` |
| `NutriActionType` | `substituir`, `aplicar-refeicao`, `outra-opcao`, `ver-refeicao`, `dispensar` |
| `RatingValue` | `up`, `down` |
| `UnitSystem` | `metric`, `imperial` |
| `OnboardingStep` | `objetivo`, `dados`, `atividade`, `preferencias`, `restricoes`, `rotina`, `resumo` |
| `PlanEffect` | `none`, `times_updated`, `regeneration_suggested`, `regeneration_started` |
| `AdherenceStatus` | `completo`, `parcial`, `vazio`, `hoje` |
| `ErrorCode` | ver `api-convencoes-e-erros.md` §4 |

## 6. Middleware

| Middleware | Onde | Função |
|---|---|---|
| `EnsureFrontendRequestsAreStateful` (Sanctum) | grupo `api` | sessão por cookie para o domínio do front |
| `auth:sanctum` | todas as rotas, exceto registro, login, senha esquecida/redefinir, catálogo público | RN07 |
| `onboarded` (`EnsureOnboardingCompleted`) | plano (exceto status), dia, Nutri, evolução, avaliações, questionário | RN07 |
| `throttle:{nome}` | ver `seguranca.md` §Rate limiting | RN19, RN33 |

## 7. Jobs, fila e agendador

| Job / Comando | Quando | Fila | Tentativas | Observações |
|---|---|---|---|---|
| `GeneratePlanJob` | `POST /plans`, `POST /onboarding/complete`, RN21 | `ai` | `tries = 1` (a nova tentativa com erros é **interna**, RN18) | `timeout = 150`; `failed()` marca plano `failed` |
| `SummarizeConversationJob` | nova conversa (RN30) | `ai` | 2, backoff 60 s | falha silenciosa |
| `notifications` (Notifications `ShouldQueue`) | comandos abaixo | `default` | 3 | |
| `schedule:run` | cron `* * * * *` | — | — | `SendMealReminders` (everyMinute), `SendWeeklySummary` (sundays 20:00), `SendNutriTips` (tue/fri 18:00), `FailStalePlans` (everyFiveMinutes) — todos com `withoutOverlapping()` e `onOneServer()` |

Worker em produção: `php artisan queue:work --queue=ai,default --tries=1 --max-time=3600`, mantido por Supervisor ou systemd (ver RNF de operação).

## 8. Rotas (`routes/api.php`, resumo)

```php
Route::prefix('v1')->group(function () {
    // Públicas
    Route::post('register', RegisterController::class)->middleware('throttle:register');
    Route::post('login', [SessionController::class, 'store'])->middleware('throttle:login');
    Route::post('password/forgot', [PasswordResetController::class, 'forgot'])->middleware('throttle:password');
    Route::post('password/reset', [PasswordResetController::class, 'reset'])->middleware('throttle:password');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [SessionController::class, 'destroy']);
        Route::get('me', MeController::class);
        Route::put('me/password', PasswordController::class);
        Route::delete('me', [AccountController::class, 'destroy']);

        Route::get('catalog/onboarding', CatalogController::class);
        Route::get('onboarding', [OnboardingController::class, 'show']);
        Route::patch('profile/steps/{step}', [ProfileController::class, 'updateStep']);
        Route::post('onboarding/complete', [OnboardingController::class, 'complete']);
        Route::get('plans/preview-targets', [PlanController::class, 'previewTargets']);
        Route::get('plans/{plan}', [PlanController::class, 'show']);
        Route::post('plans', [PlanController::class, 'store'])->middleware('throttle:plans');
        Route::get('settings', [SettingsController::class, 'show']);
        Route::put('settings', [SettingsController::class, 'update']);
        Route::post('push-subscriptions', [PushSubscriptionController::class, 'store']);
        Route::delete('push-subscriptions', [PushSubscriptionController::class, 'destroy']);

        Route::middleware('onboarded')->group(function () {
            Route::get('profile', [ProfileController::class, 'show']);
            Route::put('profile/preferences', PreferencesController::class);
            Route::get('plans/active', [PlanController::class, 'active']);
            Route::get('days/{date}', [DayController::class, 'show']);
            Route::patch('days/{date}/meals/{slot}', [DayMealController::class, 'update']);
            Route::get('days/{date}/items/{item}/substitutions', SubstitutionController::class);
            Route::post('days/{date}/items/{item}/swap', SwapController::class);
            Route::post('days/{date}/undo', UndoController::class);
            Route::apiResource('conversations', ConversationController::class)->only(['index','store','show','destroy']);
            Route::get('conversations/{conversation}/messages', [MessageController::class, 'index']);
            Route::post('conversations/{conversation}/messages', [MessageController::class, 'store'])->middleware('throttle:nutri');
            Route::post('messages/{message}/actions/{index}', MessageActionController::class);
            Route::get('nutri/context', [NutriController::class, 'context']);
            Route::get('nutri/suggestions', [NutriController::class, 'suggestions']);
            Route::get('weigh-ins', [WeighInController::class, 'index']);
            Route::post('weigh-ins', [WeighInController::class, 'store']);
            Route::get('progress', ProgressController::class);
            Route::put('ratings', [RatingController::class, 'upsert']);
            Route::delete('ratings', [RatingController::class, 'destroy']);
            Route::get('usability-responses/status', [UsabilityResponseController::class, 'status']);
            Route::post('usability-responses', [UsabilityResponseController::class, 'store']);
            Route::post('usability-responses/dismiss', [UsabilityResponseController::class, 'dismiss']);
        });
    });
});
```

Parâmetros: `{date}` aceita `YYYY-MM-DD` ou `today` (`->where('date', 'today|\d{4}-\d{2}-\d{2}')`); `{slot}` restrito ao enum `MealSlot` (`->whereIn`); `{step}` ao enum `OnboardingStep`.

## 9. Configuração (`.env`)

```
APP_TIMEZONE=America/Sao_Paulo
APP_LOCALE=pt_BR
FRONTEND_URL=https://app.pratoforte.exemplo
SESSION_DRIVER=database
SESSION_DOMAIN=.pratoforte.exemplo
SESSION_SECURE_COOKIE=true
SANCTUM_STATEFUL_DOMAINS=app.pratoforte.exemplo
QUEUE_CONNECTION=database
MAIL_MAILER=smtp            # Gmail (senha de app) ou Brevo
AI_DRIVER=openai            # openai | fake
AI_BASE_URL=https://api.aimlapi.com/v1
AI_API_KEY=                 # NUNCA no repositório (RN44) — a chave do Node deve ser revogada
AI_MODEL_PLAN=gpt-4o-mini   # exemplo; escolher pelo custo/qualidade na validação
AI_MODEL_CHAT=gpt-4o-mini
AI_TIMEOUT=60
VAPID_PUBLIC_KEY=
VAPID_PRIVATE_KEY=
VALIDACAO_RODADA=2026-1
```

## 10. Ambiente local

`docker compose` 🟡 com: `mysql:8.0`, `mailpit`, e a aplicação via `php artisan serve` ou Laravel Sail. Scripts `composer`:
- `composer dev` — serve + queue:listen + schedule:work em paralelo
- `composer test` — Pest
- `composer lint` — Pint --test + Larastan
