# Plano 03 — Onboarding e perfil — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Tirar o onboarding e o perfil do `localStorage`/mock: catálogo semeado (restrições, cozinha, alimentos), etapas salvas no servidor com retomada, meta de peso (RN10/RN11), prévia das metas (RN13), conclusão do onboarding e as telas Perfil e Preferências lendo a API.

**Architecture:** Backend convencional do Plano 01: Form Request → controller fino → `ProfileService`/`OnboardingService` → Resource. As regras de cálculo são classes puras (`GoalWeightResolver`, `NutritionCalculator`), testadas por tabela. No front, cada etapa é um contêiner (`useEtapa` = catálogo + respostas + salvar/navegar) que monta um formulário com estado local inicializado das respostas salvas; o `OnboardingStep` é a casca apresentacional (carregando, erro, salvando, modo edição).

**Tech Stack:** Laravel 12 / PHP 8.3 / MySQL 8 / Pest 3 (repo backend); Next 16.3.5 / React 19.2 / TanStack Query 5 / Storybook 10.6 / Vitest 4.1 / MSW 2 / Playwright 1.63 (repo front) — tudo em Docker, como no Plano 02.

**Spec:** `specs/02-onboarding-perfil/spec.md`, com `specs/00-fundacao/{regras-de-negocio (RN07–RN13, RN16, RN21, RN34, RN39), modelo-de-dados §2, §3, §6, §12}.md` e `specs/03-plano-alimentar/spec.md` (só `GET /plans/preview-targets`). Caminhos de spec/plano relativos ao repo **backend**. Roteiro: `docs/superpowers/plans/2026-09-24-00-roteiro.md`.

**Onde rodar:** backend em `/home/alvez/atividade-extensionista/backend` (`docker compose run --rm api …`), front em `/home/alvez/atividade-extensionista/frontend` (`docker compose run --rm web …`). **Branches empilhadas** (o Plano 02 ainda não foi integrado): backend `plano-03-onboarding` saindo de `plano-02-front`; front `plano-03-onboarding` saindo de `plano-02-fundacao-contas`.

## Decisões deste plano (rulings sobre a spec)

1. **Sem plano alimentar ainda (é o Plano 04).** `POST /onboarding/complete` conclui o onboarding e responde `202 { "data": { "plan": null } }`; o Plano 04 cria o plano e dispara o `GeneratePlanJob`. O front, depois de concluir, vai para a tela "Gerando" (ainda de protótipo).
2. **RN21 (`plan_effect`) sempre `none` neste plano.** Pela própria RN21, sem plano não há o que refazer. O Plano 04 implementa a tabela de efeitos. O front trata `none` ("Salvo.") e o aviso de meta ajustada (RN11).
3. **Unidades imperiais (RN39) ficam para o Plano 07**, junto com `GET/PATCH /settings`: hoje ninguém consegue escolher `imperial`.
4. **Catálogo de alimentos com ~60 itens** (não ~120), cobrindo a lista mínima da spec (§12 do modelo de dados). Os valores seguem a TACO 4ª ed. quando existe o item, rótulo ou USDA quando não existe, e "receita caseira" para preparos. Vira a pendência **P5** para os autores conferirem antes da validação.
5. **Parceiro em `config('prato.parceiro')`** (não `app.parceiro`): o projeto já concentra suas constantes em `config/prato.php`.
6. **Perfil (S17):** subtítulo "No Prato Forte desde {mês de ano}" (P1, opção a); a linha "Notificações e conta" mostra texto fixo até o Plano 07; "Avaliar o app" entra no Plano 08; "Refazer meu plano" continua o `NutriBar` do protótipo até o Plano 04.

## Global Constraints

- Etapas e ordem (RN08): `objetivo`, `dados`, `atividade`, `preferencias`, `restricoes`, `rotina`, `resumo`.
- Limites (RN09): idade 18–100 inteiro; altura 120–230 cm inteiro; peso 30,0–250,0 kg com 1 casa; sexo `feminino`/`masculino`/`nao-dizer`.
- Meta (RN10): só em `ganhar-massa`/`perder-gordura`; ganhar ⇒ meta > peso, perder ⇒ meta < peso; manter ⇒ meta = peso (`auto`); disposição ⇒ `null`; vazia ⇒ peso × 1,05/0,95 arredondado a 0,5 kg e limitado à faixa de IMC 18,5–24,9 quando o peso está nela (`suggested`); fora da faixa: aceita e avisa.
- Rotina (RN12): treino dentro da janela acordado; janela ≥ 12 h; dias 0–6 sem repetição (0 = domingo).
- Metas (RN13): Mifflin-St Jeor (+5 / −161 / −78); fatores 1,2/1,375/1,55/1,725 + trabalho 0/0,05/0,1 (máx. 1,9); ganhar +10%, perder −15%; piso 1.200 (fem./não dizer) ou 1.500 (masc.); proteína 2,0 g/kg (ganhar/perder) ou 1,6; gordura 25% ÷ 9 com mínimo 0,8 g/kg; carboidrato o resto ÷ 4 (mín. 100 g, reduzindo a gordura até o mínimo); kcal a múltiplo de 50, macros a múltiplo de 5.
- RN34: ao concluir o onboarding o peso vira a 1ª pesagem e `start_weight_kg`; depois, "Peso de hoje" grava a pesagem do dia (upsert), sem mexer no peso inicial; peso atual = pesagem mais recente.
- RN07: `GET /profile` e `PUT /profile/preferences` exigem onboarding concluído (`409 ONBOARDING_INCOMPLETE` com `details.next_step`); catálogo, onboarding, etapas e prévia não exigem.
- D12: nada de mock em produção — as listas de opções vêm do catálogo (DoD da spec); o `onboarding-store` (localStorage) é apagado.
- D11: telas refinadas com `frontend-design`, muita animação, identidade do mock; `text-musgo` só sobre `tinta`.
- Textos exatos: "Continuar", "Salvar", "Gerar meu plano", "Não foi possível salvar. Tente de novo.", "Não foi possível carregar suas respostas", "Com isso, seu plano começa em {kcal} por dia, com {proteína} g de proteína divididos em 5 refeições.", "Para {altura}, a faixa saudável vai de {min} a {max} kg.", "Essa meta fica fora da faixa saudável para a sua altura. Tudo bem seguir — vale conversar com um profissional.", "Para ganhar massa, a meta precisa ser maior que o peso de hoje.", "O treino precisa estar entre a hora que você acorda e a que dorme.", "Marque pelo menos uns 5 para o cardápio ficar com a sua cara.", "Sua meta de peso foi ajustada para o novo objetivo.", "Restrições e alergias refazem seu plano na hora. O resto entra quando você refizer o plano.", "Salvar alterações".
- Toda tabela nova com `user_id` entra no dataset de `tests/Feature/Auth/DeleteAccountTest.php`.

## Review Focus

1. **Voltar e reabrir uma etapa já salva** (CA01; "Editar" no resumo; `?editar=1` no perfil) → o formulário abre com o que foi salvo, e "Continuar" leva ao lugar certo (próxima etapa, resumo ou perfil) — testes nas Tasks 8–11.
2. **Vírgula decimal e campos vazios** ("58,4", "", "58,45", "abc") → vira número certo ou erro no campo, nunca `NaN` no corpo nem erro 500 — `regras.test.ts` (Task 6) e `EtapaDados.integration` (Task 8).
3. **Trocar o objetivo depois de informar a meta** (ganhar → perder com meta 62) → a meta incoerente some (ou vira sugerida depois do onboarding) e o usuário é avisado — `OnboardingStepsTest` (Task 3) e `EtapaObjetivo.integration` (Task 8).
4. **Sono depois da meia-noite** (acorda 10:00, dorme 01:30, treina 23:00) → aceito; treino às 05:00 com acorda 06:20 → erro RN12 — `OnboardingStepsTest` e `regras.test.ts`.
5. **Dados de outro usuário e ids forjados** (`disliked_food_ids` de alimento que não é "não curto", slug inexistente, etapa `resumo` no PATCH) → 422/404, nada gravado — `PreferencesTest`/`OnboardingStepsTest`.

---

## Mapa de arquivos

| Repo | Arquivo | Responsabilidade |
|---|---|---|
| back | `app/Enums/{Goal,Sex,ActivityLevel,WorkPosture,LunchPlace,FoodGroup,GoalWeightSource}.php` | valores fechados + rótulos do catálogo |
| back | `database/migrations/2026_09_29_0001xx_*.php` | catálogo, pivôs do usuário, `weigh_ins` |
| back | `app/Models/{Food,Restriction,PantryItem,WeighIn}.php`, `User.php` (relações) | dados |
| back | `database/seeders/{Restriction,PantryItem,Food,Catalog}Seeder.php`, `database/data/foods.csv` | catálogo de referência |
| back | `app/Services/Nutrition/{GoalWeightResolver,GoalWeight,NutritionCalculator,DailyTargets}.php` | RN10/RN11, RN13 (puros) |
| back | `app/Services/Catalog/OnboardingCatalog.php` | corpo de `GET /catalog/onboarding` |
| back | `app/Services/Profile/{ProfileService,OnboardingService}.php` | salvar etapa, preferências, concluir |
| back | `app/Http/Requests/Profile/{ProfileStepRequest,PreferencesRequest}.php`, `Concerns/ProfileMessages.php` | validação §6 |
| back | `app/Http/Resources/{OnboardingResource,ProfileResource}.php` | contrato JSON |
| back | `app/Http/Controllers/Api/V1/{CatalogController,OnboardingController,ProfileController,PreferencesController,PlanController}.php` | rotas |
| back | `app/Http/Middleware/EnsureOnboardingCompleted.php` | RN07 (`onboarded`) |
| front | `src/features/onboarding/{tipos,etapas,regras,hooks,useEtapa}.ts`, `src/lib/chaves.ts` | domínio do onboarding |
| front | `src/lib/api/{onboarding,perfil}.ts` | chamadas |
| front | `src/components/app/OnboardingStep.tsx` | casca da etapa |
| front | `src/features/onboarding/components/*` | etapas, `GoalWeightField`, `SummaryList`, `PreviaDeMetas` |
| front | `src/features/perfil/{hooks.ts,components/*}` | `PerfilTela`, `GoalCard`, `PreferenciasTela` |
| front | `src/mocks/{fixtures/onboarding.ts,handlers/onboarding.ts}` | MSW |
| front | `e2e/{cadastro,retomar}.spec.ts` | E2E-01 (até "Gerando"), E2E-03 |

---

### Task 1: Catálogo — enums, tabelas, models e seeders (backend)

**Files:**
- Create: `app/Enums/{Goal,Sex,ActivityLevel,WorkPosture,LunchPlace,FoodGroup,GoalWeightSource}.php`
- Create: `database/migrations/2026_09_29_000100_create_catalog_tables.php`, `2026_09_29_000200_create_user_choice_tables.php`, `2026_09_29_000300_create_weigh_ins_table.php`
- Create: `app/Models/{Food,Restriction,PantryItem,WeighIn}.php`; Modify: `app/Models/User.php`
- Create: `database/seeders/{RestrictionSeeder,PantryItemSeeder,FoodSeeder,CatalogSeeder}.php`, `database/data/foods.csv`; Modify: `database/seeders/DatabaseSeeder.php`
- Modify: `tests/Pest.php`, `tests/Feature/Auth/DeleteAccountTest.php`
- Test: `tests/Feature/Catalog/CatalogSeedersTest.php`

**Interfaces:**
- Produces:
  - Enums string-backed: `Goal` (`GanharMassa='ganhar-massa'`, `PerderGordura`, `ManterPeso`, `MaisDisposicao`; `label()`, `description()`, `asksGoalWeight(): bool`), `Sex` (`Feminino`, `Masculino`, `NaoDizer='nao-dizer'`), `ActivityLevel` (`Parado`, `Leve`, `Moderado`, `Intenso`; `label()`, `description()`, `factor(): float`), `WorkPosture` (`Sentada`, `EmPe='em-pe'`, `PesoPesado='peso-pesado'`; `label()`, `bonus(): float`), `LunchPlace` (`Casa`, `Marmita`, `Restaurante`; `label()`), `FoodGroup`, `GoalWeightSource` (`User='user'`, `Suggested`, `Auto`).
  - Models `Food`, `Restriction`, `PantryItem` (const `CATEGORIES` slug → rótulo), `WeighIn`; em `User`: `restrictions()`, `pantryItems()`, `dislikedFoods()`, `weighIns()`, `latestWeighIn()`, `currentWeightKg(): ?float`.
  - `CatalogSeeder` (chama os três na ordem). Helper de teste `seedCatalog()` em `tests/Pest.php`.

- [ ] **Step 1: Branch e teste do catálogo que deve falhar**

```bash
cd /home/alvez/atividade-extensionista/backend
git switch plano-02-front && git switch -c plano-03-onboarding
mkdir -p tests/Feature/Catalog database/data
```

Em `tests/Pest.php`, acrescentar ao fim:
```php
/** Catálogo de referência (restrições, cozinha e alimentos), como em produção. */
function seedCatalog(): void
{
    test()->seed(\Database\Seeders\CatalogSeeder::class);
}
```

`tests/Feature/Catalog/CatalogSeedersTest.php`:
```php
<?php

use App\Models\Food;
use App\Models\PantryItem;
use App\Models\Restriction;
use Database\Seeders\CatalogSeeder;

beforeEach(fn () => seedCatalog());

it('semeia as 6 restrições, com castanhas e frutos do mar como alergia', function () {
    expect(Restriction::orderBy('position')->pluck('slug')->all())
        ->toBe(['lactose', 'gluten', 'castanhas', 'frutos-do-mar', 'sem-carne', 'sem-animal'])
        ->and(Restriction::where('is_allergy', true)->pluck('slug')->sort()->values()->all())
        ->toBe(['castanhas', 'frutos-do-mar']);
});

it('semeia os 17 itens de cozinha nas 3 categorias', function () {
    expect(PantryItem::count())->toBe(17)
        ->and(PantryItem::distinct()->orderBy('category')->pluck('category')->all())->toBe(['carboidratos', 'frutas', 'proteinas']);
});

it('liga cada item de cozinha a pelo menos um alimento', function () {
    expect(PantryItem::doesntHave('foods')->pluck('slug')->all())->toBe([]);
});

it('liga cada restrição a pelo menos um alimento que ela exclui', function () {
    expect(Restriction::doesntHave('foods')->pluck('slug')->all())->toBe([]);
});

it('tem todos os alimentos do plano de exemplo do mock', function (string $name) {
    expect(Food::where('name', $name)->exists())->toBeTrue("falta {$name}");
})->with([
    'Arroz branco cozido', 'Arroz integral', 'Atum em lata, na água', 'Aveia em flocos', 'Azeite de oliva',
    'Banana', 'Batata-doce cozida', 'Brócolis no vapor', 'Café sem açúcar', 'Cuscuz de milho', 'Feijão carioca',
    'Frango grelhado', 'Iogurte natural', 'Macarrão parafuso', 'Mamão', 'Ovos cozidos', 'Ovos mexidos',
    'Patinho moído', 'Pão francês', 'Queijo minas', 'Salada de alface e tomate', 'Tapioca',
]);

it('marca a lista "prefiro não ver" do mock', function () {
    expect(Food::where('common_dislike', true)->orderBy('name')->pluck('name')->all())
        ->toBe(['Berinjela', 'Beterraba', 'Fígado bovino', 'Jiló', 'Peixe assado']);
});

it('tira de "sem origem animal" tudo que "sem carne" tira', function () {
    $semCarne = Restriction::where('slug', 'sem-carne')->sole()->foods()->pluck('foods.id');
    $semAnimal = Restriction::where('slug', 'sem-animal')->sole()->foods()->pluck('foods.id');

    expect($semCarne->diff($semAnimal)->all())->toBe([]);
});

it('tem pelo menos 3 opções ativas por grupo principal', function (string $group) {
    expect(Food::where('group', $group)->where('is_active', true)->count())->toBeGreaterThanOrEqual(3);
})->with(['proteina', 'carboidrato', 'leguminosa', 'fruta', 'vegetal']);

it('pode rodar de novo sem duplicar nada', function () {
    $antes = [Food::count(), Restriction::count(), PantryItem::count()];

    $this->seed(CatalogSeeder::class);

    expect([Food::count(), Restriction::count(), PantryItem::count()])->toBe($antes);
});
```

Run: `docker compose run --rm api php artisan test tests/Feature/Catalog`
Expected: FAIL — `Class "Database\Seeders\CatalogSeeder" not found`.

- [ ] **Step 2: Enums**

`app/Enums/Goal.php`:
```php
<?php

namespace App\Enums;

enum Goal: string
{
    case GanharMassa = 'ganhar-massa';
    case PerderGordura = 'perder-gordura';
    case ManterPeso = 'manter-peso';
    case MaisDisposicao = 'mais-disposicao';

    public function label(): string
    {
        return match ($this) {
            self::GanharMassa => 'Ganhar massa magra',
            self::PerderGordura => 'Perder gordura',
            self::ManterPeso => 'Manter o peso',
            self::MaisDisposicao => 'Ter mais disposição',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::GanharMassa => 'Comer um pouco acima do gasto, com proteína alta todo dia.',
            self::PerderGordura => 'Déficit leve, mantendo a força nos treinos.',
            self::ManterPeso => 'Organizar os horários e equilibrar o que você já come.',
            self::MaisDisposicao => 'Energia para o treino sem chegar arrastada no fim do dia.',
        };
    }

    /** RN10: só ganhar e perder pedem meta de peso. */
    public function asksGoalWeight(): bool
    {
        return $this === self::GanharMassa || $this === self::PerderGordura;
    }
}
```

`app/Enums/Sex.php`:
```php
<?php

namespace App\Enums;

enum Sex: string
{
    case Feminino = 'feminino';
    case Masculino = 'masculino';
    case NaoDizer = 'nao-dizer';
}
```

`app/Enums/ActivityLevel.php`:
```php
<?php

namespace App\Enums;

enum ActivityLevel: string
{
    case Parado = 'parado';
    case Leve = 'leve';
    case Moderado = 'moderado';
    case Intenso = 'intenso';

    public function label(): string
    {
        return match ($this) {
            self::Parado => 'Quase não treino',
            self::Leve => '1 ou 2 vezes na semana',
            self::Moderado => '3 ou 4 vezes na semana',
            self::Intenso => '5 ou 6 vezes na semana',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Parado => 'Menos de um treino por semana.',
            self::Leve => 'Musculação leve ou caminhada.',
            self::Moderado => 'O ritmo da maior parte do pessoal da Zfit.',
            self::Intenso => 'Treino puxado quase todo dia.',
        };
    }

    /** RN13, passo 2. */
    public function factor(): float
    {
        return match ($this) {
            self::Parado => 1.2,
            self::Leve => 1.375,
            self::Moderado => 1.55,
            self::Intenso => 1.725,
        };
    }
}
```

`app/Enums/WorkPosture.php`:
```php
<?php

namespace App\Enums;

enum WorkPosture: string
{
    case Sentada = 'sentada';
    case EmPe = 'em-pe';
    case PesoPesado = 'peso-pesado';

    public function label(): string
    {
        return match ($this) {
            self::Sentada => 'Sentada',
            self::EmPe => 'Em pé',
            self::PesoPesado => 'Peso pesado',
        };
    }

    /** RN13, passo 2: ajuste do trabalho somado ao fator de atividade. */
    public function bonus(): float
    {
        return match ($this) {
            self::Sentada => 0.0,
            self::EmPe => 0.05,
            self::PesoPesado => 0.1,
        };
    }
}
```

`app/Enums/LunchPlace.php`:
```php
<?php

namespace App\Enums;

enum LunchPlace: string
{
    case Casa = 'casa';
    case Marmita = 'marmita';
    case Restaurante = 'restaurante';

    public function label(): string
    {
        return match ($this) {
            self::Casa => 'Em casa',
            self::Marmita => 'Marmita no trabalho',
            self::Restaurante => 'Restaurante',
        };
    }
}
```

`app/Enums/FoodGroup.php`:
```php
<?php

namespace App\Enums;

enum FoodGroup: string
{
    case Proteina = 'proteina';
    case Carboidrato = 'carboidrato';
    case Leguminosa = 'leguminosa';
    case Laticinio = 'laticinio';
    case Fruta = 'fruta';
    case Vegetal = 'vegetal';
    case Gordura = 'gordura';
    case Bebida = 'bebida';
    case Outros = 'outros';
}
```

`app/Enums/GoalWeightSource.php`:
```php
<?php

namespace App\Enums;

/** De onde veio a meta de peso (RN10). */
enum GoalWeightSource: string
{
    case User = 'user';
    case Suggested = 'suggested';
    case Auto = 'auto';
}
```

- [ ] **Step 3: Migrations**

`database/migrations/2026_09_29_000100_create_catalog_tables.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('foods', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 80)->unique();
            $table->string('name', 120);
            $table->json('aliases');
            $table->string('group', 20);
            $table->decimal('kcal_per_100g', 6, 1);
            $table->decimal('protein_per_100g', 5, 1);
            $table->decimal('carbs_per_100g', 5, 1);
            $table->decimal('fat_per_100g', 5, 1);
            $table->decimal('typical_portion_g', 6, 1);
            $table->string('unit_label', 40)->nullable();
            $table->string('unit_label_plural', 40)->nullable();
            $table->decimal('unit_grams', 6, 1)->nullable();
            $table->string('substitution_note', 120)->nullable();
            $table->boolean('common_dislike')->default(false);
            $table->boolean('is_staple')->default(false);
            $table->boolean('is_active')->default(true);
            $table->string('source', 40);
            $table->timestamps();
            $table->index(['group', 'is_active']);
        });
        DB::statement('ALTER TABLE foods ADD CONSTRAINT foods_macros_nao_negativos CHECK (kcal_per_100g >= 0 AND protein_per_100g >= 0 AND carbs_per_100g >= 0 AND fat_per_100g >= 0)');

        Schema::create('restrictions', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 30)->unique();
            $table->string('label', 60);
            $table->boolean('is_allergy')->default(false);
            $table->unsignedTinyInteger('position');
            $table->timestamps();
        });

        Schema::create('pantry_items', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 40)->unique();
            $table->string('label', 60);
            $table->string('category', 20);
            $table->unsignedTinyInteger('position');
            $table->timestamps();
        });

        Schema::create('food_restriction', function (Blueprint $table) {
            $table->foreignId('food_id')->constrained()->cascadeOnDelete();
            $table->foreignId('restriction_id')->constrained()->cascadeOnDelete();
            $table->primary(['food_id', 'restriction_id']);
        });

        Schema::create('food_pantry_item', function (Blueprint $table) {
            $table->foreignId('food_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pantry_item_id')->constrained()->cascadeOnDelete();
            $table->primary(['food_id', 'pantry_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('food_pantry_item');
        Schema::dropIfExists('food_restriction');
        Schema::dropIfExists('pantry_items');
        Schema::dropIfExists('restrictions');
        Schema::dropIfExists('foods');
    }
};
```

`database/migrations/2026_09_29_000200_create_user_choice_tables.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restriction_user', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('restriction_id')->constrained()->cascadeOnDelete();
            $table->primary(['user_id', 'restriction_id']);
        });

        Schema::create('pantry_item_user', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pantry_item_id')->constrained()->cascadeOnDelete();
            $table->primary(['user_id', 'pantry_item_id']);
        });

        Schema::create('disliked_food_user', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('food_id')->constrained()->cascadeOnDelete();
            $table->primary(['user_id', 'food_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('disliked_food_user');
        Schema::dropIfExists('pantry_item_user');
        Schema::dropIfExists('restriction_user');
    }
};
```

`database/migrations/2026_09_29_000300_create_weigh_ins_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('weigh_ins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->decimal('weight_kg', 5, 1);
            $table->timestamps();
            $table->unique(['user_id', 'date']); // RN34: uma pesagem por dia
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('weigh_ins');
    }
};
```

- [ ] **Step 4: Models**

`app/Models/Food.php`:
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Alimento do catálogo (valores por 100 g).
 *
 * @property list<string> $aliases
 */
class Food extends Model
{
    protected $fillable = [
        'slug', 'name', 'aliases', 'group', 'kcal_per_100g', 'protein_per_100g', 'carbs_per_100g', 'fat_per_100g',
        'typical_portion_g', 'unit_label', 'unit_label_plural', 'unit_grams', 'substitution_note',
        'common_dislike', 'is_staple', 'is_active', 'source',
    ];

    protected function casts(): array
    {
        return [
            'aliases' => 'array',
            'kcal_per_100g' => 'float',
            'protein_per_100g' => 'float',
            'carbs_per_100g' => 'float',
            'fat_per_100g' => 'float',
            'typical_portion_g' => 'float',
            'unit_grams' => 'float',
            'common_dislike' => 'boolean',
            'is_staple' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /** Restrições que excluem este alimento. @return BelongsToMany<Restriction, $this> */
    public function restrictions(): BelongsToMany
    {
        return $this->belongsToMany(Restriction::class);
    }

    /** @return BelongsToMany<PantryItem, $this> */
    public function pantryItems(): BelongsToMany
    {
        return $this->belongsToMany(PantryItem::class);
    }
}
```

`app/Models/Restriction.php`:
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Restriction extends Model
{
    protected $fillable = ['slug', 'label', 'is_allergy', 'position'];

    protected function casts(): array
    {
        return ['is_allergy' => 'boolean'];
    }

    /** Alimentos que esta restrição exclui. @return BelongsToMany<Food, $this> */
    public function foods(): BelongsToMany
    {
        return $this->belongsToMany(Food::class);
    }
}
```

`app/Models/PantryItem.php`:
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class PantryItem extends Model
{
    /** Categorias na ordem de exibição, com o título de cada grupo. */
    public const CATEGORIES = [
        'proteinas' => 'Proteínas',
        'carboidratos' => 'Carboidratos',
        'frutas' => 'Frutas',
    ];

    protected $fillable = ['slug', 'label', 'category', 'position'];

    /** @return BelongsToMany<Food, $this> */
    public function foods(): BelongsToMany
    {
        return $this->belongsToMany(Food::class);
    }
}
```

`app/Models/WeighIn.php`:
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Pesagem do dia (RN34). */
class WeighIn extends Model
{
    protected $fillable = ['date', 'weight_kg'];

    protected function casts(): array
    {
        return ['date' => 'date', 'weight_kg' => 'float'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
```

Em `app/Models/User.php`: acrescentar aos imports `use Illuminate\Database\Eloquent\Relations\BelongsToMany;` e `use Illuminate\Database\Eloquent\Relations\HasMany;`, e os métodos (junto de `profile()`/`settings()`):
```php
    /** Restrições e alergias marcadas. @return BelongsToMany<Restriction, $this> */
    public function restrictions(): BelongsToMany
    {
        return $this->belongsToMany(Restriction::class)->orderBy('position');
    }

    /** Itens da cozinha. @return BelongsToMany<PantryItem, $this> */
    public function pantryItems(): BelongsToMany
    {
        return $this->belongsToMany(PantryItem::class)->orderBy('position');
    }

    /** "Prefiro não ver no cardápio" (só alimentos `common_dislike`). @return BelongsToMany<Food, $this> */
    public function dislikedFoods(): BelongsToMany
    {
        return $this->belongsToMany(Food::class, 'disliked_food_user')->orderBy('name');
    }

    /** @return HasMany<WeighIn, $this> */
    public function weighIns(): HasMany
    {
        return $this->hasMany(WeighIn::class);
    }

    /** @return HasOne<WeighIn, $this> */
    public function latestWeighIn(): HasOne
    {
        return $this->hasOne(WeighIn::class)->latestOfMany('date');
    }

    /** RN34: peso atual = pesagem mais recente; antes da primeira, o peso informado no onboarding. */
    public function currentWeightKg(): ?float
    {
        $latest = $this->latestWeighIn;
        if ($latest !== null) {
            return $latest->weight_kg;
        }

        $start = $this->profile?->start_weight_kg;

        return $start === null ? null : (float) $start;
    }
```

- [ ] **Step 5: Seeders e `foods.csv`**

`database/seeders/RestrictionSeeder.php`:
```php
<?php

namespace Database\Seeders;

use App\Models\Restriction;
use Illuminate\Database\Seeder;

/** As 6 restrições do mock (`lib/labels.ts`), com as alergias marcadas. Idempotente. */
class RestrictionSeeder extends Seeder
{
    private const RESTRICTIONS = [
        ['lactose', 'Intolerância a lactose', false],
        ['gluten', 'Glúten', false],
        ['castanhas', 'Amendoim e castanhas', true],
        ['frutos-do-mar', 'Frutos do mar', true],
        ['sem-carne', 'Não como carne', false],
        ['sem-animal', 'Não como nada de origem animal', false],
    ];

    public function run(): void
    {
        foreach (self::RESTRICTIONS as $i => [$slug, $label, $isAllergy]) {
            Restriction::updateOrCreate(['slug' => $slug], ['label' => $label, 'is_allergy' => $isAllergy, 'position' => $i + 1]);
        }
    }
}
```

`database/seeders/PantryItemSeeder.php`:
```php
<?php

namespace Database\Seeders;

use App\Models\PantryItem;
use Illuminate\Database\Seeder;

/** Os 17 itens de "O que costuma ter na sua cozinha" (união das listas do mock). Idempotente. */
class PantryItemSeeder extends Seeder
{
    private const ITEMS = [
        ['ovos', 'Ovos', 'proteinas'],
        ['frango', 'Frango', 'proteinas'],
        ['carne-moida', 'Carne moída', 'proteinas'],
        ['peixe', 'Peixe', 'proteinas'],
        ['iogurte', 'Iogurte', 'proteinas'],
        ['queijo', 'Queijo', 'proteinas'],
        ['arroz-e-feijao', 'Arroz e feijão', 'carboidratos'],
        ['batata-doce', 'Batata-doce', 'carboidratos'],
        ['tapioca', 'Tapioca', 'carboidratos'],
        ['macarrao', 'Macarrão', 'carboidratos'],
        ['cuscuz', 'Cuscuz', 'carboidratos'],
        ['pao-frances', 'Pão francês', 'carboidratos'],
        ['aveia', 'Aveia', 'carboidratos'],
        ['banana', 'Banana', 'frutas'],
        ['mamao', 'Mamão', 'frutas'],
        ['maca', 'Maçã', 'frutas'],
        ['laranja', 'Laranja', 'frutas'],
    ];

    public function run(): void
    {
        foreach (self::ITEMS as $i => [$slug, $label, $category]) {
            PantryItem::updateOrCreate(['slug' => $slug], ['label' => $label, 'category' => $category, 'position' => $i + 1]);
        }
    }
}
```

`database/seeders/FoodSeeder.php`:
```php
<?php

namespace Database\Seeders;

use App\Models\Food;
use App\Models\PantryItem;
use App\Models\Restriction;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use RuntimeException;
use SplFileObject;

/**
 * Alimentos de `database/data/foods.csv` (valores por 100 g; fonte em cada linha — pendência P5).
 * Idempotente: atualiza pelo slug e ressincroniza as restrições e os itens de cozinha.
 * Rode depois de RestrictionSeeder e PantryItemSeeder (use CatalogSeeder).
 */
class FoodSeeder extends Seeder
{
    public function run(): void
    {
        $restrictions = Restriction::pluck('id', 'slug');
        $pantry = PantryItem::pluck('id', 'slug');

        foreach ($this->rows() as $row) {
            $food = Food::updateOrCreate(['slug' => $row['slug']], [
                'name' => $row['name'],
                'aliases' => $this->list($row['aliases']),
                'group' => $row['group'],
                'kcal_per_100g' => $row['kcal'],
                'protein_per_100g' => $row['protein'],
                'carbs_per_100g' => $row['carbs'],
                'fat_per_100g' => $row['fat'],
                'typical_portion_g' => $row['portion_g'],
                'unit_label' => $row['unit'] !== '' ? $row['unit'] : null,
                'unit_label_plural' => $row['unit_plural'] !== '' ? $row['unit_plural'] : null,
                'unit_grams' => $row['unit_g'] !== '' ? $row['unit_g'] : null,
                'substitution_note' => $row['note'] !== '' ? $row['note'] : null,
                'common_dislike' => $row['dislike'] === '1',
                'is_staple' => $row['staple'] === '1',
                'is_active' => true,
                'source' => $row['source'],
            ]);

            $food->restrictions()->sync($this->ids($restrictions, $this->list($row['restrictions']), $food->slug));
            $food->pantryItems()->sync($this->ids($pantry, $this->list($row['pantry']), $food->slug));
        }
    }

    /** @return list<array<string, string>> */
    private function rows(): array
    {
        $file = new SplFileObject(database_path('data/foods.csv'));
        $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::READ_AHEAD | SplFileObject::DROP_NEW_LINE);

        $header = null;
        $rows = [];
        foreach ($file as $line) {
            /** @var list<string> $line */
            if ($header === null) {
                $header = $line;

                continue;
            }
            $rows[] = array_combine($header, $line);
        }

        return $rows;
    }

    /** "a|b" → ["a", "b"] @return list<string> */
    private function list(string $value): array
    {
        return array_values(array_filter(explode('|', $value), fn (string $item) => $item !== ''));
    }

    /**
     * @param  Collection<string, int>  $known
     * @param  list<string>  $slugs
     * @return list<int>
     */
    private function ids(Collection $known, array $slugs, string $food): array
    {
        return array_map(
            fn (string $slug) => $known[$slug] ?? throw new RuntimeException("foods.csv: '{$slug}' desconhecido em {$food}"),
            $slugs,
        );
    }
}
```

`database/seeders/CatalogSeeder.php`:
```php
<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/** Dados de referência de todos os ambientes, inclusive produção. */
class CatalogSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([RestrictionSeeder::class, PantryItemSeeder::class, FoodSeeder::class]);
    }
}
```

`database/seeders/DatabaseSeeder.php` — trocar o corpo de `run()` por:
```php
        $this->call(CatalogSeeder::class);
```
(e remover o `use App\Models\User;` que fica sem uso).

`database/data/foods.csv` (valores por 100 g; `restrictions`/`pantry` separados por `|`):
```csv
slug,name,aliases,group,kcal,protein,carbs,fat,portion_g,unit,unit_plural,unit_g,note,dislike,staple,source,restrictions,pantry
ovos-cozidos,Ovos cozidos,ovo|ovos|ovo cozido,proteina,146,13.3,0.6,9.5,100,unidade,unidades,50,"Proteína completa, pronta em 10 minutos",0,0,TACO 4ª ed.,sem-animal,ovos
ovos-mexidos,Ovos mexidos,ovo|ovos|ovo mexido,proteina,168,12.9,0.6,12.1,100,unidade,unidades,50,,0,0,Receita caseira (TACO),sem-animal,ovos
frango-grelhado,Frango grelhado,frango|peito de frango,proteina,159,32.0,0.0,2.5,120,filé médio,filés médios,100,Magro e rende bem na marmita,0,0,TACO 4ª ed.,sem-carne|sem-animal,frango
frango-desfiado,Frango desfiado,frango|peito de frango,proteina,163,31.5,0.0,3.2,100,colher de sopa,colheres de sopa,20,Combina com cuscuz e tapioca,0,0,TACO 4ª ed.,sem-carne|sem-animal,frango
coxa-de-frango-assada,Coxa de frango assada,frango|coxa|sobrecoxa,proteina,215,28.5,0.0,10.4,100,coxa,coxas,70,,0,0,TACO 4ª ed.,sem-carne|sem-animal,frango
patinho-moido,Patinho moído,carne moída|carne|patinho,proteina,219,35.9,0.0,7.3,100,colher de sopa,colheres de sopa,25,Carne magra que combina com arroz e feijão,0,0,TACO 4ª ed.,sem-carne|sem-animal,carne-moida
carne-moida-refogada,Carne moída refogada,carne moída|carne|acém,proteina,212,26.7,0.0,10.9,100,colher de sopa,colheres de sopa,25,,0,0,TACO 4ª ed.,sem-carne|sem-animal,carne-moida
bife-de-alcatra,Bife de alcatra grelhado,carne|bife|alcatra,proteina,241,31.9,0.0,11.6,100,bife médio,bifes médios,100,,0,0,TACO 4ª ed.,sem-carne|sem-animal,
peixe-assado,Peixe assado,peixe|merluza|filé de peixe,proteina,122,26.6,0.0,0.9,120,filé,filés,120,Leve para a noite,1,0,TACO 4ª ed.,sem-carne|sem-animal,peixe
sardinha-em-lata,Sardinha em lata,sardinha|peixe,proteina,285,15.9,0.0,24.0,60,lata,latas,84,Barata e cheia de ômega-3,0,0,TACO 4ª ed.,sem-carne|sem-animal,peixe
atum-em-lata,"Atum em lata, na água",atum|peixe,proteina,116,26.0,0.0,1.0,60,lata,latas,120,"Prático, não precisa de fogão",0,0,Rótulo,sem-carne|sem-animal,peixe
camarao-cozido,Camarão cozido,camarão|frutos do mar,proteina,90,19.0,0.0,1.0,100,,,,,0,0,TACO 4ª ed.,frutos-do-mar|sem-carne|sem-animal,
figado-bovino,Fígado bovino,fígado|figado,proteina,225,29.9,4.2,9.0,100,bife,bifes,100,,1,0,TACO 4ª ed.,sem-carne|sem-animal,
tofu,Tofu,tofu|queijo de soja,proteina,76,8.1,1.9,4.8,100,fatia,fatias,50,Proteína de planta que vai bem grelhada,0,0,Tabela USDA,,
queijo-minas,Queijo minas,queijo|queijo branco|minas frescal,laticinio,264,17.4,3.2,20.2,30,fatia,fatias,30,,0,0,TACO 4ª ed.,lactose|sem-animal,queijo
queijo-mucarela,Queijo muçarela,queijo|mussarela|mozarela,laticinio,330,22.6,3.0,25.2,30,fatia,fatias,15,,0,0,TACO 4ª ed.,lactose|sem-animal,queijo
iogurte-natural,Iogurte natural,iogurte,laticinio,51,4.1,1.9,3.0,170,pote,potes,170,Segura a fome até o almoço,0,0,TACO 4ª ed.,lactose|sem-animal,iogurte
leite-integral,Leite integral,leite|leite de vaca,laticinio,61,2.9,4.3,3.2,200,copo,copos,200,,0,0,TACO 4ª ed.,lactose|sem-animal,
requeijao-cremoso,Requeijão cremoso,requeijão,laticinio,257,9.6,2.4,23.4,20,colher de sopa,colheres de sopa,20,,0,0,TACO 4ª ed.,lactose|sem-animal,
feijao-carioca,Feijão carioca,feijão,leguminosa,76,4.8,13.6,0.5,86,concha,conchas,86,,0,0,TACO 4ª ed.,,arroz-e-feijao
feijao-preto,Feijão preto,feijão,leguminosa,77,4.5,14.0,0.5,86,concha,conchas,86,,0,0,TACO 4ª ed.,,arroz-e-feijao
lentilha-cozida,Lentilha cozida,lentilha,leguminosa,93,6.3,16.3,0.5,90,concha,conchas,90,Troca o feijão sem perder proteína,0,0,TACO 4ª ed.,,
grao-de-bico-cozido,Grão-de-bico cozido,grão de bico|grão-de-bico,leguminosa,164,8.9,27.4,2.6,100,colher de sopa,colheres de sopa,22,Proteína de planta que substitui a carne,0,0,Tabela USDA,,
amendoim-torrado,Amendoim torrado,amendoim,gordura,606,22.5,18.7,54.0,20,punhado,punhados,20,,0,0,TACO 4ª ed.,castanhas,
pasta-de-amendoim,Pasta de amendoim,amendoim|pasta de amendoim,gordura,588,25.0,20.0,50.0,15,colher de sopa,colheres de sopa,15,,0,0,Rótulo,castanhas,
castanha-do-para,Castanha-do-pará,castanha|castanha do brasil,gordura,643,14.5,15.1,63.5,10,unidade,unidades,4,,0,0,TACO 4ª ed.,castanhas,
castanha-de-caju,Castanha de caju,castanha|caju,gordura,570,18.5,29.1,46.3,20,punhado,punhados,20,,0,0,TACO 4ª ed.,castanhas,
arroz-branco-cozido,Arroz branco cozido,arroz,carboidrato,128,2.5,28.1,0.2,150,colher de sopa,colheres de sopa,25,,0,0,TACO 4ª ed.,,arroz-e-feijao
arroz-integral,Arroz integral,arroz|arroz integral,carboidrato,124,2.6,25.8,1.0,150,colher de sopa,colheres de sopa,25,Mais fibra segura a fome até o treino,0,0,TACO 4ª ed.,,arroz-e-feijao
batata-doce-cozida,Batata-doce cozida,batata doce|batata-doce,carboidrato,77,0.6,18.4,0.1,150,unidade média,unidades médias,150,Energia que dura até o treino,0,0,TACO 4ª ed.,,batata-doce
batata-cozida,Batata cozida,batata|batata inglesa,carboidrato,52,1.2,11.9,0.0,150,unidade média,unidades médias,140,,0,0,TACO 4ª ed.,,
aipim-cozido,Aipim cozido,aipim|mandioca|macaxeira,carboidrato,125,0.6,30.1,0.3,100,pedaço,pedaços,50,,0,0,TACO 4ª ed.,,
tapioca,Tapioca,tapioca|goma de tapioca|beiju,carboidrato,240,0.0,60.0,0.0,60,tapioca média,tapiocas médias,60,Sem glúten e pronta em 3 minutos,0,0,Rótulo,,tapioca
macarrao-parafuso,Macarrão parafuso,macarrão|massa,carboidrato,158,5.8,30.9,0.9,150,pegador,pegadores,50,,0,0,Tabela USDA,gluten,macarrao
cuscuz-de-milho,Cuscuz de milho,cuscuz|cuscuz nordestino,carboidrato,113,2.2,25.3,0.7,120,fatia,fatias,60,,0,0,TACO 4ª ed.,,cuscuz
pao-frances,Pão francês,pão|pão de sal|cacetinho,carboidrato,300,8.0,58.6,3.1,50,unidade,unidades,50,,0,0,TACO 4ª ed.,gluten,pao-frances
pao-integral,Pão integral,pão|pão de forma integral,carboidrato,253,9.4,49.9,3.7,50,fatia,fatias,25,,0,0,TACO 4ª ed.,gluten,
aveia-em-flocos,Aveia em flocos,aveia,carboidrato,394,13.9,66.6,8.5,30,colher de sopa,colheres de sopa,15,Dá liga no iogurte e na fruta,0,0,TACO 4ª ed.,gluten,aveia
milho-verde,Milho verde,milho,carboidrato,98,3.2,17.1,2.4,70,colher de sopa,colheres de sopa,24,,0,0,TACO 4ª ed.,,
banana,Banana,banana|banana prata,fruta,98,1.3,26.0,0.1,60,unidade,unidades,60,Energia rápida antes do treino,0,0,TACO 4ª ed.,,banana
mamao,Mamão,mamão|papaia,fruta,40,0.5,10.4,0.1,150,fatia,fatias,150,,0,0,TACO 4ª ed.,,mamao
maca,Maçã,maçã,fruta,56,0.3,15.2,0.0,130,unidade,unidades,130,,0,0,TACO 4ª ed.,,maca
laranja,Laranja,laranja|laranja pera,fruta,37,1.0,8.9,0.1,140,unidade,unidades,140,,0,0,TACO 4ª ed.,,laranja
abacate,Abacate,abacate,fruta,96,1.2,6.0,8.4,80,colher de sopa,colheres de sopa,40,,0,0,TACO 4ª ed.,,
melancia,Melancia,melancia,fruta,33,0.9,8.1,0.0,200,fatia,fatias,200,,0,0,TACO 4ª ed.,,
morango,Morango,morango,fruta,30,0.9,6.8,0.3,100,unidade,unidades,12,,0,0,TACO 4ª ed.,,
abacaxi,Abacaxi,abacaxi,fruta,48,0.9,12.3,0.1,100,fatia,fatias,75,,0,0,TACO 4ª ed.,,
manga,Manga,manga,fruta,51,0.9,12.8,0.2,140,fatia,fatias,70,,0,0,TACO 4ª ed.,,
salada-de-alface-e-tomate,Salada de alface e tomate,salada|alface|tomate,vegetal,13,1.1,2.4,0.2,100,,,,,0,1,Receita caseira (TACO),,
brocolis-no-vapor,Brócolis no vapor,brócolis,vegetal,25,2.1,4.4,0.5,80,ramo,ramos,20,,0,0,TACO 4ª ed.,,
cenoura-cozida,Cenoura cozida,cenoura,vegetal,30,0.8,6.7,0.2,80,colher de sopa,colheres de sopa,25,,0,0,TACO 4ª ed.,,
abobrinha-cozida,Abobrinha cozida,abobrinha,vegetal,15,1.1,3.0,0.2,80,colher de sopa,colheres de sopa,25,,0,0,TACO 4ª ed.,,
chuchu-cozido,Chuchu cozido,chuchu,vegetal,19,0.4,4.8,0.0,80,colher de sopa,colheres de sopa,25,,0,0,TACO 4ª ed.,,
couve-refogada,Couve refogada,couve,vegetal,90,1.7,8.7,6.6,40,colher de sopa,colheres de sopa,20,,0,0,TACO 4ª ed.,,
beterraba,Beterraba,beterraba,vegetal,32,1.3,7.2,0.1,60,colher de sopa,colheres de sopa,20,,1,0,TACO 4ª ed.,,
jilo,Jiló,jiló|jilo,vegetal,27,1.4,6.2,0.2,60,unidade,unidades,30,,1,0,TACO 4ª ed.,,
berinjela,Berinjela,berinjela,vegetal,19,0.7,4.5,0.1,80,colher de sopa,colheres de sopa,25,,1,0,TACO 4ª ed.,,
pepino,Pepino,pepino,vegetal,10,0.9,2.0,0.0,60,fatia,fatias,10,,0,0,TACO 4ª ed.,,
azeite-de-oliva,Azeite de oliva,azeite,gordura,884,0.0,0.0,100.0,5,colher de chá,colheres de chá,5,,0,1,TACO 4ª ed.,,
manteiga,Manteiga,manteiga,gordura,726,0.4,0.1,82.4,5,colher de chá,colheres de chá,5,,0,0,TACO 4ª ed.,lactose|sem-animal,
cafe-sem-acucar,Café sem açúcar,café|cafezinho,bebida,9,0.7,1.5,0.1,100,xícara,xícaras,100,,0,1,TACO 4ª ed.,,
mel,Mel,mel,outros,309,0.0,84.0,0.0,15,colher de sopa,colheres de sopa,15,,0,0,TACO 4ª ed.,sem-animal,
```

- [ ] **Step 6: Rodar e ver passar**

Run: `docker compose run --rm api php artisan test tests/Feature/Catalog`
Expected: PASS (todos os casos, incluindo os 22 alimentos do mock e os 5 grupos).

- [ ] **Step 7: Exclusão da conta leva as tabelas novas (RN06) — teste que deve falhar**

Em `tests/Feature/Auth/DeleteAccountTest.php`, acrescentar ao dataset `'tabelas do usuário'`:
```php
    'weigh_ins' => ['weigh_ins', 'user_id'],
    'restriction_user' => ['restriction_user', 'user_id'],
    'pantry_item_user' => ['pantry_item_user', 'user_id'],
    'disliked_food_user' => ['disliked_food_user', 'user_id'],
```
e, no teste `'não deixa nenhuma linha do usuário para trás (CA08)'`, logo depois de `Password::createToken($user);`, criar linhas em todas elas (o teste precisa ter o que apagar):
```php
    seedCatalog();
    $user->restrictions()->attach(\App\Models\Restriction::first());
    $user->pantryItems()->attach(\App\Models\PantryItem::first());
    $user->dislikedFoods()->attach(\App\Models\Food::where('common_dislike', true)->first());
    $user->weighIns()->create(['date' => today(), 'weight_kg' => 58.4]);
```
Run: `docker compose run --rm api php artisan test tests/Feature/Auth/DeleteAccountTest.php`
Expected: PASS (as FKs têm `cascadeOnDelete`). Para provar que o teste pega o problema, troque temporariamente `->cascadeOnDelete()` de `weigh_ins.user_id` por nada, rode `php artisan migrate:fresh` no teste (o `RefreshDatabase` recria) e veja FAIL em `weigh_ins`; desfaça.

- [ ] **Step 8: Suíte, lint e commit**

Run: `docker compose run --rm api php artisan test && docker compose run --rm --no-deps api composer lint`
Expected: PASS e lint OK (Pint + Larastan nível 6).

```bash
git add -A && git commit -m "feat(catalogo): restrições, itens de cozinha, alimentos e pesagens com seeders

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---
### Task 2: Cálculos puros — `GoalWeightResolver` (RN10/RN11) e `NutritionCalculator` (RN13)

**Files:**
- Create: `app/Services/Nutrition/{GoalWeight,GoalWeightResolver,DailyTargets,NutritionCalculator}.php`
- Test: `tests/Unit/Nutrition/GoalWeightResolverTest.php`, `tests/Unit/Nutrition/NutritionCalculatorTest.php`

**Interfaces:**
- Consumes: `Goal`, `Sex`, `ActivityLevel`, `WorkPosture`, `GoalWeightSource` (Task 1).
- Produces:
  - `final readonly class GoalWeight { float $kg; GoalWeightSource $source }`.
  - `GoalWeightResolver`: consts `OUT_OF_RANGE = 'GOAL_WEIGHT_OUT_OF_HEALTHY_RANGE'`, `RESET = 'GOAL_WEIGHT_RESET'`; `healthyRange(int $heightCm): array{min: float, max: float}`; `isOutOfRange(float $goalKg, int $heightCm): bool`; `suggest(Goal, float $weightKg, int $heightCm): ?GoalWeight`; `fits(Goal, float $weightKg, ?float $goalKg): bool`.
  - `final readonly class DailyTargets { int $kcal; int $proteinG; int $carbsG; int $fatG }`.
  - `NutritionCalculator::dailyTargets(Goal, Sex, int $age, int $heightCm, float $weightKg, ActivityLevel, WorkPosture): DailyTargets`.

- [ ] **Step 1: Testes por tabela que devem falhar**

`tests/Unit/Nutrition/GoalWeightResolverTest.php`:
```php
<?php

use App\Enums\Goal;
use App\Enums\GoalWeightSource;
use App\Services\Nutrition\GoalWeightResolver;

beforeEach(fn () => $this->resolver = new GoalWeightResolver);

it('calcula a faixa saudável pelo IMC 18,5–24,9, com 1 casa', function (int $height, float $min, float $max) {
    expect($this->resolver->healthyRange($height))->toBe(['min' => $min, 'max' => $max]);
})->with([
    '1,64 m (exemplo da spec)' => [164, 49.8, 67.0],
    '1,20 m' => [120, 26.6, 35.9],
    '1,78 m' => [178, 58.6, 78.9],
    '2,30 m' => [230, 97.9, 131.7],
]);

it('avisa quando a meta sai da faixa (sem bloquear)', function (float $goal, bool $out) {
    expect($this->resolver->isOutOfRange($goal, 164))->toBe($out);
})->with([[49.7, true], [49.8, false], [67.0, false], [75.0, true]]);

it('sugere a meta quando o usuário não informa (RN10)', function (Goal $goal, float $weight, int $height, ?float $kg, ?GoalWeightSource $source) {
    $result = $this->resolver->suggest($goal, $weight, $height);

    expect($result?->kg)->toBe($kg)->and($result?->source)->toBe($source);
})->with([
    'ganhar: 58,4 × 1,05 = 61,32 → 61,5 (CA04)' => [Goal::GanharMassa, 58.4, 164, 61.5, GoalWeightSource::Suggested],
    'perder: 58,4 × 0,95 = 55,48 → 55,5' => [Goal::PerderGordura, 58.4, 164, 55.5, GoalWeightSource::Suggested],
    'ganhar perto do teto: limita a 67,0' => [Goal::GanharMassa, 66.0, 164, 67.0, GoalWeightSource::Suggested],
    'perder perto do piso: limita a 50,0' => [Goal::PerderGordura, 50.5, 164, 50.0, GoalWeightSource::Suggested],
    'ganhar já no teto: não limita (a meta não pode ser ≤ peso)' => [Goal::GanharMassa, 67.0, 164, 70.5, GoalWeightSource::Suggested],
    'perder fora da faixa: não limita' => [Goal::PerderGordura, 92.0, 178, 87.5, GoalWeightSource::Suggested],
    'manter: meta = peso' => [Goal::ManterPeso, 70.0, 170, 70.0, GoalWeightSource::Auto],
    'disposição: sem meta' => [Goal::MaisDisposicao, 70.0, 170, null, null],
]);

it('diz se a meta combina com o objetivo (RN10, RN11)', function (Goal $goal, ?float $goalKg, bool $fits) {
    expect($this->resolver->fits($goal, 58.4, $goalKg))->toBe($fits);
})->with([
    'ganhar acima' => [Goal::GanharMassa, 62.0, true],
    'ganhar abaixo' => [Goal::GanharMassa, 55.0, false],
    'ganhar sem meta' => [Goal::GanharMassa, null, false],
    'perder abaixo' => [Goal::PerderGordura, 55.0, true],
    'perder acima' => [Goal::PerderGordura, 62.0, false],
    'manter igual' => [Goal::ManterPeso, 58.4, true],
    'manter diferente' => [Goal::ManterPeso, 60.0, false],
    'disposição sem meta' => [Goal::MaisDisposicao, null, true],
    'disposição com meta' => [Goal::MaisDisposicao, 60.0, false],
]);
```

`tests/Unit/Nutrition/NutritionCalculatorTest.php`:
```php
<?php

use App\Enums\ActivityLevel;
use App\Enums\Goal;
use App\Enums\Sex;
use App\Enums\WorkPosture;
use App\Services\Nutrition\NutritionCalculator;

it('calcula as metas diárias (RN13)', function (Goal $goal, Sex $sex, int $age, int $height, float $weight, ActivityLevel $activity, WorkPosture $posture, array $expected) {
    $targets = (new NutritionCalculator)->dailyTargets($goal, $sex, $age, $height, $weight, $activity, $posture);

    expect([$targets->kcal, $targets->proteinG, $targets->carbsG, $targets->fatG])->toBe($expected);
})->with([
    // TMB 1.313 × 1,55 × 1,10 = 2.238,7 → 2.250; P 116,8 → 115; G 62,5 → 65; C 305,1 → 305
    'Camila do mock (ganhar)' => [Goal::GanharMassa, Sex::Feminino, 27, 164, 58.4, ActivityLevel::Moderado, WorkPosture::Sentada, [2250, 115, 305, 65]],
    // TMB 1.862,5 × 1,425 × 0,85 = 2.255,9 → 2.250
    'perder, em pé' => [Goal::PerderGordura, Sex::Masculino, 35, 178, 92.0, ActivityLevel::Leve, WorkPosture::EmPe, [2250, 185, 215, 75]],
    // TMB 1.459,5 × 1,825 = 2.663,6 → 2.650; −78 para "não dizer"
    'manter, peso pesado' => [Goal::ManterPeso, Sex::NaoDizer, 45, 170, 70.0, ActivityLevel::Intenso, WorkPosture::PesoPesado, [2650, 110, 385, 75]],
    // 926,5 × 1,2 = 1.111,8 → piso 1.200
    'piso feminino' => [Goal::MaisDisposicao, Sex::Feminino, 60, 150, 45.0, ActivityLevel::Parado, WorkPosture::Sentada, [1200, 70, 145, 35]],
    'piso no déficit' => [Goal::PerderGordura, Sex::Feminino, 70, 150, 40.0, ActivityLevel::Parado, WorkPosture::Sentada, [1200, 80, 145, 35]],
    // fator 1,725 + 0,1 = 1,825 (abaixo do teto 1,9)
    'ganhar, intenso' => [Goal::GanharMassa, Sex::Masculino, 20, 185, 70.0, ActivityLevel::Intenso, WorkPosture::PesoPesado, [3550, 140, 525, 100]],
    // carboidrato não cabe: fica em 100 g e a gordura no mínimo de 0,8 g/kg
    'carboidrato mínimo' => [Goal::PerderGordura, Sex::Masculino, 30, 160, 150.0, ActivityLevel::Parado, WorkPosture::Sentada, [2400, 300, 100, 120]],
]);
```

Run: `docker compose run --rm api php artisan test tests/Unit/Nutrition`
Expected: FAIL — `Class "App\Services\Nutrition\GoalWeightResolver" not found`.

- [ ] **Step 2: Implementar**

`app/Services/Nutrition/GoalWeight.php`:
```php
<?php

namespace App\Services\Nutrition;

use App\Enums\GoalWeightSource;

final readonly class GoalWeight
{
    public function __construct(public float $kg, public GoalWeightSource $source) {}
}
```

`app/Services/Nutrition/GoalWeightResolver.php`:
```php
<?php

namespace App\Services\Nutrition;

use App\Enums\Goal;
use App\Enums\GoalWeightSource;

/** RN10 (meta de peso) e RN11 (troca de objetivo). Puro: sem banco, sem relógio. */
final class GoalWeightResolver
{
    public const OUT_OF_RANGE = 'GOAL_WEIGHT_OUT_OF_HEALTHY_RANGE';

    public const RESET = 'GOAL_WEIGHT_RESET';

    private const BMI_MIN = 18.5;

    private const BMI_MAX = 24.9;

    /** Faixa de IMC 18,5–24,9 para a altura, em kg com 1 casa. @return array{min: float, max: float} */
    public function healthyRange(int $heightCm): array
    {
        $squared = ($heightCm / 100) ** 2;

        return ['min' => round(self::BMI_MIN * $squared, 1), 'max' => round(self::BMI_MAX * $squared, 1)];
    }

    public function isOutOfRange(float $goalKg, int $heightCm): bool
    {
        ['min' => $min, 'max' => $max] = $this->healthyRange($heightCm);

        return $goalKg < $min || $goalKg > $max;
    }

    /** Meta quando o usuário não informou: `auto` (manter), sugerida (ganhar/perder) ou nenhuma (disposição). */
    public function suggest(Goal $goal, float $weightKg, int $heightCm): ?GoalWeight
    {
        return match ($goal) {
            Goal::ManterPeso => new GoalWeight($weightKg, GoalWeightSource::Auto),
            Goal::MaisDisposicao => null,
            Goal::GanharMassa, Goal::PerderGordura => new GoalWeight($this->suggested($goal, $weightKg, $heightCm), GoalWeightSource::Suggested),
        };
    }

    /** A meta combina com o objetivo? (direção de RN10; `null` só serve para disposição). */
    public function fits(Goal $goal, float $weightKg, ?float $goalKg): bool
    {
        return match ($goal) {
            Goal::GanharMassa => $goalKg !== null && $goalKg > $weightKg,
            Goal::PerderGordura => $goalKg !== null && $goalKg < $weightKg,
            Goal::ManterPeso => $goalKg !== null && abs($goalKg - $weightKg) < 0.05,
            Goal::MaisDisposicao => $goalKg === null,
        };
    }

    /** ±5% arredondado a 0,5 kg; limitado à faixa saudável quando o peso atual está nela e o limite não inverte a direção. */
    private function suggested(Goal $goal, float $weightKg, int $heightCm): float
    {
        $kg = $this->toHalf($weightKg * ($goal === Goal::GanharMassa ? 1.05 : 0.95));

        ['min' => $min, 'max' => $max] = $this->healthyRange($heightCm);
        if ($weightKg < $min || $weightKg > $max) {
            return $kg;
        }

        $clamped = min(max($kg, ceil($min * 2) / 2), floor($max * 2) / 2);

        return $this->fits($goal, $weightKg, $clamped) ? $clamped : $kg;
    }

    private function toHalf(float $kg): float
    {
        return round($kg * 2) / 2;
    }
}
```

`app/Services/Nutrition/DailyTargets.php`:
```php
<?php

namespace App\Services\Nutrition;

final readonly class DailyTargets
{
    public function __construct(
        public int $kcal,
        public int $proteinG,
        public int $carbsG,
        public int $fatG,
    ) {}
}
```

`app/Services/Nutrition/NutritionCalculator.php`:
```php
<?php

namespace App\Services\Nutrition;

use App\Enums\ActivityLevel;
use App\Enums\Goal;
use App\Enums\Sex;
use App\Enums\WorkPosture;

/** RN13 — metas diárias. Puro; usado pela prévia (`/plans/preview-targets`) e, no Plano 04, pela geração. */
final class NutritionCalculator
{
    private const MAX_FACTOR = 1.9;

    private const MIN_CARBS_G = 100;

    public function dailyTargets(Goal $goal, Sex $sex, int $age, int $heightCm, float $weightKg, ActivityLevel $activity, WorkPosture $posture): DailyTargets
    {
        $bmr = 10 * $weightKg + 6.25 * $heightCm - 5 * $age + $this->sexOffset($sex);
        $factor = min($activity->factor() + $posture->bonus(), self::MAX_FACTOR);
        $kcal = $this->roundTo(max($bmr * $factor * $this->goalAdjustment($goal), $this->floor($sex)), 50);

        $protein = $this->proteinPerKg($goal) * $weightKg;
        $minFat = 0.8 * $weightKg;
        $fat = max(0.25 * $kcal / 9, $minFat);
        $carbs = ($kcal - 4 * $protein - 9 * $fat) / 4;

        if ($carbs < self::MIN_CARBS_G) {
            // Carboidrato no mínimo; a gordura cede até o mínimo de 0,8 g/kg.
            $fat = max($minFat, ($kcal - 4 * $protein - 4 * self::MIN_CARBS_G) / 9);
            $carbs = max(self::MIN_CARBS_G, ($kcal - 4 * $protein - 9 * $fat) / 4);
        }

        return new DailyTargets($kcal, $this->roundTo($protein, 5), $this->roundTo($carbs, 5), $this->roundTo($fat, 5));
    }

    private function sexOffset(Sex $sex): int
    {
        return match ($sex) {
            Sex::Masculino => 5,
            Sex::Feminino => -161,
            Sex::NaoDizer => -78,
        };
    }

    private function goalAdjustment(Goal $goal): float
    {
        return match ($goal) {
            Goal::GanharMassa => 1.10,
            Goal::PerderGordura => 0.85,
            Goal::ManterPeso, Goal::MaisDisposicao => 1.0,
        };
    }

    private function floor(Sex $sex): int
    {
        return $sex === Sex::Masculino ? 1500 : 1200;
    }

    private function proteinPerKg(Goal $goal): float
    {
        return $goal === Goal::GanharMassa || $goal === Goal::PerderGordura ? 2.0 : 1.6;
    }

    private function roundTo(float $value, int $step): int
    {
        return (int) (round($value / $step) * $step);
    }
}
```

- [ ] **Step 3: Rodar e ver passar**

Run: `docker compose run --rm api php artisan test tests/Unit/Nutrition`
Expected: PASS (4 + 4 + 8 + 9 + 7 casos).

- [ ] **Step 4: Lint e commit**

Run: `docker compose run --rm --no-deps api composer lint`
Expected: OK.

```bash
git add -A && git commit -m "feat(nutricao): meta de peso (RN10/RN11) e metas diárias (RN13) como cálculos puros

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---
### Task 3: API das etapas — catálogo, `GET /onboarding` e `PATCH /profile/steps/{step}` (backend)

**Files:**
- Create: `app/Services/Catalog/OnboardingCatalog.php`, `app/Services/Profile/ProfileService.php`
- Create: `app/Http/Requests/Concerns/ProfileRules.php`, `app/Http/Requests/Profile/ProfileStepRequest.php`
- Create: `app/Http/Resources/OnboardingResource.php`
- Create: `app/Http/Controllers/Api/V1/{CatalogController,OnboardingController,ProfileController}.php`
- Modify: `routes/api.php`, `database/factories/{ProfileFactory,UserFactory}.php`, `tests/Pest.php`
- Test: `tests/Feature/Catalog/CatalogTest.php`, `tests/Feature/Onboarding/OnboardingStepsTest.php`

**Interfaces:**
- Consumes: models e enums (Task 1); `GoalWeightResolver`, `GoalWeight` (Task 2).
- Produces:
  - `GET /api/v1/catalog/onboarding` e `GET /api/v1/onboarding` (formatos da spec §5); `PATCH /api/v1/profile/steps/{objetivo|dados|atividade|preferencias|restricoes|rotina}` → `{ data: <onboarding>, meta: { plan_effect: "none", plan_id: null, warnings: string[] } }`. Avisos: `GOAL_WEIGHT_OUT_OF_HEALTHY_RANGE`, `GOAL_WEIGHT_RESET`.
  - `ProfileService::updateStep(User, OnboardingStep, array $data): list<string>` e `updatePreferences(User, array $data): void`.
  - `ProfileRules::pantry()`, `::restrictions()`, `::messages()` (reusados pelo `PreferencesRequest` da Task 5).
  - `OnboardingResource::RELATIONS`, `OnboardingResource::time(?string): ?string` ("06:20:00" → "06:20").
  - Factories: `Profile::factory()->answered()` / `User::factory()->answered()` — todas as etapas respondidas (Camila do mock), sem concluir. Helper de teste `stepPayload(string $step, array $overrides = [])`.

- [ ] **Step 1: Factories, helper e testes que devem falhar**

Em `database/factories/ProfileFactory.php`, trocar o método `onboarded()` por estes dois:
```php
    /** Todas as etapas respondidas (Camila do mock), sem concluir o onboarding. */
    public function answered(): static
    {
        return $this->state(fn () => [
            'goal' => 'ganhar-massa',
            'sex' => 'feminino',
            'age' => 27,
            'height_cm' => 164,
            'start_weight_kg' => 58.4,
            'goal_weight_kg' => 62.0,
            'goal_weight_source' => 'user',
            'activity_level' => 'moderado',
            'work_posture' => 'sentada',
            'wake_time' => '06:20',
            'training_time' => '19:00',
            'sleep_time' => '23:00',
            'training_days' => [1, 3, 5],
            'lunch_place' => 'marmita',
            'completed_steps' => ['objetivo', 'dados', 'atividade', 'preferencias', 'restricoes', 'rotina'],
        ]);
    }

    /** Perfil da Camila do mock, com onboarding concluído. */
    public function onboarded(): static
    {
        return $this->answered()->state(fn () => [
            'completed_steps' => array_map(fn (OnboardingStep $step) => $step->value, OnboardingStep::cases()),
            'onboarding_completed_at' => now(),
        ]);
    }
```

Em `database/factories/UserFactory.php`, acrescentar depois de `onboarded()`:
```php
    /** Onboarding respondido até o resumo, ainda não concluído. */
    public function answered(): static
    {
        return $this->has(Profile::factory()->answered(), 'profile');
    }
```

Em `tests/Pest.php`, acrescentar ao fim:
```php
/** Corpo válido de cada etapa do onboarding (dados da Camila do mock); sobrescreva o que o teste variar. */
function stepPayload(string $step, array $overrides = []): array
{
    $payloads = [
        'objetivo' => ['goal' => 'ganhar-massa'],
        'dados' => ['preferred_name' => 'Camila', 'age' => 27, 'height_cm' => 164, 'weight_kg' => 58.4, 'sex' => 'feminino', 'goal_weight_kg' => 62.0],
        'atividade' => ['activity_level' => 'moderado', 'work_posture' => 'sentada'],
        'preferencias' => ['pantry_items' => ['ovos', 'frango', 'arroz-e-feijao']],
        'restricoes' => ['restrictions' => ['castanhas'], 'other_restrictions' => ['camarão', 'pimenta']],
        'rotina' => ['wake_time' => '06:20', 'training_time' => '19:00', 'sleep_time' => '23:00', 'training_days' => [1, 3, 5], 'lunch_place' => 'marmita'],
    ];

    return array_merge($payloads[$step], $overrides);
}
```

`tests/Feature/Catalog/CatalogTest.php`:
```php
<?php

beforeEach(fn () => seedCatalog());

it('lista as opções de todas as etapas, sem exigir onboarding', function () {
    login();

    $this->getJson('/api/v1/catalog/onboarding')
        ->assertOk()
        ->assertJsonCount(4, 'data.goals')
        ->assertJsonPath('data.goals.0', ['value' => 'ganhar-massa', 'label' => 'Ganhar massa magra', 'description' => 'Comer um pouco acima do gasto, com proteína alta todo dia.'])
        ->assertJsonPath('data.activity_levels.2', ['value' => 'moderado', 'label' => '3 ou 4 vezes na semana', 'description' => 'O ritmo da maior parte do pessoal da Zfit.'])
        ->assertJsonPath('data.work_postures.1', ['value' => 'em-pe', 'label' => 'Em pé'])
        ->assertJsonPath('data.restrictions.2', ['slug' => 'castanhas', 'label' => 'Amendoim e castanhas', 'is_allergy' => true])
        ->assertJsonCount(6, 'data.restrictions')
        ->assertJsonCount(3, 'data.pantry')
        ->assertJsonPath('data.pantry.0.category', 'proteinas')
        ->assertJsonPath('data.pantry.0.label', 'Proteínas')
        ->assertJsonPath('data.pantry.0.items.0', ['slug' => 'ovos', 'label' => 'Ovos'])
        ->assertJsonPath('data.pantry.2.items.*.slug', ['banana', 'mamao', 'maca', 'laranja'])
        ->assertJsonPath('data.dislike_options.*.name', ['Berinjela', 'Beterraba', 'Fígado bovino', 'Jiló', 'Peixe assado'])
        ->assertJsonPath('data.lunch_places.1', ['value' => 'marmita', 'label' => 'Marmita no trabalho']);
});

it('exige login', function () {
    $this->getJson('/api/v1/catalog/onboarding')->assertUnauthorized();
});
```

`tests/Feature/Onboarding/OnboardingStepsTest.php`:
```php
<?php

use App\Models\User;
use App\Models\WeighIn;

beforeEach(fn () => seedCatalog());

it('salva as etapas em ordem e aponta a próxima (RN08)', function () {
    login(User::factory()->create(['name' => 'Camila Réus']));
    $sequencia = ['objetivo' => 'dados', 'dados' => 'atividade', 'atividade' => 'preferencias', 'preferencias' => 'restricoes', 'restricoes' => 'rotina', 'rotina' => 'resumo'];

    foreach ($sequencia as $step => $next) {
        $this->patchJson("/api/v1/profile/steps/{$step}", stepPayload($step))
            ->assertOk()
            ->assertJsonPath('data.next_step', $next)
            ->assertJsonPath('meta', ['plan_effect' => 'none', 'plan_id' => null, 'warnings' => []]);
    }

    $this->getJson('/api/v1/onboarding')
        ->assertOk()
        ->assertJsonPath('data.completed', false)
        ->assertJsonPath('data.completed_steps', array_keys($sequencia))
        ->assertJsonPath('data.answers', [
            'goal' => 'ganhar-massa', 'preferred_name' => 'Camila', 'age' => 27, 'height_cm' => 164, 'weight_kg' => 58.4,
            'sex' => 'feminino', 'goal_weight_kg' => 62.0, 'goal_weight_source' => 'user',
            'activity_level' => 'moderado', 'work_posture' => 'sentada',
            'pantry_items' => ['ovos', 'frango', 'arroz-e-feijao'], 'restrictions' => ['castanhas'], 'other_restrictions' => ['camarão', 'pimenta'],
            'wake_time' => '06:20', 'training_time' => '19:00', 'sleep_time' => '23:00', 'training_days' => [1, 3, 5], 'lunch_place' => 'marmita',
        ])
        ->assertJsonPath('data.healthy_weight_range', ['min' => 49.8, 'max' => 67.0]);
});

it('retoma de onde parou, com as respostas salvas (CA01)', function () {
    login();
    $this->patchJson('/api/v1/profile/steps/objetivo', stepPayload('objetivo'))->assertOk();
    $this->patchJson('/api/v1/profile/steps/dados', stepPayload('dados'))->assertOk();

    $this->getJson('/api/v1/me')->assertJsonPath('data.next_step', 'atividade');
    $this->getJson('/api/v1/onboarding')
        ->assertJsonPath('data.next_step', 'atividade')
        ->assertJsonPath('data.answers.age', 27)
        ->assertJsonPath('data.answers.weight_kg', 58.4);
});

it('usa o primeiro nome do cadastro como nome preferido até a pessoa escolher outro', function () {
    login(User::factory()->create(['name' => 'Rafael Lima Souza']));

    $this->getJson('/api/v1/onboarding')
        ->assertJsonPath('data.answers.preferred_name', 'Rafael')
        ->assertJsonPath('data.healthy_weight_range', null);
});

dataset('etapas inválidas', [
    'objetivo vazio' => ['objetivo', ['goal' => null], 'goal', 'Escolha um objetivo.'],
    'objetivo desconhecido' => ['objetivo', ['goal' => 'ficar-forte'], 'goal', 'Escolha um objetivo.'],
    'nome vazio' => ['dados', ['preferred_name' => ''], 'preferred_name', 'Diga como podemos te chamar (até 40 letras).'],
    'nome com 41 letras' => ['dados', ['preferred_name' => str_repeat('a', 41)], 'preferred_name', 'Diga como podemos te chamar (até 40 letras).'],
    'idade 17 (P2)' => ['dados', ['age' => 17], 'age', 'Use uma idade entre 18 e 100 anos.'],
    'idade 101' => ['dados', ['age' => 101], 'age', 'Use uma idade entre 18 e 100 anos.'],
    'idade quebrada' => ['dados', ['age' => 27.5], 'age', 'Use uma idade entre 18 e 100 anos.'],
    'altura 119' => ['dados', ['height_cm' => 119], 'height_cm', 'Use a altura em centímetros, entre 120 e 230.'],
    'altura 231' => ['dados', ['height_cm' => 231], 'height_cm', 'Use a altura em centímetros, entre 120 e 230.'],
    'peso 29,9' => ['dados', ['weight_kg' => 29.9], 'weight_kg', 'Use um peso entre 30 e 250 kg, com até uma casa decimal.'],
    'peso 250,1' => ['dados', ['weight_kg' => 250.1], 'weight_kg', 'Use um peso entre 30 e 250 kg, com até uma casa decimal.'],
    'peso com 2 casas' => ['dados', ['weight_kg' => 58.45], 'weight_kg', 'Use um peso entre 30 e 250 kg, com até uma casa decimal.'],
    'sexo desconhecido' => ['dados', ['sex' => 'outro'], 'sex', 'Escolha uma opção.'],
    'meta abaixo do peso ao ganhar (CA02)' => ['dados', ['goal_weight_kg' => 55.0], 'goal_weight_kg', 'Para ganhar massa, a meta precisa ser maior que o peso de hoje.'],
    'meta acima do limite' => ['dados', ['goal_weight_kg' => 251.0], 'goal_weight_kg', 'Use uma meta entre 30 e 250 kg.'],
    'atividade vazia' => ['atividade', ['activity_level' => null], 'activity_level', 'Escolha quantas vezes você treina.'],
    'trabalho desconhecido' => ['atividade', ['work_posture' => 'deitada'], 'work_posture', 'Escolha como é seu trabalho.'],
    'item de cozinha desconhecido' => ['preferencias', ['pantry_items' => ['caviar']], 'pantry_items.0', 'Confira os itens da cozinha.'],
    'restrição desconhecida' => ['restricoes', ['restrictions' => ['cebola']], 'restrictions.0', 'Confira as restrições.'],
    'mais de 10 outras' => ['restricoes', ['other_restrictions' => array_map(fn (int $i) => "item {$i}", range(1, 11))], 'other_restrictions', 'Use no máximo 10 itens.'],
    'outra curta demais' => ['restricoes', ['other_restrictions' => ['a']], 'other_restrictions.0', 'Cada item precisa ter de 2 a 60 letras.'],
    'outra repetida' => ['restricoes', ['other_restrictions' => ['Camarão', 'camarão']], 'other_restrictions.1', 'Esse item já está na lista.'],
    'hora sem formato' => ['rotina', ['wake_time' => '6h20'], 'wake_time', 'Use o formato 06:20.'],
    'dia 7' => ['rotina', ['training_days' => [7]], 'training_days.0', 'Confira os dias de treino.'],
    'dia repetido' => ['rotina', ['training_days' => [1, 1]], 'training_days.1', 'Confira os dias de treino.'],
    'almoço desconhecido' => ['rotina', ['lunch_place' => 'lanchonete'], 'lunch_place', 'Escolha onde você almoça.'],
    'treino antes de acordar (CA06)' => ['rotina', ['training_time' => '05:00'], 'training_time', 'O treino precisa estar entre a hora que você acorda e a que dorme.'],
    'dia acordado com menos de 12 h' => ['rotina', ['wake_time' => '09:00', 'training_time' => '12:00', 'sleep_time' => '20:00'], 'sleep_time', 'Seu dia acordado precisa ter pelo menos 12 horas.'],
]);

it('recusa o que foge das regras (§6), com a mensagem no campo', function (string $step, array $overrides, string $field, string $message) {
    login(User::factory()->answered()->create());

    $this->patchJson("/api/v1/profile/steps/{$step}", stepPayload($step, $overrides))
        ->assertUnprocessable()
        ->assertJsonPath('code', 'VALIDATION_ERROR')
        ->assertJsonValidationErrors([$field => $message]);
})->with('etapas inválidas');

it('aceita sono depois da meia-noite (RN12)', function () {
    login(User::factory()->answered()->create());

    $this->patchJson('/api/v1/profile/steps/rotina', stepPayload('rotina', ['wake_time' => '10:00', 'training_time' => '23:00', 'sleep_time' => '01:30']))
        ->assertOk()
        ->assertJsonPath('data.answers.sleep_time', '01:30');
});

it('aceita meta fora da faixa saudável e avisa (CA03)', function () {
    login(User::factory()->answered()->create());

    $this->patchJson('/api/v1/profile/steps/dados', stepPayload('dados', ['goal_weight_kg' => 75.0]))
        ->assertOk()
        ->assertJsonPath('data.answers.goal_weight_kg', 75.0)
        ->assertJsonPath('data.answers.goal_weight_source', 'user')
        ->assertJsonPath('meta.warnings', ['GOAL_WEIGHT_OUT_OF_HEALTHY_RANGE']);
});

it('recusa meta quando o objetivo não usa meta (RN10)', function () {
    $user = User::factory()->answered()->create();
    $user->profile->update(['goal' => 'manter-peso', 'goal_weight_kg' => null, 'goal_weight_source' => null]);
    login($user);

    $this->patchJson('/api/v1/profile/steps/dados', stepPayload('dados', ['goal_weight_kg' => 60.0]))
        ->assertJsonValidationErrors(['goal_weight_kg' => 'Esse objetivo não usa meta de peso.']);
});

it('durante o onboarding, a meta vazia espera a conclusão (RN10)', function () {
    login(User::factory()->answered()->create());

    $this->patchJson('/api/v1/profile/steps/dados', stepPayload('dados', ['goal_weight_kg' => null]))
        ->assertOk()
        ->assertJsonPath('data.answers.goal_weight_kg', null)
        ->assertJsonPath('data.answers.goal_weight_source', null);
});

it('trocar para um objetivo que não combina com a meta apaga a meta e avisa (RN11)', function () {
    login(User::factory()->answered()->create()); // ganhar massa, meta 62

    $this->patchJson('/api/v1/profile/steps/objetivo', ['goal' => 'perder-gordura'])
        ->assertOk()
        ->assertJsonPath('data.answers.goal', 'perder-gordura')
        ->assertJsonPath('data.answers.goal_weight_kg', null)
        ->assertJsonPath('meta.warnings', ['GOAL_WEIGHT_RESET']);
});

it('manter o objetivo não mexe na meta', function () {
    login(User::factory()->answered()->create());

    $this->patchJson('/api/v1/profile/steps/objetivo', ['goal' => 'ganhar-massa'])
        ->assertJsonPath('data.answers.goal_weight_kg', 62.0)
        ->assertJsonPath('meta.warnings', []);
});

it('depois do onboarding, trocar o objetivo já sugere a meta nova (RN11)', function () {
    login(User::factory()->onboarded()->create());

    $this->patchJson('/api/v1/profile/steps/objetivo', ['goal' => 'perder-gordura'])
        ->assertJsonPath('data.answers.goal_weight_kg', 55.5)
        ->assertJsonPath('data.answers.goal_weight_source', 'suggested')
        ->assertJsonPath('meta.warnings', ['GOAL_WEIGHT_RESET']);
});

it('depois do onboarding, o peso de hoje vira a pesagem do dia e o peso inicial fica (RN34)', function () {
    $user = login(User::factory()->onboarded()->create());

    $this->patchJson('/api/v1/profile/steps/dados', stepPayload('dados', ['weight_kg' => 59.0]))->assertJsonPath('data.answers.weight_kg', 59.0);
    $this->patchJson('/api/v1/profile/steps/dados', stepPayload('dados', ['weight_kg' => 59.3]))->assertJsonPath('data.answers.weight_kg', 59.3);

    expect(WeighIn::where('user_id', $user->id)->count())->toBe(1)
        ->and(WeighIn::where('user_id', $user->id)->sole()->weight_kg)->toBe(59.3)
        ->and((float) $user->profile->fresh()->start_weight_kg)->toBe(58.4);
});

it('depois do onboarding, meta vazia vira a sugerida na hora', function () {
    login(User::factory()->onboarded()->create());

    $this->patchJson('/api/v1/profile/steps/dados', stepPayload('dados', ['goal_weight_kg' => null]))
        ->assertJsonPath('data.answers.goal_weight_kg', 61.5)
        ->assertJsonPath('data.answers.goal_weight_source', 'suggested');
});

it('substitui a lista da cozinha e a das restrições a cada envio', function () {
    login(User::factory()->answered()->create());

    $this->patchJson('/api/v1/profile/steps/preferencias', ['pantry_items' => ['ovos', 'banana']])->assertOk();
    $this->patchJson('/api/v1/profile/steps/preferencias', ['pantry_items' => ['maca']])
        ->assertJsonPath('data.answers.pantry_items', ['maca']);

    $this->patchJson('/api/v1/profile/steps/restricoes', ['restrictions' => ['castanhas'], 'other_restrictions' => []])->assertOk();
    $this->patchJson('/api/v1/profile/steps/restricoes', ['restrictions' => [], 'other_restrictions' => ['  pimenta ']])
        ->assertJsonPath('data.answers.restrictions', [])
        ->assertJsonPath('data.answers.other_restrictions', ['pimenta']);
});

it('responde 404 para etapa que não existe ou que não se salva', function (string $step) {
    login();

    $this->patchJson("/api/v1/profile/steps/{$step}", [])->assertNotFound()->assertJsonPath('code', 'NOT_FOUND');
})->with(['resumo', 'bonus']);

it('exige login', function () {
    $this->patchJson('/api/v1/profile/steps/objetivo', stepPayload('objetivo'))->assertUnauthorized();
    $this->getJson('/api/v1/onboarding')->assertUnauthorized();
});
```

Run: `docker compose run --rm api php artisan test tests/Feature/Catalog/CatalogTest.php tests/Feature/Onboarding`
Expected: FAIL — rotas inexistentes (404 onde se espera 200/422).

- [ ] **Step 2: Implementar**

`app/Services/Catalog/OnboardingCatalog.php`:
```php
<?php

namespace App\Services\Catalog;

use App\Enums\ActivityLevel;
use App\Enums\Goal;
use App\Enums\LunchPlace;
use App\Enums\WorkPosture;
use App\Models\Food;
use App\Models\PantryItem;
use App\Models\Restriction;
use Illuminate\Support\Collection;

/** Opções de todas as etapas do onboarding e de Preferências (`GET /catalog/onboarding`). */
class OnboardingCatalog
{
    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'goals' => array_map(fn (Goal $goal) => ['value' => $goal->value, 'label' => $goal->label(), 'description' => $goal->description()], Goal::cases()),
            'activity_levels' => array_map(fn (ActivityLevel $level) => ['value' => $level->value, 'label' => $level->label(), 'description' => $level->description()], ActivityLevel::cases()),
            'work_postures' => array_map(fn (WorkPosture $posture) => ['value' => $posture->value, 'label' => $posture->label()], WorkPosture::cases()),
            'restrictions' => Restriction::orderBy('position')->get()
                ->map(fn (Restriction $r) => ['slug' => $r->slug, 'label' => $r->label, 'is_allergy' => $r->is_allergy])->all(),
            'pantry' => PantryItem::orderBy('position')->get()->groupBy('category')
                ->map(fn (Collection $items, string $category) => [
                    'category' => $category,
                    'label' => PantryItem::CATEGORIES[$category] ?? $category,
                    'items' => $items->map(fn (PantryItem $item) => ['slug' => $item->slug, 'label' => $item->label])->values()->all(),
                ])->values()->all(),
            'dislike_options' => Food::where('common_dislike', true)->where('is_active', true)->orderBy('name')->get()
                ->map(fn (Food $food) => ['id' => $food->id, 'name' => $food->name])->all(),
            'lunch_places' => array_map(fn (LunchPlace $place) => ['value' => $place->value, 'label' => $place->label()], LunchPlace::cases()),
        ];
    }
}
```

`app/Http/Requests/Concerns/ProfileRules.php`:
```php
<?php

namespace App\Http\Requests\Concerns;

/** Listas do perfil, iguais na etapa do onboarding e em Preferências (spec 02 §6). */
final class ProfileRules
{
    /** @return array<string, list<string>> */
    public static function pantry(): array
    {
        return [
            'pantry_items' => ['present', 'array'],
            'pantry_items.*' => ['string', 'distinct', 'exists:pantry_items,slug'],
        ];
    }

    /** @return array<string, list<string>> */
    public static function restrictions(): array
    {
        return [
            'restrictions' => ['present', 'array'],
            'restrictions.*' => ['string', 'distinct', 'exists:restrictions,slug'],
            'other_restrictions' => ['present', 'array', 'max:10'],
            'other_restrictions.*' => ['string', 'min:2', 'max:60', 'distinct:ignore_case'],
        ];
    }

    /** A ordem importa: a primeira chave que casar vence. @return array<string, string> */
    public static function messages(): array
    {
        return [
            'pantry_items.*' => 'Confira os itens da cozinha.',
            'restrictions.*' => 'Confira as restrições.',
            'other_restrictions.max' => 'Use no máximo 10 itens.',
            'other_restrictions.*.distinct' => 'Esse item já está na lista.',
            'other_restrictions.*' => 'Cada item precisa ter de 2 a 60 letras.',
            'disliked_food_ids.*' => 'Confira os alimentos que você prefere não ver.',
        ];
    }
}
```

`app/Http/Requests/Profile/ProfileStepRequest.php`:
```php
<?php

namespace App\Http\Requests\Profile;

use App\Enums\ActivityLevel;
use App\Enums\Goal;
use App\Enums\LunchPlace;
use App\Enums\Sex;
use App\Enums\WorkPosture;
use App\Http\Requests\Concerns\ProfileRules;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** Uma etapa do onboarding (spec 02 §6); a etapa vem da rota. */
class ProfileStepRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return match ($this->route('step')) {
            'objetivo' => ['goal' => ['required', Rule::enum(Goal::class)]],
            'dados' => [
                'preferred_name' => ['required', 'string', 'max:40'],
                'age' => ['required', 'integer', 'between:18,100'],
                'height_cm' => ['required', 'integer', 'between:120,230'],
                'weight_kg' => ['required', 'numeric', 'decimal:0,1', 'between:30,250'],
                'sex' => ['required', Rule::enum(Sex::class)],
                'goal_weight_kg' => $this->goalWeightRules(),
            ],
            'atividade' => [
                'activity_level' => ['required', Rule::enum(ActivityLevel::class)],
                'work_posture' => ['required', Rule::enum(WorkPosture::class)],
            ],
            'preferencias' => ProfileRules::pantry(),
            'restricoes' => ProfileRules::restrictions(),
            'rotina' => [
                'wake_time' => ['required', 'date_format:H:i'],
                'training_time' => ['required', 'date_format:H:i'],
                'sleep_time' => ['required', 'date_format:H:i'],
                'training_days' => ['present', 'array', 'max:7'],
                'training_days.*' => ['integer', 'between:0,6', 'distinct'],
                'lunch_place' => ['required', Rule::enum(LunchPlace::class)],
            ],
            default => [],
        };
    }

    /** RN12 — treino dentro da janela acordado, e janela de pelo menos 12 h. @return list<callable> */
    public function after(): array
    {
        if ($this->route('step') !== 'rotina') {
            return [];
        }

        return [function (Validator $validator) {
            if ($validator->errors()->hasAny(['wake_time', 'training_time', 'sleep_time'])) {
                return;
            }

            $wake = $this->minutes('wake_time');
            $sleep = $this->minutes('sleep_time');
            $training = $this->minutes('training_time');

            if ($sleep <= $wake) {
                $sleep += 24 * 60; // dorme depois da meia-noite
            }
            if ($sleep - $wake < 12 * 60) {
                $validator->errors()->add('sleep_time', 'Seu dia acordado precisa ter pelo menos 12 horas.');

                return;
            }
            if ($training < $wake) {
                $training += 24 * 60;
            }
            if ($training >= $sleep) {
                $validator->errors()->add('training_time', 'O treino precisa estar entre a hora que você acorda e a que dorme.');
            }
        }];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'goal.*' => 'Escolha um objetivo.',
            'preferred_name.*' => 'Diga como podemos te chamar (até 40 letras).',
            'age.*' => 'Use uma idade entre 18 e 100 anos.',
            'height_cm.*' => 'Use a altura em centímetros, entre 120 e 230.',
            'weight_kg.*' => 'Use um peso entre 30 e 250 kg, com até uma casa decimal.',
            'sex.*' => 'Escolha uma opção.',
            'goal_weight_kg.gt' => 'Para ganhar massa, a meta precisa ser maior que o peso de hoje.',
            'goal_weight_kg.lt' => 'Para perder gordura, a meta precisa ser menor que o peso de hoje.',
            'goal_weight_kg.prohibited' => 'Esse objetivo não usa meta de peso.',
            'goal_weight_kg.*' => 'Use uma meta entre 30 e 250 kg.',
            'activity_level.*' => 'Escolha quantas vezes você treina.',
            'work_posture.*' => 'Escolha como é seu trabalho.',
            'wake_time.*' => 'Use o formato 06:20.',
            'training_time.*' => 'Use o formato 06:20.',
            'sleep_time.*' => 'Use o formato 06:20.',
            'training_days.*' => 'Confira os dias de treino.',
            'lunch_place.*' => 'Escolha onde você almoça.',
            ...ProfileRules::messages(),
        ];
    }

    /** RN10: só ganhar/perder aceitam meta, na direção do objetivo já salvo. @return list<string> */
    private function goalWeightRules(): array
    {
        /** @var User $user */
        $user = $this->user();
        $goal = Goal::tryFrom((string) $user->profile->goal);

        if ($goal === null || ! $goal->asksGoalWeight()) {
            return ['prohibited'];
        }

        return ['nullable', 'numeric', 'decimal:0,1', 'between:30,250', $goal === Goal::GanharMassa ? 'gt:weight_kg' : 'lt:weight_kg'];
    }

    private function minutes(string $field): int
    {
        [$hours, $minutes] = array_map('intval', explode(':', (string) $this->input($field)));

        return $hours * 60 + $minutes;
    }
}
```

`app/Services/Profile/ProfileService.php`:
```php
<?php

namespace App\Services\Profile;

use App\Enums\Goal;
use App\Enums\GoalWeightSource;
use App\Enums\OnboardingStep;
use App\Models\PantryItem;
use App\Models\Profile;
use App\Models\Restriction;
use App\Models\User;
use App\Services\Nutrition\GoalWeight;
use App\Services\Nutrition\GoalWeightResolver;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Etapas do onboarding e edição do perfil (RN08, RN10, RN11, RN34). RN21 chega no Plano 04. */
class ProfileService
{
    public function __construct(private readonly GoalWeightResolver $goalWeights) {}

    /**
     * Salva uma etapa e a marca como feita; devolve os avisos da resposta (`meta.warnings`).
     *
     * @param  array<string, mixed>  $data  validado por ProfileStepRequest
     * @return list<string>
     */
    public function updateStep(User $user, OnboardingStep $step, array $data): array
    {
        return DB::transaction(function () use ($user, $step, $data) {
            $profile = $user->profile;

            $warnings = match ($step) {
                OnboardingStep::Objetivo => $this->saveGoal($user, $profile, Goal::from((string) $data['goal'])),
                OnboardingStep::Dados => $this->saveBody($user, $profile, $data),
                OnboardingStep::Atividade, OnboardingStep::Rotina => $this->fill($profile, $data),
                OnboardingStep::Preferencias => $this->syncPantry($user, $data['pantry_items']),
                OnboardingStep::Restricoes => $this->saveRestrictions($user, $profile, $data),
                OnboardingStep::Resumo => throw new LogicException('O resumo não é uma etapa salva.'),
            };

            $profile->completed_steps = array_values(array_unique([...$profile->completed_steps, $step->value]));
            $profile->save();

            return $warnings;
        });
    }

    /**
     * RF17 — substitui as quatro listas de uma vez.
     *
     * @param  array<string, mixed>  $data  validado por PreferencesRequest
     */
    public function updatePreferences(User $user, array $data): void
    {
        DB::transaction(function () use ($user, $data) {
            $this->saveRestrictions($user, $user->profile, $data);
            $user->profile->save();
            $this->syncPantry($user, $data['pantry_items']);
            $user->dislikedFoods()->sync($data['disliked_food_ids']);
        });
    }

    /** RN11: objetivo novo que não combina com a meta → meta refeita (ou apagada no onboarding) e aviso. @return list<string> */
    private function saveGoal(User $user, Profile $profile, Goal $goal): array
    {
        $profile->goal = $goal->value;

        $weight = $user->currentWeightKg();
        $previous = $profile->goal_weight_kg === null ? null : (float) $profile->goal_weight_kg;
        if ($weight === null || $profile->height_cm === null || $this->goalWeights->fits($goal, $weight, $previous)) {
            return [];
        }

        $this->setGoalWeight($profile, $this->defaultGoalWeight($profile, $goal, $weight));
        $current = $profile->goal_weight_kg === null ? null : (float) $profile->goal_weight_kg;

        return $previous !== null && $current !== $previous ? [GoalWeightResolver::RESET] : [];
    }

    /**
     * Etapa `dados`.
     *
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private function saveBody(User $user, Profile $profile, array $data): array
    {
        $profile->fill(Arr::only($data, ['preferred_name', 'age', 'height_cm', 'sex']));
        $weight = (float) $data['weight_kg'];

        if ($profile->isOnboarded()) {
            // RN34: depois do onboarding, "peso de hoje" é a pesagem do dia; o peso inicial não muda.
            $user->weighIns()->updateOrCreate(['date' => today()->toDateString()], ['weight_kg' => $weight]);
            $user->unsetRelation('latestWeighIn');
        } else {
            $profile->start_weight_kg = $weight;
        }

        $informed = $data['goal_weight_kg'] ?? null;
        if ($informed !== null) {
            $this->setGoalWeight($profile, new GoalWeight((float) $informed, GoalWeightSource::User));

            return $this->goalWeights->isOutOfRange((float) $informed, (int) $profile->height_cm) ? [GoalWeightResolver::OUT_OF_RANGE] : [];
        }

        $goal = Goal::tryFrom((string) $profile->goal);
        $this->setGoalWeight($profile, $goal === null ? null : $this->defaultGoalWeight($profile, $goal, $weight));

        return [];
    }

    /** Durante o onboarding a meta vazia espera a conclusão (RN10); depois, vira a sugerida na hora. */
    private function defaultGoalWeight(Profile $profile, Goal $goal, float $weight): ?GoalWeight
    {
        return $profile->isOnboarded() ? $this->goalWeights->suggest($goal, $weight, (int) $profile->height_cm) : null;
    }

    private function setGoalWeight(Profile $profile, ?GoalWeight $goalWeight): void
    {
        $profile->goal_weight_kg = $goalWeight?->kg;
        $profile->goal_weight_source = $goalWeight?->source->value;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private function fill(Profile $profile, array $data): array
    {
        $profile->fill($data);

        return [];
    }

    /**
     * @param  list<string>  $slugs
     * @return list<string>
     */
    private function syncPantry(User $user, array $slugs): array
    {
        $user->pantryItems()->sync(PantryItem::whereIn('slug', $slugs)->pluck('id'));

        return [];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private function saveRestrictions(User $user, Profile $profile, array $data): array
    {
        $user->restrictions()->sync(Restriction::whereIn('slug', $data['restrictions'])->pluck('id'));
        $profile->other_restrictions = array_values(array_map(fn (string $item) => trim($item), $data['other_restrictions']));

        return [];
    }
}
```

`app/Http/Resources/OnboardingResource.php`:
```php
<?php

namespace App\Http\Resources;

use App\Models\Profile;
use App\Models\User;
use App\Services\Nutrition\GoalWeightResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/**
 * Respostas do onboarding (`GET /onboarding`, `PATCH /profile/steps/{step}`).
 *
 * @mixin User — carregue as relações de RELATIONS.
 */
class OnboardingResource extends JsonResource
{
    public const RELATIONS = ['profile', 'restrictions', 'pantryItems', 'latestWeighIn'];

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Profile $profile */
        $profile = $this->profile;

        return [
            'completed' => $profile->isOnboarded(),
            'completed_steps' => $profile->completed_steps,
            'next_step' => $profile->nextStep()?->value,
            'answers' => [
                'goal' => $profile->goal,
                'preferred_name' => $profile->preferred_name ?? Str::before($this->name, ' '),
                'age' => $profile->age,
                'height_cm' => $profile->height_cm,
                'weight_kg' => $this->currentWeightKg(),
                'sex' => $profile->sex,
                'goal_weight_kg' => $profile->goal_weight_kg === null ? null : (float) $profile->goal_weight_kg,
                'goal_weight_source' => $profile->goal_weight_source,
                'activity_level' => $profile->activity_level,
                'work_posture' => $profile->work_posture,
                'pantry_items' => $this->pantryItems->pluck('slug')->all(),
                'restrictions' => $this->restrictions->pluck('slug')->all(),
                'other_restrictions' => $profile->other_restrictions,
                'wake_time' => self::time($profile->wake_time),
                'training_time' => self::time($profile->training_time),
                'sleep_time' => self::time($profile->sleep_time),
                'training_days' => $profile->training_days,
                'lunch_place' => $profile->lunch_place,
            ],
            'healthy_weight_range' => $profile->height_cm === null ? null : app(GoalWeightResolver::class)->healthyRange((int) $profile->height_cm),
        ];
    }

    /** Coluna `time` ("06:20:00") → "06:20". */
    public static function time(mixed $value): ?string
    {
        return $value === null ? null : substr((string) $value, 0, 5);
    }
}
```

`app/Http/Controllers/Api/V1/CatalogController.php`:
```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Catalog\OnboardingCatalog;
use Illuminate\Http\JsonResponse;

class CatalogController extends Controller
{
    public function __invoke(OnboardingCatalog $catalog): JsonResponse
    {
        return response()->json(['data' => $catalog->toArray()]);
    }
}
```

`app/Http/Controllers/Api/V1/OnboardingController.php`:
```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\OnboardingResource;
use App\Models\User;
use Illuminate\Http\Request;

class OnboardingController extends Controller
{
    public function show(Request $request): OnboardingResource
    {
        /** @var User $user */
        $user = $request->user();

        return new OnboardingResource($user->load(OnboardingResource::RELATIONS));
    }
}
```

`app/Http/Controllers/Api/V1/ProfileController.php`:
```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OnboardingStep;
use App\Http\Controllers\Controller;
use App\Http\Requests\Profile\ProfileStepRequest;
use App\Http\Resources\OnboardingResource;
use App\Models\User;
use App\Services\Profile\ProfileService;
use Illuminate\Http\JsonResponse;

class ProfileController extends Controller
{
    public function updateStep(ProfileStepRequest $request, string $step, ProfileService $profiles): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $warnings = $profiles->updateStep($user, OnboardingStep::from($step), $request->validated());

        // plan_effect: RN21 entra com o plano alimentar (Plano 04); sem plano, é sempre "none".
        return (new OnboardingResource($user->fresh(OnboardingResource::RELATIONS)))
            ->additional(['meta' => ['plan_effect' => 'none', 'plan_id' => null, 'warnings' => $warnings]])
            ->response();
    }
}
```

`routes/api.php` — acrescentar os `use` de `CatalogController`, `OnboardingController`, `ProfileController` e, dentro do grupo `auth:sanctum`, depois de `Route::delete('me', …)`:
```php
        Route::get('catalog/onboarding', CatalogController::class);
        Route::get('onboarding', [OnboardingController::class, 'show']);
        Route::patch('profile/steps/{step}', [ProfileController::class, 'updateStep'])
            ->whereIn('step', ['objetivo', 'dados', 'atividade', 'preferencias', 'restricoes', 'rotina']);
```

- [ ] **Step 3: Rodar e ver passar**

Run: `docker compose run --rm api php artisan test tests/Feature/Catalog tests/Feature/Onboarding`
Expected: PASS (inclui os 28 casos do dataset de validação).

- [ ] **Step 4: Suíte, lint e commit**

Run: `docker compose run --rm api php artisan test && docker compose run --rm --no-deps api composer lint`
Expected: tudo verde (os testes do Plano 01 continuam passando com o novo `onboarded()`).

```bash
git add -A && git commit -m "feat(onboarding): catálogo, respostas salvas e etapas com meta de peso (RN08–RN12, RN34)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: Conclusão do onboarding e prévia das metas (backend)

**Files:**
- Create: `app/Services/Profile/OnboardingService.php`, `app/Http/Controllers/Api/V1/PlanController.php`
- Modify: `app/Http/Controllers/Api/V1/OnboardingController.php`, `routes/api.php`
- Test: `tests/Feature/Onboarding/CompleteOnboardingTest.php`, `tests/Feature/Onboarding/PreviewTargetsTest.php`

**Interfaces:**
- Consumes: `GoalWeightResolver`, `NutritionCalculator` (Task 2); factories `answered()`/`onboarded()` (Task 3).
- Produces:
  - `POST /api/v1/onboarding/complete` → `202 { data: { plan: null } }` (200 se já concluído); 422 `VALIDATION_ERROR` com `details.step`.
  - `GET /api/v1/plans/preview-targets` → `{ data: { kcal, protein_g, carbs_g, fat_g, meals: 5 } }`; 422 com `details.missing_steps`.
  - `OnboardingService::complete(User): void`, `firstIncompleteStep(Profile): ?OnboardingStep`, `missingForPreview(Profile): list<string>`.

- [ ] **Step 1: Testes que devem falhar**

`tests/Feature/Onboarding/CompleteOnboardingTest.php`:
```php
<?php

use App\Models\User;
use App\Models\WeighIn;

beforeEach(fn () => seedCatalog());

it('conclui, cria a primeira pesagem e responde 202 (RF07, RN34)', function () {
    $user = login(User::factory()->answered()->create());

    $this->postJson('/api/v1/onboarding/complete')
        ->assertAccepted()
        ->assertExactJson(['data' => ['plan' => null]]);

    expect($user->profile->fresh()->isOnboarded())->toBeTrue()
        ->and(WeighIn::where('user_id', $user->id)->sole()->weight_kg)->toBe(58.4)
        ->and(WeighIn::where('user_id', $user->id)->sole()->date->isToday())->toBeTrue();
    $this->getJson('/api/v1/me')->assertJsonPath('data.onboarding_completed', true)->assertJsonPath('data.next_step', null);
});

it('aplica a meta sugerida quando a pessoa não informou (CA04)', function () {
    $user = User::factory()->answered()->create();
    $user->profile->update(['goal_weight_kg' => null, 'goal_weight_source' => null]);
    login($user);

    $this->postJson('/api/v1/onboarding/complete')->assertAccepted();

    expect((float) $user->profile->fresh()->goal_weight_kg)->toBe(61.5)
        ->and($user->profile->fresh()->goal_weight_source)->toBe('suggested');
});

it('grava a meta automática de quem quer manter o peso e nenhuma de quem quer disposição (RN10)', function (string $goal, ?float $kg, ?string $source) {
    $user = User::factory()->answered()->create();
    $user->profile->update(['goal' => $goal, 'goal_weight_kg' => null, 'goal_weight_source' => null]);
    login($user);

    $this->postJson('/api/v1/onboarding/complete')->assertAccepted();

    $profile = $user->profile->fresh();
    expect($profile->goal_weight_kg === null ? null : (float) $profile->goal_weight_kg)->toBe($kg)
        ->and($profile->goal_weight_source)->toBe($source);
})->with([
    'manter' => ['manter-peso', 58.4, 'auto'],
    'disposição' => ['mais-disposicao', null, null],
]);

it('aponta a primeira etapa incompleta (422 com details.step)', function (array $steps, string $expected) {
    $user = User::factory()->answered()->create();
    $user->profile->update(['completed_steps' => $steps]);
    login($user);

    $this->postJson('/api/v1/onboarding/complete')
        ->assertUnprocessable()
        ->assertJsonPath('code', 'VALIDATION_ERROR')
        ->assertJsonPath('details.step', $expected);

    expect($user->profile->fresh()->isOnboarded())->toBeFalse();
})->with([
    'nada salvo' => [[], 'objetivo'],
    'falta a rotina' => [['objetivo', 'dados', 'atividade', 'preferencias', 'restricoes'], 'rotina'],
]);

it('aponta a etapa salva que ficou sem campo obrigatório', function () {
    $user = User::factory()->answered()->create();
    $user->profile->update(['activity_level' => null]);
    login($user);

    $this->postJson('/api/v1/onboarding/complete')->assertJsonPath('details.step', 'atividade');
});

it('é idempotente: concluir de novo responde 200 e não duplica a pesagem', function () {
    $user = login(User::factory()->answered()->create());
    $this->postJson('/api/v1/onboarding/complete')->assertAccepted();

    $this->postJson('/api/v1/onboarding/complete')
        ->assertOk()
        ->assertExactJson(['data' => ['plan' => null]]);

    expect(WeighIn::where('user_id', $user->id)->count())->toBe(1);
});
```

`tests/Feature/Onboarding/PreviewTargetsTest.php`:
```php
<?php

use App\Models\User;

it('mostra a prévia calculada pelo backend (RN13, CA10)', function () {
    login(User::factory()->answered()->create());

    $this->getJson('/api/v1/plans/preview-targets')
        ->assertOk()
        ->assertExactJson(['data' => ['kcal' => 2250, 'protein_g' => 115, 'carbs_g' => 305, 'fat_g' => 65, 'meals' => 5]]);
});

it('usa o peso atual (a pesagem mais recente)', function () {
    $user = login(User::factory()->onboarded()->create());
    $user->weighIns()->create(['date' => today(), 'weight_kg' => 70.0]);

    // 70 kg: TMB 1.429 × 1,55 × 1,10 = 2.436,5 → 2.450; P 140; G 68,1 → 70; C 319,4 → 320
    $this->getJson('/api/v1/plans/preview-targets')
        ->assertJsonPath('data', ['kcal' => 2450, 'protein_g' => 140, 'carbs_g' => 320, 'fat_g' => 70, 'meals' => 5]);
});

it('diz quais etapas faltam para a prévia', function () {
    $user = User::factory()->create();
    $user->profile->update(['goal' => 'ganhar-massa', 'completed_steps' => ['objetivo']]);
    login($user);

    $this->getJson('/api/v1/plans/preview-targets')
        ->assertUnprocessable()
        ->assertJsonPath('details.missing_steps', ['dados', 'atividade']);
});
```

Run: `docker compose run --rm api php artisan test tests/Feature/Onboarding/CompleteOnboardingTest.php tests/Feature/Onboarding/PreviewTargetsTest.php`
Expected: FAIL — 404 nas duas rotas.

- [ ] **Step 2: Implementar**

`app/Services/Profile/OnboardingService.php`:
```php
<?php

namespace App\Services\Profile;

use App\Enums\ErrorCode;
use App\Enums\Goal;
use App\Enums\OnboardingStep;
use App\Exceptions\DomainException;
use App\Models\Profile;
use App\Models\User;
use App\Services\Nutrition\GoalWeightResolver;
use Illuminate\Support\Facades\DB;

/** Conclusão do onboarding (RF07, RN08, RN10, RN34). O primeiro plano chega no Plano 04. */
class OnboardingService
{
    /** Campos que cada etapa precisa ter para concluir. `preferred_name` tem padrão (primeiro nome). */
    private const REQUIRED = [
        'objetivo' => ['goal'],
        'dados' => ['age', 'height_cm', 'start_weight_kg', 'sex'],
        'atividade' => ['activity_level', 'work_posture'],
        'preferencias' => [],
        'restricoes' => [],
        'rotina' => ['wake_time', 'training_time', 'sleep_time', 'lunch_place'],
    ];

    public function __construct(private readonly GoalWeightResolver $goalWeights) {}

    /** Idempotente: quem já concluiu não muda nada. */
    public function complete(User $user): void
    {
        $profile = $user->profile;
        if ($profile->isOnboarded()) {
            return;
        }

        $missing = $this->firstIncompleteStep($profile);
        if ($missing !== null) {
            throw new DomainException(ErrorCode::ValidationError, ['step' => $missing->value], 'Falta completar uma etapa.');
        }

        DB::transaction(function () use ($user, $profile) {
            $weight = (float) $profile->start_weight_kg;
            $user->weighIns()->updateOrCreate(['date' => today()->toDateString()], ['weight_kg' => $weight]); // RN34

            if ($profile->goal_weight_kg === null) {
                $suggested = $this->goalWeights->suggest(Goal::from((string) $profile->goal), $weight, (int) $profile->height_cm);
                $profile->goal_weight_kg = $suggested?->kg;
                $profile->goal_weight_source = $suggested?->source->value;
            }

            $profile->completed_steps = array_map(fn (OnboardingStep $step) => $step->value, OnboardingStep::cases());
            $profile->onboarding_completed_at = now();
            $profile->save();
        });
    }

    /** Primeira etapa não salva ou salva sem um campo obrigatório (RN08). */
    public function firstIncompleteStep(Profile $profile): ?OnboardingStep
    {
        foreach (self::REQUIRED as $step => $fields) {
            $empty = array_filter($fields, fn (string $field) => $profile->getAttribute($field) === null);
            if (! in_array($step, $profile->completed_steps, true) || $empty !== []) {
                return OnboardingStep::from($step);
            }
        }

        return null;
    }

    /** Etapas que a prévia das metas usa e ainda não foram salvas. @return list<string> */
    public function missingForPreview(Profile $profile): array
    {
        return array_values(array_filter(
            ['objetivo', 'dados', 'atividade'],
            fn (string $step) => ! in_array($step, $profile->completed_steps, true),
        ));
    }
}
```

Em `app/Http/Controllers/Api/V1/OnboardingController.php`, acrescentar os `use` de `App\Services\Profile\OnboardingService` e `Illuminate\Http\JsonResponse`, e o método:
```php
    public function complete(Request $request, OnboardingService $onboarding): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $alreadyDone = $user->profile->isOnboarded();

        $onboarding->complete($user);

        // O plano (e o GeneratePlanJob) chega no Plano 04; até lá, `plan` é null.
        return response()->json(['data' => ['plan' => null]], $alreadyDone ? 200 : 202);
    }
```

`app/Http/Controllers/Api/V1/PlanController.php`:
```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ActivityLevel;
use App\Enums\ErrorCode;
use App\Enums\Goal;
use App\Enums\Sex;
use App\Enums\WorkPosture;
use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Nutrition\NutritionCalculator;
use App\Services\Profile\OnboardingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlanController extends Controller
{
    /** Prévia das metas no resumo do onboarding (RF08, spec 03). */
    public function previewTargets(Request $request, OnboardingService $onboarding, NutritionCalculator $calculator): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $profile = $user->profile;

        $missing = $onboarding->missingForPreview($profile);
        if ($missing !== []) {
            throw new DomainException(ErrorCode::ValidationError, ['missing_steps' => $missing], 'Complete as etapas anteriores para ver a prévia.');
        }

        $targets = $calculator->dailyTargets(
            Goal::from((string) $profile->goal), Sex::from((string) $profile->sex), (int) $profile->age, (int) $profile->height_cm,
            (float) $user->currentWeightKg(), ActivityLevel::from((string) $profile->activity_level), WorkPosture::from((string) $profile->work_posture),
        );

        return response()->json(['data' => [
            'kcal' => $targets->kcal, 'protein_g' => $targets->proteinG, 'carbs_g' => $targets->carbsG, 'fat_g' => $targets->fatG, 'meals' => 5,
        ]]);
    }
}
```

`routes/api.php` — `use` de `PlanController` e, no grupo `auth:sanctum`:
```php
        Route::post('onboarding/complete', [OnboardingController::class, 'complete']);
        Route::get('plans/preview-targets', [PlanController::class, 'previewTargets']);
```

- [ ] **Step 3: Rodar e ver passar**

Run: `docker compose run --rm api php artisan test tests/Feature/Onboarding`
Expected: PASS.

- [ ] **Step 4: Suíte, lint e commit**

Run: `docker compose run --rm api php artisan test && docker compose run --rm --no-deps api composer lint`
Expected: tudo verde.

```bash
git add -A && git commit -m "feat(onboarding): concluir com a primeira pesagem e meta sugerida; prévia das metas (RN13)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---
### Task 5: Perfil e preferências — `GET /profile`, `PUT /profile/preferences` e RN07 (backend)

**Files:**
- Create: `app/Http/Middleware/EnsureOnboardingCompleted.php`, `app/Http/Resources/ProfileResource.php`, `app/Http/Requests/Profile/PreferencesRequest.php`, `app/Http/Controllers/Api/V1/PreferencesController.php`
- Modify: `app/Http/Controllers/Api/V1/ProfileController.php`, `bootstrap/app.php`, `config/prato.php`, `routes/api.php`
- Test: `tests/Feature/Profile/ProfileTest.php`, `tests/Feature/Profile/PreferencesTest.php`

**Interfaces:**
- Consumes: `ProfileService::updatePreferences`, `ProfileRules`, `OnboardingResource::time` (Task 3).
- Produces:
  - Middleware alias `onboarded` (RN07): `409 ONBOARDING_INCOMPLETE` com `details.next_step`.
  - `GET /api/v1/profile` (formato da spec §5, com `gym`/`city` de `config('prato.parceiro')`); `PUT /api/v1/profile/preferences` → `{ data: <profile>, meta: { plan_effect: "none", plan_id: null } }`.
  - `ProfileResource::RELATIONS`.

- [ ] **Step 1: Testes que devem falhar**

`tests/Feature/Profile/ProfileTest.php`:
```php
<?php

use App\Models\Food;
use App\Models\PantryItem;
use App\Models\Restriction;
use App\Models\User;

beforeEach(fn () => seedCatalog());

it('devolve o perfil, com o peso atual vindo da última pesagem', function () {
    $user = login(User::factory()->onboarded()->create([
        'name' => 'Camila Réus', 'email' => 'camila.reus@gmail.com', 'created_at' => '2026-08-11 09:00:00',
    ]));
    $user->restrictions()->attach(Restriction::where('slug', 'castanhas')->sole());
    $user->pantryItems()->attach(PantryItem::where('slug', 'ovos')->sole());
    $figado = Food::where('slug', 'figado-bovino')->sole();
    $user->dislikedFoods()->attach($figado);
    $user->profile->update(['other_restrictions' => ['camarão']]);
    $user->weighIns()->create(['date' => '2026-09-01', 'weight_kg' => 57.0]);
    $user->weighIns()->create(['date' => '2026-09-20', 'weight_kg' => 58.9]);

    $this->getJson('/api/v1/profile')
        ->assertOk()
        ->assertExactJson(['data' => [
            'name' => 'Camila Réus', 'preferred_name' => 'Camila', 'email' => 'camila.reus@gmail.com', 'created_at' => '2026-08-11T09:00:00-03:00',
            'goal' => 'ganhar-massa', 'sex' => 'feminino', 'age' => 27, 'height_cm' => 164,
            'start_weight_kg' => 58.4, 'current_weight_kg' => 58.9, 'goal_weight_kg' => 62.0, 'goal_weight_source' => 'user',
            'healthy_weight_range' => ['min' => 49.8, 'max' => 67.0],
            'activity_level' => 'moderado', 'work_posture' => 'sentada',
            'wake_time' => '06:20', 'training_time' => '19:00', 'sleep_time' => '23:00', 'training_days' => [1, 3, 5], 'lunch_place' => 'marmita',
            'pantry_items' => [['slug' => 'ovos', 'label' => 'Ovos']],
            'restrictions' => [['slug' => 'castanhas', 'label' => 'Amendoim e castanhas', 'is_allergy' => true]],
            'other_restrictions' => ['camarão'],
            'disliked_foods' => [['id' => $figado->id, 'name' => 'Fígado bovino']],
            'gym' => 'Zfit', 'city' => 'Capivari de Baixo',
        ]]);
});

it('exige o onboarding concluído e diz a próxima etapa (RN07)', function () {
    login();

    $this->getJson('/api/v1/profile')
        ->assertStatus(409)
        ->assertJsonPath('code', 'ONBOARDING_INCOMPLETE')
        ->assertJsonPath('details.next_step', 'objetivo');
});

it('exige login', function () {
    $this->getJson('/api/v1/profile')->assertUnauthorized();
});
```

`tests/Feature/Profile/PreferencesTest.php`:
```php
<?php

use App\Models\Food;
use App\Models\PantryItem;
use App\Models\Restriction;
use App\Models\User;

beforeEach(fn () => seedCatalog());

function preferencesPayload(array $overrides = []): array
{
    return array_merge([
        'restrictions' => ['castanhas', 'lactose'],
        'other_restrictions' => ['camarão'],
        'pantry_items' => ['ovos', 'maca'],
        'disliked_food_ids' => [Food::where('slug', 'figado-bovino')->value('id')],
    ], $overrides);
}

it('substitui as quatro listas de uma vez (RF17)', function () {
    $user = login(User::factory()->onboarded()->create());
    $user->restrictions()->attach(Restriction::where('slug', 'gluten')->sole());
    $user->pantryItems()->attach(PantryItem::where('slug', 'frango')->sole());

    $this->putJson('/api/v1/profile/preferences', preferencesPayload())
        ->assertOk()
        ->assertJsonPath('data.restrictions.*.slug', ['lactose', 'castanhas'])
        ->assertJsonPath('data.pantry_items.*.slug', ['ovos', 'maca'])
        ->assertJsonPath('data.other_restrictions', ['camarão'])
        ->assertJsonPath('data.disliked_foods.*.name', ['Fígado bovino'])
        ->assertJsonPath('meta', ['plan_effect' => 'none', 'plan_id' => null]);
});

it('aceita listas vazias', function () {
    login(User::factory()->onboarded()->create());

    $this->putJson('/api/v1/profile/preferences', ['restrictions' => [], 'other_restrictions' => [], 'pantry_items' => [], 'disliked_food_ids' => []])
        ->assertOk()
        ->assertJsonPath('data.restrictions', [])
        ->assertJsonPath('data.disliked_foods', []);
});

it('só aceita em "prefiro não ver" alimentos da lista de "não curto"', function () {
    $user = login(User::factory()->onboarded()->create());
    $arroz = Food::where('slug', 'arroz-branco-cozido')->value('id');

    $this->putJson('/api/v1/profile/preferences', preferencesPayload(['disliked_food_ids' => [$arroz]]))
        ->assertJsonValidationErrors(['disliked_food_ids.0' => 'Confira os alimentos que você prefere não ver.']);

    expect($user->dislikedFoods()->count())->toBe(0);
});

it('exige as quatro listas (substituição completa)', function (string $missing) {
    login(User::factory()->onboarded()->create());
    $payload = preferencesPayload();
    unset($payload[$missing]);

    $this->putJson('/api/v1/profile/preferences', $payload)->assertJsonValidationErrors([$missing]);
})->with(['restrictions', 'other_restrictions', 'pantry_items', 'disliked_food_ids']);

it('exige o onboarding concluído (RN07)', function () {
    login(User::factory()->answered()->create());

    $this->putJson('/api/v1/profile/preferences', preferencesPayload())
        ->assertStatus(409)
        ->assertJsonPath('details.next_step', 'resumo');
});
```

Run: `docker compose run --rm api php artisan test tests/Feature/Profile`
Expected: FAIL — 404 em `/profile` e `/profile/preferences`.

- [ ] **Step 2: Implementar**

`config/prato.php` — acrescentar ao array:
```php
    // Parceiro do projeto de extensão, mostrado no Perfil (constante do projeto, não do usuário).
    'parceiro' => [
        'gym' => 'Zfit',
        'city' => 'Capivari de Baixo',
    ],
```

`app/Http/Middleware/EnsureOnboardingCompleted.php`:
```php
<?php

namespace App\Http\Middleware;

use App\Enums\ErrorCode;
use App\Exceptions\DomainException;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** RN07 — rotas do app só depois do onboarding. */
class EnsureOnboardingCompleted
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var User $user */
        $user = $request->user();
        $profile = $user->profile;

        if (! $profile->isOnboarded()) {
            throw new DomainException(ErrorCode::OnboardingIncomplete, ['next_step' => $profile->nextStep()?->value]);
        }

        return $next($request);
    }
}
```

Em `bootstrap/app.php`, dentro de `->withMiddleware(...)`, acrescentar (com o `use App\Http\Middleware\EnsureOnboardingCompleted;` no topo):
```php
        $middleware->alias(['onboarded' => EnsureOnboardingCompleted::class]);
```

`app/Http/Resources/ProfileResource.php`:
```php
<?php

namespace App\Http\Resources;

use App\Models\Food;
use App\Models\PantryItem;
use App\Models\Profile;
use App\Models\Restriction;
use App\Models\User;
use App\Services\Nutrition\GoalWeightResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/**
 * Perfil de quem concluiu o onboarding (`GET /profile`, `PUT /profile/preferences`).
 *
 * @mixin User — carregue as relações de RELATIONS.
 */
class ProfileResource extends JsonResource
{
    public const RELATIONS = ['profile', 'restrictions', 'pantryItems', 'dislikedFoods', 'latestWeighIn'];

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Profile $profile */
        $profile = $this->profile;

        return [
            'name' => $this->name,
            'preferred_name' => $profile->preferred_name ?? Str::before($this->name, ' '),
            'email' => $this->email,
            'created_at' => $this->created_at?->toIso8601String(),
            'goal' => $profile->goal,
            'sex' => $profile->sex,
            'age' => $profile->age,
            'height_cm' => $profile->height_cm,
            'start_weight_kg' => (float) $profile->start_weight_kg,
            'current_weight_kg' => $this->currentWeightKg(),
            'goal_weight_kg' => $profile->goal_weight_kg === null ? null : (float) $profile->goal_weight_kg,
            'goal_weight_source' => $profile->goal_weight_source,
            'healthy_weight_range' => app(GoalWeightResolver::class)->healthyRange((int) $profile->height_cm),
            'activity_level' => $profile->activity_level,
            'work_posture' => $profile->work_posture,
            'wake_time' => OnboardingResource::time($profile->wake_time),
            'training_time' => OnboardingResource::time($profile->training_time),
            'sleep_time' => OnboardingResource::time($profile->sleep_time),
            'training_days' => $profile->training_days,
            'lunch_place' => $profile->lunch_place,
            'pantry_items' => $this->pantryItems->map(fn (PantryItem $item) => ['slug' => $item->slug, 'label' => $item->label])->all(),
            'restrictions' => $this->restrictions->map(fn (Restriction $r) => ['slug' => $r->slug, 'label' => $r->label, 'is_allergy' => $r->is_allergy])->all(),
            'other_restrictions' => $profile->other_restrictions,
            'disliked_foods' => $this->dislikedFoods->map(fn (Food $food) => ['id' => $food->id, 'name' => $food->name])->all(),
            'gym' => config('prato.parceiro.gym'),
            'city' => config('prato.parceiro.city'),
        ];
    }
}
```

`app/Http/Requests/Profile/PreferencesRequest.php`:
```php
<?php

namespace App\Http\Requests\Profile;

use App\Http\Requests\Concerns\ProfileRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Preferências e restrições (RF17): as quatro listas, sempre inteiras. */
class PreferencesRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...ProfileRules::restrictions(),
            ...ProfileRules::pantry(),
            'disliked_food_ids' => ['present', 'array'],
            'disliked_food_ids.*' => ['integer', 'distinct', Rule::exists('foods', 'id')->where('common_dislike', true)],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ProfileRules::messages();
    }
}
```

`app/Http/Controllers/Api/V1/PreferencesController.php`:
```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Profile\PreferencesRequest;
use App\Http\Resources\ProfileResource;
use App\Models\User;
use App\Services\Profile\ProfileService;
use Illuminate\Http\JsonResponse;

class PreferencesController extends Controller
{
    public function __invoke(PreferencesRequest $request, ProfileService $profiles): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $profiles->updatePreferences($user, $request->validated());

        // plan_effect: RN21 (regeneração ao mudar restrição) entra com o plano, no Plano 04.
        return (new ProfileResource($user->fresh(ProfileResource::RELATIONS)))
            ->additional(['meta' => ['plan_effect' => 'none', 'plan_id' => null]])
            ->response();
    }
}
```

Em `app/Http/Controllers/Api/V1/ProfileController.php`, acrescentar (com os `use` de `ProfileResource` e `Illuminate\Http\Request`):
```php
    public function show(Request $request): ProfileResource
    {
        /** @var User $user */
        $user = $request->user();

        return new ProfileResource($user->load(ProfileResource::RELATIONS));
    }
```

`routes/api.php` — `use` de `PreferencesController` e, no grupo `auth:sanctum`:
```php
        Route::middleware('onboarded')->group(function () {
            Route::get('profile', [ProfileController::class, 'show']);
            Route::put('profile/preferences', PreferencesController::class);
        });
```

- [ ] **Step 3: Rodar e ver passar**

Run: `docker compose run --rm api php artisan test tests/Feature/Profile`
Expected: PASS.

- [ ] **Step 4: Suíte, lint e commit**

Run: `docker compose run --rm api php artisan test && docker compose run --rm --no-deps api composer lint`
Expected: tudo verde.

```bash
git add -A && git commit -m "feat(perfil): perfil, preferências e bloqueio de rotas antes do onboarding (RN07)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---
### Task 6: Front — tipos, chamadas, regras espelho, hooks e MSW do onboarding

**Files (repo front):**
- Create: `src/lib/chaves.ts`, `src/lib/api/onboarding.ts`, `src/lib/api/perfil.ts`
- Create: `src/features/onboarding/{tipos,etapas,regras,hooks}.ts`, `src/features/perfil/{tipos,hooks}.ts`
- Create: `src/mocks/fixtures/onboarding.ts`, `src/mocks/handlers/onboarding.ts`; Modify: `src/mocks/handlers/index.ts`
- Test: `src/features/onboarding/regras.test.ts`

**Interfaces:**
- Consumes: `api` (Plano 02), `CHAVE_ME` (`src/features/auth/hooks.ts`), `url` (MSW, Plano 02).
- Produces:
  - Tipos: `Sexo`, `PosturaTrabalho`, `LocalAlmoco`, `OrigemDaMeta`, `EtapaEditavel`, `EfeitoNoPlano`, `Catalogo`, `Respostas`, `FaixaSaudavel`, `DadosOnboarding`, `MetaDaResposta`, `Previa` (`features/onboarding/tipos.ts`); `Perfil`, `EntradaPreferencias` (`features/perfil/tipos.ts`).
  - API: `getCatalogo()`, `getOnboarding()`, `salvarEtapa(etapa, corpo)` → `{ data: DadosOnboarding; meta: MetaDaResposta }`, `concluirOnboarding()`, `getPrevia()`; `getPerfil()`, `salvarPreferencias(e)`.
  - `CHAVES` (`catalogo`, `onboarding`, `previa`, `perfil`).
  - Hooks: `useCatalogo()`, `useDadosOnboarding()`, `useSalvarEtapa(etapa)`, `usePrevia()`, `useConcluirOnboarding()`; `usePerfil()`, `useSalvarPreferencias()`.
  - `etapas.ts`: `ETAPAS`, `TOTAL_ETAPAS`, `numeroDaEtapa`, `etapaAnterior`, `proximaEtapa`, `TEXTOS` (título e descrição de cada etapa).
  - `regras.ts`: `MENSAGENS`, `AVISO_META_FORA`, `faixaSaudavel`, `alturaValida`, `metaForaDaFaixa`, `pedeMeta`, `lerNumero`, `escreverNumero`, `separarOutrasRestricoes`, `validarDados(CamposDados, Goal|null)`, `validarRotina(CamposRotina)`, `erroDe(erros, campo)`.
  - MSW: `catalogoApi`, `respostasVazias`, `respostasDaCamila`, `onboardingApi(parcial)`, `perfilApi`, `previaApi`; `handlersOnboarding` (catálogo + onboarding vazio) nos handlers padrão.

- [ ] **Step 1: Branch e teste das regras que deve falhar**

```bash
cd /home/alvez/atividade-extensionista/frontend
git switch plano-02-fundacao-contas && git switch -c plano-03-onboarding
```

`src/features/onboarding/regras.test.ts`:
```ts
import { describe, expect, it } from 'vitest';
import {
  erroDe,
  escreverNumero,
  faixaSaudavel,
  lerNumero,
  MENSAGENS,
  separarOutrasRestricoes,
  validarDados,
  validarRotina,
} from './regras';

describe('faixaSaudavel (espelha GoalWeightResolver)', () => {
  it.each([
    [164, 49.8, 67],
    [120, 26.6, 35.9],
    [178, 58.6, 78.9],
  ])('%i cm → %f a %f kg', (altura, min, max) => {
    expect(faixaSaudavel(altura)).toEqual({ min, max });
  });
});

describe('lerNumero e escreverNumero', () => {
  it.each([
    ['58,4', 58.4],
    ['58.4', 58.4],
    [' 70 ', 70],
    ['', null],
  ])('%j → %j', (texto, numero) => {
    expect(lerNumero(texto)).toBe(numero);
  });

  it.each(['abc', '5,8,4', '-3'])('%j não é número', (texto) => {
    expect(lerNumero(texto)).toBeNaN();
  });

  it('escreve com vírgula e deixa vazio o que não existe', () => {
    expect(escreverNumero(58.4)).toBe('58,4');
    expect(escreverNumero(null)).toBe('');
  });
});

describe('separarOutrasRestricoes', () => {
  it('separa por vírgula, sem vazios nem repetidos', () => {
    expect(separarOutrasRestricoes('camarão, pimenta,, Camarão ,')).toEqual(['camarão', 'pimenta']);
  });
});

describe('validarDados', () => {
  const ok = { preferredName: 'Camila', age: '27', heightCm: '164', weightKg: '58,4', sex: 'feminino' as const, goalWeightKg: '62' };

  it('aceita os dados da Camila', () => {
    expect(validarDados(ok, 'ganhar-massa')).toEqual({});
  });

  it.each([
    ['nome em branco', { preferredName: '  ' }, 'preferredName', MENSAGENS.nome],
    ['idade 17', { age: '17' }, 'age', MENSAGENS.idade],
    ['idade quebrada', { age: '27,5' }, 'age', MENSAGENS.idade],
    ['altura 119', { heightCm: '119' }, 'heightCm', MENSAGENS.altura],
    ['peso com 2 casas', { weightKg: '58,45' }, 'weightKg', MENSAGENS.peso],
    ['peso em texto', { weightKg: 'abc' }, 'weightKg', MENSAGENS.peso],
    ['sem sexo', { sex: null }, 'sex', MENSAGENS.sexo],
    ['meta abaixo do peso ao ganhar (CA02)', { goalWeightKg: '55' }, 'goalWeightKg', MENSAGENS.metaGanhar],
    ['meta acima do limite', { goalWeightKg: '251' }, 'goalWeightKg', MENSAGENS.metaFaixa],
  ])('%s', (_, troca, campo, mensagem) => {
    expect(validarDados({ ...ok, ...troca }, 'ganhar-massa')).toEqual({ [campo]: mensagem });
  });

  it('meta acima do peso ao perder', () => {
    expect(validarDados(ok, 'perder-gordura')).toEqual({ goalWeightKg: MENSAGENS.metaPerder });
  });

  it('ignora a meta quando o objetivo não usa meta', () => {
    expect(validarDados({ ...ok, goalWeightKg: '10' }, 'manter-peso')).toEqual({});
  });
});

describe('validarRotina (RN12)', () => {
  const ok = { wakeTime: '06:20', trainingTime: '19:00', sleepTime: '23:00', lunchPlace: 'marmita' as const };

  it('aceita a rotina do mock', () => {
    expect(validarRotina(ok)).toEqual({});
  });

  it('treino antes de acordar (CA06)', () => {
    expect(validarRotina({ ...ok, trainingTime: '05:00' })).toEqual({ trainingTime: MENSAGENS.treinoForaDaJanela });
  });

  it('aceita dormir depois da meia-noite', () => {
    expect(validarRotina({ ...ok, wakeTime: '10:00', trainingTime: '23:00', sleepTime: '01:30' })).toEqual({});
  });

  it('pede pelo menos 12 horas acordado', () => {
    expect(validarRotina({ ...ok, wakeTime: '09:00', trainingTime: '12:00', sleepTime: '20:00' })).toEqual({ sleepTime: MENSAGENS.janela });
  });

  it('pede o lugar do almoço e o formato da hora', () => {
    expect(validarRotina({ ...ok, wakeTime: '6h', lunchPlace: null })).toEqual({ wakeTime: MENSAGENS.horario, lunchPlace: MENSAGENS.almoco });
  });
});

describe('erroDe', () => {
  it('acha o erro do campo ou de um item dele', () => {
    expect(erroDe({ goalWeightKg: 'a' }, 'goalWeightKg')).toBe('a');
    expect(erroDe({ 'otherRestrictions.1': 'b' }, 'otherRestrictions')).toBe('b');
    expect(erroDe({}, 'age')).toBeUndefined();
  });
});
```

Run: `docker compose run --rm web npx vitest run --project unit src/features/onboarding`
Expected: FAIL — `Failed to resolve import "./regras"`.

- [ ] **Step 2: Tipos, chaves e chamadas**

`src/lib/chaves.ts`:
```ts
/** Chaves do React Query usadas por mais de uma feature. `['me']` fica em `features/auth/hooks.ts`. */
export const CHAVES = {
  catalogo: ['catalogo'],
  onboarding: ['onboarding'],
  previa: ['previa'],
  perfil: ['perfil'],
} as const;
```

`src/features/onboarding/tipos.ts`:
```ts
import type { ActivityLevel, EtapaOnboarding, Goal } from '@/lib/types';

export type Sexo = 'feminino' | 'masculino' | 'nao-dizer';
export type PosturaTrabalho = 'sentada' | 'em-pe' | 'peso-pesado';
export type LocalAlmoco = 'casa' | 'marmita' | 'restaurante';
export type OrigemDaMeta = 'user' | 'suggested' | 'auto';
export type EtapaEditavel = Exclude<EtapaOnboarding, 'resumo'>;
/** RN21 — neste plano a API sempre devolve `none`; o Plano 04 liga os outros. */
export type EfeitoNoPlano = 'none' | 'regeneration_suggested' | 'regeneration_started' | 'times_updated';

/** `GET /catalog/onboarding`, já em camelCase. */
export interface Catalogo {
  goals: { value: Goal; label: string; description: string }[];
  activityLevels: { value: ActivityLevel; label: string; description: string }[];
  workPostures: { value: PosturaTrabalho; label: string }[];
  restrictions: { slug: string; label: string; isAllergy: boolean }[];
  pantry: { category: string; label: string; items: { slug: string; label: string }[] }[];
  dislikeOptions: { id: number; name: string }[];
  lunchPlaces: { value: LocalAlmoco; label: string }[];
}

export interface Respostas {
  goal: Goal | null;
  preferredName: string | null;
  age: number | null;
  heightCm: number | null;
  weightKg: number | null;
  sex: Sexo | null;
  goalWeightKg: number | null;
  goalWeightSource: OrigemDaMeta | null;
  activityLevel: ActivityLevel | null;
  workPosture: PosturaTrabalho | null;
  pantryItems: string[];
  restrictions: string[];
  otherRestrictions: string[];
  wakeTime: string | null;
  trainingTime: string | null;
  sleepTime: string | null;
  trainingDays: number[];
  lunchPlace: LocalAlmoco | null;
}

export interface FaixaSaudavel {
  min: number;
  max: number;
}

/** `GET /onboarding`. */
export interface DadosOnboarding {
  completed: boolean;
  completedSteps: EtapaOnboarding[];
  nextStep: EtapaOnboarding | null;
  answers: Respostas;
  healthyWeightRange: FaixaSaudavel | null;
}

export interface MetaDaResposta {
  planEffect: EfeitoNoPlano;
  planId: number | null;
  /** Códigos: `GOAL_WEIGHT_OUT_OF_HEALTHY_RANGE`, `GOAL_WEIGHT_RESET`. */
  warnings: string[];
}

/** `GET /plans/preview-targets` (RN13). */
export interface Previa {
  kcal: number;
  proteinG: number;
  carbsG: number;
  fatG: number;
  meals: number;
}
```

`src/features/perfil/tipos.ts`:
```ts
import type { FaixaSaudavel, EfeitoNoPlano, LocalAlmoco, OrigemDaMeta, PosturaTrabalho, Sexo } from '@/features/onboarding/tipos';
import type { ActivityLevel, Goal } from '@/lib/types';

/** `GET /profile`, já em camelCase. */
export interface Perfil {
  name: string;
  preferredName: string;
  email: string;
  createdAt: string;
  goal: Goal;
  sex: Sexo;
  age: number;
  heightCm: number;
  startWeightKg: number;
  currentWeightKg: number;
  goalWeightKg: number | null;
  goalWeightSource: OrigemDaMeta | null;
  healthyWeightRange: FaixaSaudavel;
  activityLevel: ActivityLevel;
  workPosture: PosturaTrabalho;
  wakeTime: string;
  trainingTime: string;
  sleepTime: string;
  trainingDays: number[];
  lunchPlace: LocalAlmoco;
  pantryItems: { slug: string; label: string }[];
  restrictions: { slug: string; label: string; isAllergy: boolean }[];
  otherRestrictions: string[];
  dislikedFoods: { id: number; name: string }[];
  gym: string;
  city: string;
}

/** `PUT /profile/preferences` — as quatro listas, sempre inteiras. */
export interface EntradaPreferencias {
  restrictions: string[];
  otherRestrictions: string[];
  pantryItems: string[];
  dislikedFoodIds: number[];
}

export interface RespostaPreferencias {
  data: Perfil;
  meta: { planEffect: EfeitoNoPlano; planId: number | null };
}
```

`src/lib/api/onboarding.ts`:
```ts
import type { Catalogo, DadosOnboarding, EtapaEditavel, MetaDaResposta, Previa } from '@/features/onboarding/tipos';
import { api } from './client';

type Dados<T> = { data: T };

/** GET /catalog/onboarding — opções de todas as etapas. */
export const getCatalogo = () => api<Dados<Catalogo>>('/catalog/onboarding').then((r) => r.data);

/** GET /onboarding — respostas salvas e próxima etapa. */
export const getOnboarding = () => api<Dados<DadosOnboarding>>('/onboarding').then((r) => r.data);

/** PATCH /profile/steps/{etapa} */
export const salvarEtapa = (etapa: EtapaEditavel, corpo: Record<string, unknown>) =>
  api<Dados<DadosOnboarding> & { meta: MetaDaResposta }>(`/profile/steps/${etapa}`, { method: 'PATCH', body: corpo });

/** POST /onboarding/complete — o plano chega no Plano 04 (`plan: null` até lá). */
export const concluirOnboarding = () =>
  api<Dados<{ plan: { id: number; status: string } | null }>>('/onboarding/complete', { method: 'POST' }).then((r) => r.data);

/** GET /plans/preview-targets — prévia das metas (RN13). */
export const getPrevia = () => api<Dados<Previa>>('/plans/preview-targets').then((r) => r.data);
```

`src/lib/api/perfil.ts`:
```ts
import type { EntradaPreferencias, Perfil, RespostaPreferencias } from '@/features/perfil/tipos';
import { api } from './client';

/** GET /profile */
export const getPerfil = () => api<{ data: Perfil }>('/profile').then((r) => r.data);

/** PUT /profile/preferences */
export const salvarPreferencias = (entrada: EntradaPreferencias) =>
  api<RespostaPreferencias>('/profile/preferences', { method: 'PUT', body: entrada });
```

- [ ] **Step 3: Etapas e regras**

`src/features/onboarding/etapas.ts`:
```ts
import type { EtapaOnboarding } from '@/lib/types';

/** Ordem do fluxo (RN08). */
export const ETAPAS: EtapaOnboarding[] = ['objetivo', 'dados', 'atividade', 'preferencias', 'restricoes', 'rotina', 'resumo'];
export const TOTAL_ETAPAS = ETAPAS.length;

export const numeroDaEtapa = (etapa: EtapaOnboarding) => ETAPAS.indexOf(etapa) + 1;
export const etapaAnterior = (etapa: EtapaOnboarding): EtapaOnboarding | null => ETAPAS[ETAPAS.indexOf(etapa) - 1] ?? null;
export const proximaEtapa = (etapa: EtapaOnboarding): EtapaOnboarding | null => ETAPAS[ETAPAS.indexOf(etapa) + 1] ?? null;

/** Textos do mock (🔵) de cada etapa. */
export const TEXTOS: Record<EtapaOnboarding, { titulo: string; descricao: string }> = {
  objetivo: { titulo: 'Qual é seu objetivo agora?', descricao: 'Ele define suas calorias e a quantidade de proteína do dia.' },
  dados: { titulo: 'Agora, seus dados', descricao: 'Ficam só no seu perfil. Ninguém da academia vê.' },
  atividade: { titulo: 'Quantas vezes você treina?', descricao: 'Conte só o que acontece de verdade numa semana comum.' },
  preferencias: { titulo: 'O que costuma ter na sua cozinha?', descricao: 'Marque o que você come sem reclamar. Seu cardápio sai daqui.' },
  restricoes: { titulo: 'Tem algo que você não pode comer?', descricao: 'O Nutri nunca sugere um alimento marcado aqui, nem nas substituições.' },
  rotina: { titulo: 'Como é o seu dia?', descricao: 'Os horários das refeições saem daqui, inclusive o pré-treino.' },
  resumo: { titulo: 'Confere se está certo', descricao: 'Qualquer linha pode ser ajustada agora ou depois, no perfil.' },
};
```

`src/features/onboarding/regras.ts`:
```ts
import type { Goal } from '@/lib/types';
import type { FaixaSaudavel, LocalAlmoco, Sexo } from './tipos';

/** Mensagens iguais às do backend (ProfileStepRequest). */
export const MENSAGENS = {
  objetivo: 'Escolha um objetivo.',
  nome: 'Diga como podemos te chamar (até 40 letras).',
  idade: 'Use uma idade entre 18 e 100 anos.',
  altura: 'Use a altura em centímetros, entre 120 e 230.',
  peso: 'Use um peso entre 30 e 250 kg, com até uma casa decimal.',
  sexo: 'Escolha uma opção.',
  metaFaixa: 'Use uma meta entre 30 e 250 kg.',
  metaGanhar: 'Para ganhar massa, a meta precisa ser maior que o peso de hoje.',
  metaPerder: 'Para perder gordura, a meta precisa ser menor que o peso de hoje.',
  outrasMuitas: 'Use no máximo 10 itens.',
  outraTamanho: 'Cada item precisa ter de 2 a 60 letras.',
  horario: 'Use o formato 06:20.',
  janela: 'Seu dia acordado precisa ter pelo menos 12 horas.',
  treinoForaDaJanela: 'O treino precisa estar entre a hora que você acorda e a que dorme.',
  almoco: 'Escolha onde você almoça.',
} as const;

/** RN10: aviso que não bloqueia. */
export const AVISO_META_FORA =
  'Essa meta fica fora da faixa saudável para a sua altura. Tudo bem seguir — vale conversar com um profissional.';

type Erros<C extends string> = Partial<Record<C, string>>;

function limpar<C extends string>(erros: Erros<C>): Erros<C> {
  return Object.fromEntries(Object.entries(erros).filter(([, mensagem]) => mensagem)) as Erros<C>;
}

const arredondar1 = (n: number) => Math.round(n * 10) / 10;

/** IMC 18,5–24,9 para a altura, 1 casa — espelha `GoalWeightResolver::healthyRange`. */
export function faixaSaudavel(alturaCm: number): FaixaSaudavel {
  const quadrado = (alturaCm / 100) ** 2;
  return { min: arredondar1(18.5 * quadrado), max: arredondar1(24.9 * quadrado) };
}

export const alturaValida = (cm: number | null): cm is number => cm !== null && Number.isInteger(cm) && cm >= 120 && cm <= 230;

export function metaForaDaFaixa(metaKg: number, alturaCm: number) {
  const { min, max } = faixaSaudavel(alturaCm);
  return metaKg < min || metaKg > max;
}

/** RN10: só ganhar e perder pedem meta. */
export const pedeMeta = (objetivo: Goal | null) => objetivo === 'ganhar-massa' || objetivo === 'perder-gordura';

/** "58,4" ou "58.4" → 58.4; vazio → null; qualquer outra coisa → NaN. */
export function lerNumero(texto: string): number | null {
  const limpo = texto.trim().replace(',', '.');
  if (limpo === '') return null;
  return /^\d+(\.\d+)?$/.test(limpo) ? Number(limpo) : Number.NaN;
}

export const escreverNumero = (n: number | null | undefined) => (n == null ? '' : String(n).replace('.', ','));

/** "camarão, pimenta,," → ["camarão", "pimenta"] — sem vazios nem repetidos (ignorando maiúsculas). */
export function separarOutrasRestricoes(texto: string): string[] {
  const vistos = new Set<string>();
  return texto
    .split(',')
    .map((item) => item.trim())
    .filter((item) => {
      const chave = item.toLocaleLowerCase('pt-BR');
      if (!item || vistos.has(chave)) return false;
      vistos.add(chave);
      return true;
    });
}

const inteiroEntre = (texto: string, min: number, max: number) => {
  const limpo = texto.trim();
  return /^\d{1,3}$/.test(limpo) && Number(limpo) >= min && Number(limpo) <= max;
};

const umaCasaEntre = (texto: string, min: number, max: number) => {
  const limpo = texto.trim();
  if (!/^\d{1,3}([.,]\d)?$/.test(limpo)) return false;
  const n = Number(limpo.replace(',', '.'));
  return n >= min && n <= max;
};

export interface CamposDados {
  preferredName: string;
  age: string;
  heightCm: string;
  weightKg: string;
  sex: Sexo | null;
  goalWeightKg: string;
}

/** Etapa `dados` (RN09, RN10), com as mensagens do backend. */
export function validarDados(d: CamposDados, objetivo: Goal | null): Erros<keyof CamposDados> {
  const nome = d.preferredName.trim();
  const pesoOk = umaCasaEntre(d.weightKg, 30, 250);

  let meta: string | undefined;
  if (pedeMeta(objetivo) && d.goalWeightKg.trim() !== '') {
    if (!umaCasaEntre(d.goalWeightKg, 30, 250)) {
      meta = MENSAGENS.metaFaixa;
    } else if (pesoOk) {
      const alvo = lerNumero(d.goalWeightKg) as number;
      const peso = lerNumero(d.weightKg) as number;
      if (objetivo === 'ganhar-massa' && alvo <= peso) meta = MENSAGENS.metaGanhar;
      if (objetivo === 'perder-gordura' && alvo >= peso) meta = MENSAGENS.metaPerder;
    }
  }

  return limpar({
    preferredName: nome.length < 1 || nome.length > 40 ? MENSAGENS.nome : undefined,
    age: inteiroEntre(d.age, 18, 100) ? undefined : MENSAGENS.idade,
    heightCm: inteiroEntre(d.heightCm, 120, 230) ? undefined : MENSAGENS.altura,
    weightKg: pesoOk ? undefined : MENSAGENS.peso,
    sex: d.sex ? undefined : MENSAGENS.sexo,
    goalWeightKg: meta,
  });
}

export interface CamposRotina {
  wakeTime: string;
  trainingTime: string;
  sleepTime: string;
  lunchPlace: LocalAlmoco | null;
}

const HORA = /^([01]\d|2[0-3]):[0-5]\d$/;
const minutos = (hora: string) => {
  const [h, m] = hora.split(':').map(Number);
  return h * 60 + m;
};

/** Etapa `rotina` (RN12) — espelha o `after()` do ProfileStepRequest. */
export function validarRotina(r: CamposRotina): Erros<keyof CamposRotina> {
  const erros: Erros<keyof CamposRotina> = {};
  for (const campo of ['wakeTime', 'trainingTime', 'sleepTime'] as const) {
    if (!HORA.test(r[campo])) erros[campo] = MENSAGENS.horario;
  }
  if (!r.lunchPlace) erros.lunchPlace = MENSAGENS.almoco;
  if (erros.wakeTime || erros.trainingTime || erros.sleepTime) return erros;

  const acorda = minutos(r.wakeTime);
  let dorme = minutos(r.sleepTime);
  let treino = minutos(r.trainingTime);
  if (dorme <= acorda) dorme += 24 * 60; // dorme depois da meia-noite
  if (dorme - acorda < 12 * 60) return { ...erros, sleepTime: MENSAGENS.janela };
  if (treino < acorda) treino += 24 * 60;
  if (treino >= dorme) erros.trainingTime = MENSAGENS.treinoForaDaJanela;
  return erros;
}

/** Primeira mensagem do campo ou de um item dele ("otherRestrictions.1"), vinda de um 422. */
export function erroDe(erros: Record<string, string>, campo: string): string | undefined {
  return erros[campo] ?? Object.entries(erros).find(([chave]) => chave.startsWith(`${campo}.`))?.[1];
}
```

Run: `docker compose run --rm web npx vitest run --project unit src/features/onboarding`
Expected: PASS.

- [ ] **Step 4: Hooks**

`src/features/onboarding/hooks.ts`:
```ts
'use client';

import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { CHAVE_ME } from '@/features/auth/hooks';
import * as onboarding from '@/lib/api/onboarding';
import { CHAVES } from '@/lib/chaves';
import type { EtapaEditavel } from './tipos';

/** O catálogo só muda com deploy: busca uma vez por sessão. */
export const useCatalogo = () => useQuery({ queryKey: CHAVES.catalogo, queryFn: onboarding.getCatalogo, staleTime: Infinity });

export const useDadosOnboarding = () => useQuery({ queryKey: CHAVES.onboarding, queryFn: onboarding.getOnboarding });

export function useSalvarEtapa(etapa: EtapaEditavel) {
  const cliente = useQueryClient();
  return useMutation({
    mutationFn: (corpo: Record<string, unknown>) => onboarding.salvarEtapa(etapa, corpo),
    onSuccess: (resposta) => {
      cliente.setQueryData(CHAVES.onboarding, resposta.data);
      cliente.removeQueries({ queryKey: CHAVES.previa });
      void cliente.invalidateQueries({ queryKey: CHAVE_ME }); // next_step e nome preferido mudam
      void cliente.invalidateQueries({ queryKey: CHAVES.perfil });
    },
  });
}

/** A prévia falhar não impede gerar o plano: sem novas tentativas. */
export const usePrevia = () => useQuery({ queryKey: CHAVES.previa, queryFn: onboarding.getPrevia, retry: false });

/** Quem chama navega com recarga (`recarregarEm`), que descarta o `['me']` de onboarding incompleto. */
export const useConcluirOnboarding = () => useMutation({ mutationFn: onboarding.concluirOnboarding });
```

`src/features/perfil/hooks.ts`:
```ts
'use client';

import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { getPerfil, salvarPreferencias } from '@/lib/api/perfil';
import { CHAVES } from '@/lib/chaves';

export const usePerfil = () => useQuery({ queryKey: CHAVES.perfil, queryFn: getPerfil });

export function useSalvarPreferencias() {
  const cliente = useQueryClient();
  return useMutation({
    mutationFn: salvarPreferencias,
    onSuccess: (resposta) => {
      cliente.setQueryData(CHAVES.perfil, resposta.data);
      void cliente.invalidateQueries({ queryKey: CHAVES.onboarding });
    },
  });
}
```

- [ ] **Step 5: MSW**

`src/mocks/fixtures/onboarding.ts`:
```ts
/** Respostas da API (snake_case) para stories e testes — espelham os seeders e a Camila do mock. */

export const catalogoApi = {
  goals: [
    { value: 'ganhar-massa', label: 'Ganhar massa magra', description: 'Comer um pouco acima do gasto, com proteína alta todo dia.' },
    { value: 'perder-gordura', label: 'Perder gordura', description: 'Déficit leve, mantendo a força nos treinos.' },
    { value: 'manter-peso', label: 'Manter o peso', description: 'Organizar os horários e equilibrar o que você já come.' },
    { value: 'mais-disposicao', label: 'Ter mais disposição', description: 'Energia para o treino sem chegar arrastada no fim do dia.' },
  ],
  activity_levels: [
    { value: 'parado', label: 'Quase não treino', description: 'Menos de um treino por semana.' },
    { value: 'leve', label: '1 ou 2 vezes na semana', description: 'Musculação leve ou caminhada.' },
    { value: 'moderado', label: '3 ou 4 vezes na semana', description: 'O ritmo da maior parte do pessoal da Zfit.' },
    { value: 'intenso', label: '5 ou 6 vezes na semana', description: 'Treino puxado quase todo dia.' },
  ],
  work_postures: [
    { value: 'sentada', label: 'Sentada' },
    { value: 'em-pe', label: 'Em pé' },
    { value: 'peso-pesado', label: 'Peso pesado' },
  ],
  restrictions: [
    { slug: 'lactose', label: 'Intolerância a lactose', is_allergy: false },
    { slug: 'gluten', label: 'Glúten', is_allergy: false },
    { slug: 'castanhas', label: 'Amendoim e castanhas', is_allergy: true },
    { slug: 'frutos-do-mar', label: 'Frutos do mar', is_allergy: true },
    { slug: 'sem-carne', label: 'Não como carne', is_allergy: false },
    { slug: 'sem-animal', label: 'Não como nada de origem animal', is_allergy: false },
  ],
  pantry: [
    {
      category: 'proteinas',
      label: 'Proteínas',
      items: [
        { slug: 'ovos', label: 'Ovos' },
        { slug: 'frango', label: 'Frango' },
        { slug: 'carne-moida', label: 'Carne moída' },
        { slug: 'peixe', label: 'Peixe' },
        { slug: 'iogurte', label: 'Iogurte' },
        { slug: 'queijo', label: 'Queijo' },
      ],
    },
    {
      category: 'carboidratos',
      label: 'Carboidratos',
      items: [
        { slug: 'arroz-e-feijao', label: 'Arroz e feijão' },
        { slug: 'batata-doce', label: 'Batata-doce' },
        { slug: 'tapioca', label: 'Tapioca' },
        { slug: 'macarrao', label: 'Macarrão' },
        { slug: 'cuscuz', label: 'Cuscuz' },
        { slug: 'pao-frances', label: 'Pão francês' },
        { slug: 'aveia', label: 'Aveia' },
      ],
    },
    {
      category: 'frutas',
      label: 'Frutas',
      items: [
        { slug: 'banana', label: 'Banana' },
        { slug: 'mamao', label: 'Mamão' },
        { slug: 'maca', label: 'Maçã' },
        { slug: 'laranja', label: 'Laranja' },
      ],
    },
  ],
  dislike_options: [
    { id: 91, name: 'Berinjela' },
    { id: 92, name: 'Beterraba' },
    { id: 88, name: 'Fígado bovino' },
    { id: 93, name: 'Jiló' },
    { id: 94, name: 'Peixe assado' },
  ],
  lunch_places: [
    { value: 'casa', label: 'Em casa' },
    { value: 'marmita', label: 'Marmita no trabalho' },
    { value: 'restaurante', label: 'Restaurante' },
  ],
};

export interface RespostasApi {
  goal: string | null;
  preferred_name: string | null;
  age: number | null;
  height_cm: number | null;
  weight_kg: number | null;
  sex: string | null;
  goal_weight_kg: number | null;
  goal_weight_source: string | null;
  activity_level: string | null;
  work_posture: string | null;
  pantry_items: string[];
  restrictions: string[];
  other_restrictions: string[];
  wake_time: string | null;
  training_time: string | null;
  sleep_time: string | null;
  training_days: number[];
  lunch_place: string | null;
}

export const respostasVazias: RespostasApi = {
  goal: null,
  preferred_name: 'Camila',
  age: null,
  height_cm: null,
  weight_kg: null,
  sex: null,
  goal_weight_kg: null,
  goal_weight_source: null,
  activity_level: null,
  work_posture: null,
  pantry_items: [],
  restrictions: [],
  other_restrictions: [],
  wake_time: null,
  training_time: null,
  sleep_time: null,
  training_days: [],
  lunch_place: null,
};

export const respostasDaCamila: RespostasApi = {
  goal: 'ganhar-massa',
  preferred_name: 'Camila',
  age: 27,
  height_cm: 164,
  weight_kg: 58.4,
  sex: 'feminino',
  goal_weight_kg: 62,
  goal_weight_source: 'user',
  activity_level: 'moderado',
  work_posture: 'sentada',
  pantry_items: ['ovos', 'frango', 'arroz-e-feijao'],
  restrictions: ['castanhas'],
  other_restrictions: ['camarão'],
  wake_time: '06:20',
  training_time: '19:00',
  sleep_time: '23:00',
  training_days: [1, 3, 5],
  lunch_place: 'marmita',
};

/** `GET /onboarding` com o que o teste quiser trocar. */
export function onboardingApi(
  parcial: { completed_steps?: string[]; next_step?: string | null; answers?: Partial<RespostasApi> } = {},
) {
  const answers = { ...respostasVazias, ...parcial.answers };
  return {
    completed: false,
    completed_steps: parcial.completed_steps ?? [],
    next_step: parcial.next_step === undefined ? 'objetivo' : parcial.next_step,
    answers,
    healthy_weight_range: answers.height_cm === 164 ? { min: 49.8, max: 67.0 } : null,
  };
}

export const previaApi = { kcal: 2250, protein_g: 115, carbs_g: 305, fat_g: 65, meals: 5 };

export const perfilApi = {
  name: 'Camila Réus',
  preferred_name: 'Camila',
  email: 'camila.reus@gmail.com',
  created_at: '2026-08-11T09:00:00-03:00',
  goal: 'ganhar-massa',
  sex: 'feminino',
  age: 27,
  height_cm: 164,
  start_weight_kg: 56.8,
  current_weight_kg: 58.4,
  goal_weight_kg: 62,
  goal_weight_source: 'user',
  healthy_weight_range: { min: 49.8, max: 67.0 },
  activity_level: 'moderado',
  work_posture: 'sentada',
  wake_time: '06:20',
  training_time: '19:00',
  sleep_time: '23:00',
  training_days: [1, 3, 5],
  lunch_place: 'marmita',
  pantry_items: [
    { slug: 'ovos', label: 'Ovos' },
    { slug: 'frango', label: 'Frango' },
    { slug: 'arroz-e-feijao', label: 'Arroz e feijão' },
  ],
  restrictions: [{ slug: 'castanhas', label: 'Amendoim e castanhas', is_allergy: true }],
  other_restrictions: ['camarão'],
  disliked_foods: [{ id: 88, name: 'Fígado bovino' }],
  gym: 'Zfit',
  city: 'Capivari de Baixo',
};
```

`src/mocks/handlers/onboarding.ts`:
```ts
import { http, HttpResponse } from 'msw';
import { catalogoApi, onboardingApi } from '../fixtures/onboarding';
import { url } from './auth';

/** Padrão: catálogo completo e onboarding sem nada salvo. */
export const handlersOnboarding = [
  http.get(url('/catalog/onboarding'), () => HttpResponse.json({ data: catalogoApi })),
  http.get(url('/onboarding'), () => HttpResponse.json({ data: onboardingApi() })),
];
```

`src/mocks/handlers/index.ts` (substituir inteiro):
```ts
import type { RequestHandler } from 'msw';
import { handlersAuth } from './auth';
import { handlersOnboarding } from './onboarding';

/** Handlers padrão de todas as integrações. Cada teste troca o que precisar com `server.use()`. */
export const handlers: RequestHandler[] = [...handlersAuth, ...handlersOnboarding];
```

- [ ] **Step 6: Suíte, lint e commit**

Run: `docker compose run --rm web bash -c "npm run lint && npm run typecheck && npm test"`
Expected: tudo verde.

```bash
git add -A && git commit -m "feat(onboarding): tipos, chamadas, regras espelho do backend, hooks e MSW

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---
### Task 7: Front — casca da etapa (`OnboardingStep`), `useEtapa`, `GoalWeightField` e `Segmento` com setas

**Files (repo front):**
- Create: `src/features/onboarding/components/{OnboardingStep,EtapaEsperando,MensagemDoGrupo,GoalWeightField}.tsx`, `src/features/onboarding/useEtapa.ts`
- Modify: `src/components/ui/Field.tsx` (`"use client"` no topo e a função `Segmento`)
- Test (stories com `play`): `src/features/onboarding/components/{OnboardingStep,GoalWeightField}.stories.tsx`, `src/components/ui/{Segmento,OptionRow}.stories.tsx`

**Interfaces:**
- Consumes: Task 6 inteira; `Button`, `FormError`, `Field`, `Skeleton`, `Steps`, `ErrorState`, `useToast` (Plano 02).
- Produces:
  - `OnboardingStep({ numero, total, titulo, descricao, voltarPara: string | null, rotuloBotao?, rotuloSalvando?, carregando?, erroAoCarregar?: { aoTentarDeNovo } | null, salvando?, erroAoSalvar?: ApiError | null, podeContinuar?, aoContinuar?, acimaDoBotao?, children? })` — `<form>`: Enter em qualquer campo aciona "Continuar"; foco no título ao montar.
  - `useEtapa(etapa: EtapaEditavel)` → `{ catalogo?, dados?, erroAoCarregar, editando, errosCampo, salvar(corpo): Promise<void>, casca }`, em que `casca` são as props de `OnboardingStep` (número, textos, voltar, rótulo, salvando, erro). Navega depois de salvar: próxima etapa, `/onboarding/resumo` (`?de=resumo`) ou `/perfil` (`?editar=1`, com o aviso "Salvo.").
  - `type Etapa = ReturnType<typeof useEtapa>`; `EtapaEsperando({ etapa })`; `MensagemDoGrupo({ texto? })`.
  - `GoalWeightField({ objetivo, alturaCm, valor, onChange, erro?, disabled?, className?, style? })`.
  - `Segmento` aceita `valor: T | null`, `erro?: string`, e anda com as setas (roving tabindex).
- Nota: a casca nova mora em `features/onboarding/components/`; a antiga (`components/app/OnboardingStep.tsx`) continua servindo as telas ainda não migradas e é apagada na Task 10.

- [ ] **Step 1: Stories que devem falhar**

`src/features/onboarding/components/OnboardingStep.stories.tsx`:
```tsx
import type { Meta, StoryObj } from '@storybook/nextjs-vite';
import { expect, fn, userEvent, waitFor, within } from 'storybook/test';
import { Field } from '@/components/ui/Field';
import { ApiError } from '@/lib/api/errors';
import { OnboardingStep } from './OnboardingStep';

const meta = {
  title: 'Onboarding/OnboardingStep',
  component: OnboardingStep,
  args: {
    numero: 2,
    total: 7,
    titulo: 'Agora, seus dados',
    descricao: 'Ficam só no seu perfil. Ninguém da academia vê.',
    voltarPara: '/onboarding/objetivo',
    aoContinuar: fn(),
    children: <Field id="nome" label="Como podemos te chamar" defaultValue="Camila" />,
  },
} satisfies Meta<typeof OnboardingStep>;

export default meta;
type Story = StoryObj<typeof meta>;

export const Pronto: Story = {
  play: async ({ canvasElement, args }) => {
    const tela = within(canvasElement);
    await waitFor(() => expect(tela.getByRole('heading', { name: 'Agora, seus dados' })).toHaveFocus());
    await expect(tela.getByText('Etapa 2 de 7')).toBeVisible();
    await expect(tela.getByRole('link', { name: 'Voltar' })).toHaveAttribute('href', '/onboarding/objetivo');
    await userEvent.click(tela.getByRole('button', { name: 'Continuar' }));
    await expect(args.aoContinuar).toHaveBeenCalledOnce();
  },
};

export const EnterContinua: Story = {
  play: async ({ canvasElement, args }) => {
    await userEvent.type(within(canvasElement).getByLabelText('Como podemos te chamar'), '{Enter}');
    await expect(args.aoContinuar).toHaveBeenCalledOnce();
  },
};

export const Carregando: Story = {
  args: { carregando: true },
  play: async ({ canvasElement }) => {
    const tela = within(canvasElement);
    await expect(tela.getByLabelText('Carregando suas respostas')).toHaveAttribute('aria-busy', 'true');
    await expect(tela.queryByLabelText('Como podemos te chamar')).toBeNull();
    await expect(tela.getByRole('button', { name: 'Continuar' })).toBeDisabled();
  },
};

export const Salvando: Story = {
  args: { salvando: true },
  play: async ({ canvasElement, args }) => {
    const botao = within(canvasElement).getByRole('button', { name: 'Salvando…' });
    await expect(botao).toHaveAttribute('aria-busy', 'true');
    await userEvent.click(botao, { pointerEventsCheck: 0 });
    await expect(args.aoContinuar).not.toHaveBeenCalled();
  },
};

export const ErroAoSalvar: Story = {
  args: { erroAoSalvar: new ApiError(0, 'NETWORK_ERROR', 'Não foi possível salvar. Tente de novo.') },
  play: async ({ canvasElement }) => {
    await expect(within(canvasElement).getByRole('alert')).toHaveTextContent('Não foi possível salvar. Tente de novo.');
  },
};

export const ErroAoCarregar: Story = {
  args: { erroAoCarregar: { aoTentarDeNovo: fn() } },
  play: async ({ canvasElement, args }) => {
    const tela = within(canvasElement);
    await expect(tela.getByText('Não foi possível carregar suas respostas')).toBeVisible();
    await expect(tela.getByRole('button', { name: 'Continuar' })).toBeDisabled();
    await userEvent.click(tela.getByRole('button', { name: 'Tentar de novo' }));
    await expect(args.erroAoCarregar?.aoTentarDeNovo).toHaveBeenCalledOnce();
  },
};

export const ModoEdicao: Story = {
  args: { rotuloBotao: 'Salvar', voltarPara: '/perfil' },
  play: async ({ canvasElement }) => {
    const tela = within(canvasElement);
    await expect(tela.getByRole('button', { name: 'Salvar' })).toBeEnabled();
    await expect(tela.getByRole('link', { name: 'Voltar' })).toHaveAttribute('href', '/perfil');
  },
};

export const PrimeiraEtapa: Story = {
  args: { numero: 1, voltarPara: null, titulo: 'Qual é seu objetivo agora?' },
  play: async ({ canvasElement }) => {
    await expect(within(canvasElement).queryByRole('link', { name: 'Voltar' })).toBeNull();
  },
};

export const SemEscolha: Story = {
  args: { podeContinuar: false },
  play: async ({ canvasElement }) => {
    await expect(within(canvasElement).getByRole('button', { name: 'Continuar' })).toBeDisabled();
  },
};
```

`src/features/onboarding/components/GoalWeightField.stories.tsx`:
```tsx
import type { Meta, StoryObj } from '@storybook/nextjs-vite';
import { expect, fn, within } from 'storybook/test';
import { AVISO_META_FORA, MENSAGENS } from '../regras';
import { GoalWeightField } from './GoalWeightField';

const meta = {
  title: 'Onboarding/GoalWeightField',
  component: GoalWeightField,
  args: { objetivo: 'ganhar-massa', alturaCm: 164, valor: '', onChange: fn() },
} satisfies Meta<typeof GoalWeightField>;

export default meta;
type Story = StoryObj<typeof meta>;

const campo = (canvas: HTMLElement) => within(canvas).queryByLabelText('Meta de peso (opcional)');

export const Oculto: Story = {
  args: { objetivo: 'mais-disposicao' },
  play: async ({ canvasElement }) => {
    await expect(campo(canvasElement)).toBeNull();
  },
};

export const VazioComFaixa: Story = {
  play: async ({ canvasElement }) => {
    await expect(within(canvasElement).getByText('Para 1,64 m, a faixa saudável vai de 49,8 a 67,0 kg.')).toBeVisible();
    await expect(campo(canvasElement)).toHaveValue('');
  },
};

export const SemAltura: Story = {
  args: { alturaCm: null },
  play: async ({ canvasElement }) => {
    await expect(within(canvasElement).getByText('Opcional. Se deixar vazio, sugerimos uma meta saudável para você.')).toBeVisible();
  },
};

export const DentroDaFaixa: Story = {
  args: { valor: '62' },
  play: async ({ canvasElement }) => {
    await expect(within(canvasElement).queryByText(AVISO_META_FORA)).toBeNull();
  },
};

export const ForaDaFaixaComAviso: Story = {
  args: { valor: '75' },
  play: async ({ canvasElement }) => {
    await expect(within(canvasElement).getByText(AVISO_META_FORA)).toBeInTheDocument();
    await expect(campo(canvasElement)).not.toHaveAttribute('aria-invalid');
  },
};

export const DirecaoErrada: Story = {
  args: { valor: '55', erro: MENSAGENS.metaGanhar },
  play: async ({ canvasElement }) => {
    await expect(campo(canvasElement)).toHaveAttribute('aria-invalid', 'true');
    await expect(within(canvasElement).getByText(MENSAGENS.metaGanhar)).toBeInTheDocument();
  },
};
```

`src/components/ui/Segmento.stories.tsx`:
```tsx
import type { Meta, StoryObj } from '@storybook/nextjs-vite';
import { useState } from 'react';
import { expect, userEvent, within } from 'storybook/test';
import { Segmento } from './Field';

const OPCOES = [
  { valor: 'feminino', rotulo: 'Feminino' },
  { valor: 'masculino', rotulo: 'Masculino' },
  { valor: 'nao-dizer', rotulo: 'Prefiro não dizer' },
];

function Controlado({ inicial = null, erro }: { inicial?: string | null; erro?: string }) {
  const [valor, setValor] = useState<string | null>(inicial);
  return <Segmento label="Sexo biológico" opcoes={OPCOES} valor={valor} onChange={setValor} erro={erro} />;
}

const meta = { title: 'UI/Segmento', component: Controlado } satisfies Meta<typeof Controlado>;

export default meta;
type Story = StoryObj<typeof meta>;

export const SemEscolha: Story = {
  play: async ({ canvasElement }) => {
    const opcoes = within(canvasElement).getAllByRole('radio');
    await expect(opcoes.map((o) => o.getAttribute('aria-checked'))).toEqual(['false', 'false', 'false']);
    await expect(opcoes[0]).toHaveAttribute('tabindex', '0');
  },
};

export const Setas: Story = {
  args: { inicial: 'feminino' },
  play: async ({ canvasElement }) => {
    const tela = within(canvasElement);
    tela.getByRole('radio', { name: 'Feminino' }).focus();
    await userEvent.keyboard('{ArrowRight}');
    await expect(tela.getByRole('radio', { name: 'Masculino' })).toHaveAttribute('aria-checked', 'true');
    await expect(tela.getByRole('radio', { name: 'Masculino' })).toHaveFocus();
    await userEvent.keyboard('{ArrowLeft}{ArrowLeft}');
    await expect(tela.getByRole('radio', { name: 'Prefiro não dizer' })).toHaveAttribute('aria-checked', 'true');
  },
};

export const ComErro: Story = {
  args: { erro: 'Escolha uma opção.' },
  play: async ({ canvasElement }) => {
    await expect(within(canvasElement).getByText('Escolha uma opção.')).toBeInTheDocument();
  },
};
```

`src/components/ui/OptionRow.stories.tsx`:
```tsx
import type { Meta, StoryObj } from '@storybook/nextjs-vite';
import { expect, fn, userEvent, within } from 'storybook/test';
import { EtiquetaAlergia, OptionRow } from './OptionRow';

const meta = {
  title: 'UI/OptionRow',
  component: OptionRow,
  args: { marcado: false, onClick: fn(), titulo: 'Ganhar massa magra', descricao: 'Comer um pouco acima do gasto, com proteína alta todo dia.' },
} satisfies Meta<typeof OptionRow>;

export default meta;
type Story = StoryObj<typeof meta>;

/** Rádio só existe dentro de um grupo (axe: aria-required-parent). */
const emGrupo: Story['decorators'] = [(Historia) => <div role="radiogroup" aria-label="Objetivo"><Historia /></div>];

export const Desmarcado: Story = {
  decorators: emGrupo,
  play: async ({ canvasElement, args }) => {
    const opcao = within(canvasElement).getByRole('radio', { name: /Ganhar massa magra/ });
    await expect(opcao).toHaveAttribute('aria-checked', 'false');
    await userEvent.click(opcao);
    await expect(args.onClick).toHaveBeenCalledOnce();
  },
};

export const Marcado: Story = {
  args: { marcado: true },
  decorators: emGrupo,
  play: async ({ canvasElement }) => {
    await expect(within(canvasElement).getByRole('radio')).toHaveAttribute('aria-checked', 'true');
  },
};

export const Quadrado: Story = {
  args: { quadrado: true, compacto: true, titulo: 'Glúten', descricao: undefined },
  play: async ({ canvasElement }) => {
    await expect(within(canvasElement).getByRole('checkbox', { name: 'Glúten' })).toBeInTheDocument();
  },
};

export const ComAlergia: Story = {
  args: { quadrado: true, compacto: true, marcado: true, titulo: 'Amendoim e castanhas', descricao: undefined, etiqueta: <EtiquetaAlergia /> },
  play: async ({ canvasElement }) => {
    await expect(within(canvasElement).getByRole('checkbox', { name: /Amendoim e castanhas/ })).toHaveTextContent('Alergia');
  },
};
```

Run: `docker compose run --rm web npx vitest run --project storybook src/features/onboarding src/components/ui/Segmento.stories.tsx src/components/ui/OptionRow.stories.tsx`
Expected: FAIL — `./OnboardingStep` e `./GoalWeightField` não resolvem; `Segmento` sem `erro`/setas (as stories de `OptionRow` já passam: documentam o componente existente).

- [ ] **Step 2: Implementar**

Em `src/components/ui/Field.tsx`: acrescentar `"use client";` e `import { useRef } from "react";` no topo, e trocar a função `Segmento` inteira por:
```tsx
export function Segmento<T extends string>({
  label,
  opcoes,
  valor,
  onChange,
  ajuda,
  erro,
}: {
  label?: string;
  opcoes: { valor: T; rotulo: string }[];
  valor: T | null;
  onChange: (v: T) => void;
  ajuda?: string;
  erro?: string;
}) {
  const botoes = useRef<(HTMLButtonElement | null)[]>([]);
  const atual = opcoes.findIndex((o) => o.valor === valor);
  const focavel = atual === -1 ? 0 : atual;

  // Grupo de rádio: Tab entra e sai; as setas trocam a escolha (padrão do WAI-ARIA).
  function mover(evento: React.KeyboardEvent, indice: number) {
    const passo =
      evento.key === "ArrowRight" || evento.key === "ArrowDown" ? 1 : evento.key === "ArrowLeft" || evento.key === "ArrowUp" ? -1 : 0;
    if (passo === 0) return;
    evento.preventDefault();
    const proximo = (indice + passo + opcoes.length) % opcoes.length;
    onChange(opcoes[proximo].valor);
    botoes.current[proximo]?.focus();
  }

  return (
    <div>
      {label ? (
        <span className="mb-[7px] block text-[12.5px] font-semibold text-fumo">
          {label}
        </span>
      ) : null}
      <div className="flex gap-2" role="radiogroup" aria-label={label}>
        {opcoes.map((o, i) => {
          const ativo = o.valor === valor;
          return (
            <button
              key={o.valor}
              ref={(el) => {
                botoes.current[i] = el;
              }}
              type="button"
              role="radio"
              aria-checked={ativo}
              tabIndex={i === focavel ? 0 : -1}
              onClick={() => onChange(o.valor)}
              onKeyDown={(e) => mover(e, i)}
              className={`flex h-11 flex-1 items-center justify-center rounded-xl border px-2 text-center text-sm transition active:scale-[0.97] ${
                ativo
                  ? "border-tinta bg-tinta font-semibold text-white"
                  : erro
                    ? "border-alerta bg-white font-medium text-tinta"
                    : "border-linha bg-white font-medium text-tinta hover:border-pedra"
              }`}
            >
              {o.rotulo}
            </button>
          );
        })}
      </div>
      {ajuda ? <p className="mt-2 text-[12.5px] leading-snug text-fumo">{ajuda}</p> : null}
      {erro ? <p className="mt-2 animate-entra text-[12.5px] leading-snug font-medium text-alerta">{erro}</p> : null}
    </div>
  );
}
```

`src/features/onboarding/components/OnboardingStep.tsx`:
```tsx
"use client";

import Link from "next/link";
import { useEffect, useRef } from "react";
import { ErrorState } from "@/components/app/ErrorState";
import { Screen } from "@/components/app/Screen";
import { IconeVoltar } from "@/components/icons";
import { Button } from "@/components/ui/Button";
import { FormError } from "@/components/ui/FormError";
import { Skeleton } from "@/components/ui/Skeleton";
import { Steps } from "@/components/ui/Steps";
import type { ApiError } from "@/lib/api/errors";

/** Casca de uma etapa (spec 02 §4): progresso, título, conteúdo e o botão que salva. */
export function OnboardingStep({
  numero,
  total,
  titulo,
  descricao,
  voltarPara,
  rotuloBotao = "Continuar",
  rotuloSalvando = "Salvando…",
  carregando = false,
  erroAoCarregar = null,
  salvando = false,
  erroAoSalvar = null,
  podeContinuar = true,
  aoContinuar,
  acimaDoBotao,
  children,
}: {
  numero: number;
  total: number;
  titulo: string;
  descricao: string;
  voltarPara: string | null;
  rotuloBotao?: string;
  rotuloSalvando?: string;
  carregando?: boolean;
  erroAoCarregar?: { aoTentarDeNovo: () => void } | null;
  salvando?: boolean;
  erroAoSalvar?: ApiError | null;
  podeContinuar?: boolean;
  aoContinuar?: () => void;
  acimaDoBotao?: React.ReactNode;
  children?: React.ReactNode;
}) {
  const tituloRef = useRef<HTMLHeadingElement>(null);
  const bloqueado = carregando || erroAoCarregar !== null;

  // Foco no título ao trocar de etapa: o leitor de tela anuncia onde a pessoa está (DoD da spec 02).
  useEffect(() => {
    tituloRef.current?.focus({ preventScroll: true });
  }, []);

  function enviar(evento: React.FormEvent) {
    evento.preventDefault();
    if (!bloqueado && !salvando && podeContinuar) aoContinuar?.();
  }

  return (
    <Screen>
      <form noValidate onSubmit={enviar} className="flex flex-1 flex-col">
        <header className="shrink-0 px-6 pt-[22px] area-segura-cima">
          <div className="flex h-10 items-center justify-between">
            {voltarPara ? (
              <Link
                href={voltarPara}
                aria-label="Voltar"
                className="-ml-2.5 flex size-10 items-center justify-center rounded-full transition-colors hover:bg-tinta/5"
              >
                <IconeVoltar size={22} />
              </Link>
            ) : (
              <span />
            )}
            <span className="text-[12.5px] font-medium text-fumo">
              Etapa {numero} de {total}
            </span>
          </div>
          <Steps atual={numero} total={total} />
        </header>

        <main className="flex-1 px-6 pt-7 pb-4">
          <h1
            ref={tituloRef}
            tabIndex={-1}
            className="animate-entra font-display text-[28px] leading-tight font-bold tracking-[-0.025em] outline-none"
            style={{ animationDelay: "40ms" }}
          >
            {titulo}
          </h1>
          <p className="mt-2.5 animate-entra text-[14.5px] leading-normal text-fumo" style={{ animationDelay: "110ms" }}>
            {descricao}
          </p>
          <div className="mt-5">
            {erroAoCarregar ? (
              <ErrorState
                titulo="Não foi possível carregar suas respostas"
                descricao="Confira a internet e tente de novo."
                aoTentarDeNovo={erroAoCarregar.aoTentarDeNovo}
              />
            ) : carregando ? (
              <div aria-busy="true" aria-label="Carregando suas respostas" className="flex flex-col gap-2.5">
                {[0, 1, 2].map((i) => (
                  <Skeleton key={i} className="h-[72px]" atraso={i * 90} />
                ))}
              </div>
            ) : (
              children
            )}
          </div>
        </main>

        <footer className="shrink-0 animate-entra px-6 pt-3.5 pb-7 area-segura-baixo" style={{ animationDelay: "260ms" }}>
          {bloqueado ? null : acimaDoBotao}
          <FormError erro={erroAoSalvar} />
          <Button type="submit" carregando={salvando} rotuloCarregando={rotuloSalvando} disabled={bloqueado || !podeContinuar}>
            {rotuloBotao}
          </Button>
        </footer>
      </form>
    </Screen>
  );
}
```

`src/features/onboarding/useEtapa.ts`:
```ts
'use client';

import { useRouter, useSearchParams } from 'next/navigation';
import { useState } from 'react';
import { useToast } from '@/components/ui/Toaster';
import { ApiError, comoApiError, primeirasMensagens } from '@/lib/api/errors';
import { etapaAnterior, numeroDaEtapa, proximaEtapa, TEXTOS, TOTAL_ETAPAS } from './etapas';
import { useCatalogo, useDadosOnboarding, useSalvarEtapa } from './hooks';
import type { EtapaEditavel } from './tipos';

export const AVISO_META_AJUSTADA = 'Sua meta de peso foi ajustada para o novo objetivo.';
export const ERRO_AO_SALVAR = 'Não foi possível salvar. Tente de novo.';

/**
 * Contêiner de uma etapa: carrega catálogo e respostas, salva e navega.
 * `?editar=1` (vindo do Perfil) volta ao Perfil; `?de=resumo` volta ao resumo; senão, segue o fluxo.
 */
export function useEtapa(etapa: EtapaEditavel) {
  const busca = useSearchParams();
  const router = useRouter();
  const avisar = useToast();
  const catalogo = useCatalogo();
  const dados = useDadosOnboarding();
  const salvarEtapa = useSalvarEtapa(etapa);
  const [errosCampo, setErrosCampo] = useState<Record<string, string>>({});
  const [erroGeral, setErroGeral] = useState<ApiError | null>(null);

  const editando = busca.get('editar') === '1';
  const deResumo = busca.get('de') === 'resumo';
  const anterior = etapaAnterior(etapa);
  const voltarPara = editando ? '/perfil' : deResumo ? '/onboarding/resumo' : anterior ? `/onboarding/${anterior}` : null;
  const destino = editando ? '/perfil' : deResumo ? '/onboarding/resumo' : `/onboarding/${proximaEtapa(etapa)}`;

  async function salvar(corpo: Record<string, unknown>) {
    if (salvarEtapa.isPending) return;
    setErrosCampo({});
    setErroGeral(null);

    let avisos: string[];
    try {
      avisos = (await salvarEtapa.mutateAsync(corpo)).meta.warnings;
    } catch (e) {
      const erro = comoApiError(e);
      if (erro.code === 'VALIDATION_ERROR') setErrosCampo(primeirasMensagens(erro.fieldErrors));
      else setErroGeral(erro.code === 'NETWORK_ERROR' ? new ApiError(0, 'NETWORK_ERROR', ERRO_AO_SALVAR) : erro);
      return;
    }

    if (avisos.includes('GOAL_WEIGHT_RESET')) {
      avisar({
        texto: AVISO_META_AJUSTADA,
        acao: editando ? { rotulo: 'Ver meta', onClick: () => router.push('/onboarding/dados?editar=1') } : undefined,
      });
    } else if (editando) {
      avisar({ texto: 'Salvo.' });
    }
    router.push(destino);
  }

  const erroAoCarregar =
    catalogo.error || dados.error
      ? {
          aoTentarDeNovo: () => {
            void catalogo.refetch();
            void dados.refetch();
          },
        }
      : null;

  return {
    catalogo: catalogo.data,
    dados: dados.data,
    erroAoCarregar,
    editando,
    errosCampo,
    salvar,
    casca: {
      ...TEXTOS[etapa],
      numero: numeroDaEtapa(etapa),
      total: TOTAL_ETAPAS,
      voltarPara,
      rotuloBotao: editando ? 'Salvar' : 'Continuar',
      salvando: salvarEtapa.isPending,
      erroAoSalvar: erroGeral,
    },
  };
}

export type Etapa = ReturnType<typeof useEtapa>;
```

`src/features/onboarding/components/EtapaEsperando.tsx`:
```tsx
"use client";

import type { Etapa } from "../useEtapa";
import { OnboardingStep } from "./OnboardingStep";

/** A etapa enquanto catálogo e respostas carregam (ou falham): cabeçalho e progresso já visíveis. */
export function EtapaEsperando({ etapa }: { etapa: Etapa }) {
  return <OnboardingStep {...etapa.casca} carregando={!etapa.erroAoCarregar} erroAoCarregar={etapa.erroAoCarregar} />;
}
```

`src/features/onboarding/components/MensagemDoGrupo.tsx`:
```tsx
/** Erro de um grupo de opções (rádios, chips), abaixo dele. */
export function MensagemDoGrupo({ texto }: { texto?: string }) {
  return texto ? <p className="mt-2 animate-entra text-[12.5px] leading-snug font-medium text-alerta">{texto}</p> : null;
}
```

`src/features/onboarding/components/GoalWeightField.tsx`:
```tsx
"use client";

import { Field } from "@/components/ui/Field";
import type { Goal } from "@/lib/types";
import { alturaValida, AVISO_META_FORA, faixaSaudavel, lerNumero, metaForaDaFaixa, pedeMeta } from "../regras";

const umaCasa = (n: number) => n.toLocaleString("pt-BR", { minimumFractionDigits: 1, maximumFractionDigits: 1 });
const metros = (cm: number) => `${(cm / 100).toFixed(2).replace(".", ",")} m`;

/** Meta de peso opcional (RN10): só em ganhar/perder; mostra a faixa saudável e avisa sem bloquear. */
export function GoalWeightField({
  objetivo,
  alturaCm,
  valor,
  onChange,
  erro,
  disabled,
  className,
  style,
}: {
  objetivo: Goal | null;
  alturaCm: number | null;
  valor: string;
  onChange: (valor: string) => void;
  erro?: string;
  disabled?: boolean;
  className?: string;
  style?: React.CSSProperties;
}) {
  if (!pedeMeta(objetivo)) return null;

  const faixa = alturaValida(alturaCm) ? faixaSaudavel(alturaCm) : null;
  const meta = lerNumero(valor);
  const fora = alturaValida(alturaCm) && meta !== null && !Number.isNaN(meta) && metaForaDaFaixa(meta, alturaCm);

  return (
    <Field
      id="meta"
      label="Meta de peso (opcional)"
      sufixo="kg"
      inputMode="decimal"
      value={valor}
      onChange={(e) => onChange(e.target.value)}
      ajuda={
        faixa && alturaCm
          ? `Para ${metros(alturaCm)}, a faixa saudável vai de ${umaCasa(faixa.min)} a ${umaCasa(faixa.max)} kg.`
          : "Opcional. Se deixar vazio, sugerimos uma meta saudável para você."
      }
      erro={erro}
      aviso={fora ? AVISO_META_FORA : undefined}
      disabled={disabled}
      className={className}
      style={style}
    />
  );
}
```

- [ ] **Step 3: Rodar e ver passar**

Run: `docker compose run --rm web npx vitest run --project storybook src/features/onboarding src/components/ui`
Expected: PASS (inclui o axe de cada story). Se o axe apontar contraste, troque a cor pelo token da regra de contraste do Plano 02 — nunca desligue o `a11y`.

- [ ] **Step 4: Refinar com `frontend-design` (D11)**

Carregue `frontend-design:frontend-design` para `OnboardingStep` e `GoalWeightField`: entrada do conteúdo quando o carregamento termina (sem pulo de altura), transição do número da etapa, o aviso da meta entrando com `animate-entra`, sempre com os tokens de `globals.css` e movimento em `transform`/`opacity`. **Não mudar** textos, `role`s, `aria-*`, ids nem props (stories e integrações dependem deles).

Run: `docker compose run --rm web bash -c "npm run lint && npm run typecheck && npm test"`
Expected: tudo verde.

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -m "feat(onboarding): casca da etapa, useEtapa, campo de meta de peso e Segmento com setas

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---
### Task 8: Front — etapas Objetivo, Dados e Atividade ligadas à API

**Files (repo front):**
- Create: `src/features/onboarding/components/{EtapaObjetivo,EtapaDados,EtapaAtividade}.tsx`
- Modify: `src/app/onboarding/{objetivo,dados,atividade}/page.tsx` (substituir inteiros), `src/mocks/handlers/onboarding.ts`
- Test: `src/features/onboarding/components/{EtapaObjetivo,EtapaDados,EtapaAtividade}.integration.test.tsx`

**Interfaces:**
- Consumes: `useEtapa`, `Etapa`, `OnboardingStep`, `EtapaEsperando`, `MensagemDoGrupo`, `GoalWeightField`, `Segmento` (Task 7); regras e fixtures (Task 6).
- Produces:
  - `EtapaObjetivo()`, `EtapaDados()`, `EtapaAtividade()`.
  - MSW: `respondendoOnboarding(parcial)` (handler de `GET /onboarding`) e `gravandoEtapa(etapa, meta?)` → `{ handler, corpos }` (grava os corpos do `PATCH`).
- Corpos enviados (camelCase no front, snake_case no fio): objetivo `{ goal }`; dados `{ preferredName, age, heightCm, weightKg, sex, goalWeightKg }` (`goalWeightKg: null` quando o objetivo não pede meta ou o campo está vazio); atividade `{ activityLevel, workPosture }`.

- [ ] **Step 1: Utilitários do MSW e testes que devem falhar**

Em `src/mocks/handlers/onboarding.ts`, acrescentar ao fim:
```ts
/** `GET /onboarding` com estas respostas. */
export const respondendoOnboarding = (parcial: Parameters<typeof onboardingApi>[0]) =>
  http.get(url('/onboarding'), () => HttpResponse.json({ data: onboardingApi(parcial) }));

/** `PATCH /profile/steps/{etapa}` que guarda os corpos recebidos (já em snake_case, como no fio). */
export function gravandoEtapa(etapa: string, meta: { warnings?: string[] } = {}) {
  const corpos: unknown[] = [];
  const handler = http.patch(url(`/profile/steps/${etapa}`), async ({ request }) => {
    corpos.push(await request.json());
    return HttpResponse.json({
      data: onboardingApi({ completed_steps: [etapa] }),
      meta: { plan_effect: 'none', plan_id: null, warnings: meta.warnings ?? [] },
    });
  });
  return { handler, corpos };
}
```

`src/features/onboarding/components/EtapaObjetivo.integration.test.tsx`:
```tsx
import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { http, HttpResponse } from 'msw';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { onboardingApi } from '@/mocks/fixtures/onboarding';
import { url } from '@/mocks/handlers/auth';
import { gravandoEtapa, respondendoOnboarding } from '@/mocks/handlers/onboarding';
import { server } from '@/mocks/server';
import { definirUrl, redefinirNavegacao, roteador } from '@/test/next-navigation';
import { renderizar } from '@/test/renderizar';
import { EtapaObjetivo } from './EtapaObjetivo';

vi.mock('next/navigation', () => import('@/test/next-navigation'));

beforeEach(() => {
  redefinirNavegacao();
  definirUrl('/onboarding/objetivo');
});

describe('Etapa Objetivo (S02)', () => {
  it('começa sem nada marcado e só continua depois da escolha', async () => {
    const { handler, corpos } = gravandoEtapa('objetivo');
    server.use(handler);
    const usuario = userEvent.setup();

    renderizar(<EtapaObjetivo />);
    const continuar = await screen.findByRole('button', { name: 'Continuar' });

    expect(continuar).toBeDisabled();
    expect(screen.getAllByRole('radio').map((r) => r.getAttribute('aria-checked'))).toEqual(['false', 'false', 'false', 'false']);
    expect(screen.queryByRole('link', { name: 'Voltar' })).not.toBeInTheDocument();

    await usuario.click(screen.getByRole('radio', { name: /Perder gordura/ }));
    await usuario.click(continuar);

    await waitFor(() => expect(roteador.push).toHaveBeenCalledWith('/onboarding/dados'));
    expect(corpos).toEqual([{ goal: 'perder-gordura' }]);
  });

  it('volta com a escolha salva marcada (CA01)', async () => {
    server.use(respondendoOnboarding({ answers: { goal: 'manter-peso' } }));

    renderizar(<EtapaObjetivo />);

    expect(await screen.findByRole('radio', { name: /Manter o peso/ })).toHaveAttribute('aria-checked', 'true');
  });

  it('no modo edição salva, avisa e volta ao perfil', async () => {
    const { handler } = gravandoEtapa('objetivo');
    server.use(handler, respondendoOnboarding({ answers: { goal: 'ganhar-massa' } }));
    definirUrl('/onboarding/objetivo?editar=1');

    renderizar(<EtapaObjetivo />);
    await userEvent.setup().click(await screen.findByRole('button', { name: 'Salvar' }));

    await waitFor(() => expect(roteador.push).toHaveBeenCalledWith('/perfil'));
    expect(await screen.findByText('Salvo.')).toBeInTheDocument();
  });

  it('avisa quando a meta de peso foi ajustada e volta ao resumo (RN11)', async () => {
    const { handler } = gravandoEtapa('objetivo', { warnings: ['GOAL_WEIGHT_RESET'] });
    server.use(handler, respondendoOnboarding({ answers: { goal: 'ganhar-massa' } }));
    definirUrl('/onboarding/objetivo?de=resumo');
    const usuario = userEvent.setup();

    renderizar(<EtapaObjetivo />);
    await usuario.click(await screen.findByRole('radio', { name: /Perder gordura/ }));
    await usuario.click(screen.getByRole('button', { name: 'Continuar' }));

    await waitFor(() => expect(roteador.push).toHaveBeenCalledWith('/onboarding/resumo'));
    expect(await screen.findByText('Sua meta de peso foi ajustada para o novo objetivo.')).toBeInTheDocument();
  });

  it('se não carregar, mostra o erro e tenta de novo', async () => {
    let tentativas = 0;
    server.use(
      http.get(url('/onboarding'), () => {
        tentativas++;
        return tentativas === 1 ? HttpResponse.error() : HttpResponse.json({ data: onboardingApi() });
      }),
    );

    renderizar(<EtapaObjetivo />);
    await userEvent.setup().click(await screen.findByRole('button', { name: 'Tentar de novo' }));

    expect(await screen.findByRole('radio', { name: /Ganhar massa magra/ })).toBeInTheDocument();
  });
});
```

`src/features/onboarding/components/EtapaDados.integration.test.tsx`:
```tsx
import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { http, HttpResponse } from 'msw';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { respostasDaCamila } from '@/mocks/fixtures/onboarding';
import { erroDaApi, url } from '@/mocks/handlers/auth';
import { gravandoEtapa, respondendoOnboarding } from '@/mocks/handlers/onboarding';
import { server } from '@/mocks/server';
import { definirUrl, redefinirNavegacao, roteador } from '@/test/next-navigation';
import { renderizar } from '@/test/renderizar';
import { EtapaDados } from './EtapaDados';

vi.mock('next/navigation', () => import('@/test/next-navigation'));

beforeEach(() => {
  redefinirNavegacao();
  definirUrl('/onboarding/dados');
});

async function preencher(peso = '58,4', meta = '62') {
  const usuario = userEvent.setup();
  await usuario.type(await screen.findByLabelText('Idade'), '27');
  await usuario.type(screen.getByLabelText('Altura'), '164');
  await usuario.type(screen.getByLabelText('Peso de hoje'), peso);
  await usuario.click(screen.getByRole('radio', { name: 'Feminino' }));
  if (meta) await usuario.type(screen.getByLabelText('Meta de peso (opcional)'), meta);
  return usuario;
}

describe('Etapa Dados (S03)', () => {
  it('abre com o que foi salvo (CA01)', async () => {
    server.use(respondendoOnboarding({ answers: respostasDaCamila }));

    renderizar(<EtapaDados />);

    expect(await screen.findByLabelText('Como podemos te chamar')).toHaveValue('Camila');
    expect(screen.getByLabelText('Idade')).toHaveValue('27');
    expect(screen.getByLabelText('Altura')).toHaveValue('164');
    expect(screen.getByLabelText('Peso de hoje')).toHaveValue('58,4');
    expect(screen.getByRole('radio', { name: 'Feminino' })).toHaveAttribute('aria-checked', 'true');
    expect(screen.getByLabelText('Meta de peso (opcional)')).toHaveValue('62');
  });

  it('salva com vírgula virando número e segue para Atividade', async () => {
    const { handler, corpos } = gravandoEtapa('dados');
    server.use(handler, respondendoOnboarding({ answers: { goal: 'ganhar-massa' } }));

    renderizar(<EtapaDados />);
    const usuario = await preencher();
    await usuario.click(screen.getByRole('button', { name: 'Continuar' }));

    await waitFor(() => expect(roteador.push).toHaveBeenCalledWith('/onboarding/atividade'));
    expect(corpos).toEqual([
      { preferred_name: 'Camila', age: 27, height_cm: 164, weight_kg: 58.4, sex: 'feminino', goal_weight_kg: 62 },
    ]);
  });

  it('mostra a faixa saudável e avisa a meta fora dela sem bloquear (CA03)', async () => {
    const { handler, corpos } = gravandoEtapa('dados');
    server.use(handler, respondendoOnboarding({ answers: { goal: 'ganhar-massa' } }));

    renderizar(<EtapaDados />);
    const usuario = await preencher('58,4', '75');

    expect(screen.getByText('Para 1,64 m, a faixa saudável vai de 49,8 a 67,0 kg.')).toBeInTheDocument();
    expect(screen.getByText(/Essa meta fica fora da faixa saudável/)).toBeInTheDocument();
    await usuario.click(screen.getByRole('button', { name: 'Continuar' }));
    await waitFor(() => expect(corpos).toHaveLength(1));
  });

  it('recusa meta abaixo do peso ao ganhar massa, sem enviar (CA02)', async () => {
    const { handler, corpos } = gravandoEtapa('dados');
    server.use(handler, respondendoOnboarding({ answers: { goal: 'ganhar-massa' } }));

    renderizar(<EtapaDados />);
    const usuario = await preencher('58,4', '55');
    await usuario.click(screen.getByRole('button', { name: 'Continuar' }));

    expect(await screen.findByText('Para ganhar massa, a meta precisa ser maior que o peso de hoje.')).toBeInTheDocument();
    expect(screen.getByLabelText('Meta de peso (opcional)')).toHaveAttribute('aria-invalid', 'true');
    expect(corpos).toEqual([]);
  });

  it('mostra no campo o erro que vem do servidor', async () => {
    server.use(
      respondendoOnboarding({ answers: { goal: 'ganhar-massa' } }),
      http.patch(url('/profile/steps/dados'), () =>
        erroDaApi(422, 'VALIDATION_ERROR', 'Confira os campos destacados.', { errors: { weight_kg: ['Use um peso entre 30 e 250 kg, com até uma casa decimal.'] } }),
      ),
    );

    renderizar(<EtapaDados />);
    const usuario = await preencher();
    await usuario.click(screen.getByRole('button', { name: 'Continuar' }));

    expect(await screen.findByText('Use um peso entre 30 e 250 kg, com até uma casa decimal.')).toBeInTheDocument();
    expect(screen.getByLabelText('Peso de hoje')).toHaveAttribute('aria-invalid', 'true');
    expect(roteador.push).not.toHaveBeenCalled();
  });

  it('sem rede, avisa e não perde o que foi digitado', async () => {
    server.use(respondendoOnboarding({ answers: { goal: 'ganhar-massa' } }), http.patch(url('/profile/steps/dados'), () => HttpResponse.error()));

    renderizar(<EtapaDados />);
    const usuario = await preencher();
    await usuario.click(screen.getByRole('button', { name: 'Continuar' }));

    expect(await screen.findByRole('alert')).toHaveTextContent('Não foi possível salvar. Tente de novo.');
    expect(screen.getByLabelText('Idade')).toHaveValue('27');
    expect(screen.getByRole('button', { name: 'Continuar' })).toBeEnabled();
  });

  it('quem quer mais disposição não vê a meta e envia sem ela (RN10, CA05)', async () => {
    const { handler, corpos } = gravandoEtapa('dados');
    server.use(handler, respondendoOnboarding({ answers: { goal: 'mais-disposicao' } }));

    renderizar(<EtapaDados />);
    const usuario = await preencher('70', '');

    expect(screen.queryByLabelText('Meta de peso (opcional)')).not.toBeInTheDocument();
    await usuario.click(screen.getByRole('button', { name: 'Continuar' }));
    await waitFor(() => expect(corpos).toEqual([expect.objectContaining({ weight_kg: 70, goal_weight_kg: null })]));
  });
});
```

`src/features/onboarding/components/EtapaAtividade.integration.test.tsx`:
```tsx
import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { gravandoEtapa, respondendoOnboarding } from '@/mocks/handlers/onboarding';
import { server } from '@/mocks/server';
import { definirUrl, redefinirNavegacao, roteador } from '@/test/next-navigation';
import { renderizar } from '@/test/renderizar';
import { EtapaAtividade } from './EtapaAtividade';

vi.mock('next/navigation', () => import('@/test/next-navigation'));

beforeEach(() => {
  redefinirNavegacao();
  definirUrl('/onboarding/atividade');
});

describe('Etapa Atividade (S04)', () => {
  it('só continua com as duas escolhas, e salva as duas', async () => {
    const { handler, corpos } = gravandoEtapa('atividade');
    server.use(handler);
    const usuario = userEvent.setup();

    renderizar(<EtapaAtividade />);
    await usuario.click(await screen.findByRole('radio', { name: /3 ou 4 vezes na semana/ }));
    expect(screen.getByRole('button', { name: 'Continuar' })).toBeDisabled();
    await usuario.click(screen.getByRole('radio', { name: 'Sentada' }));
    await usuario.click(screen.getByRole('button', { name: 'Continuar' }));

    await waitFor(() => expect(roteador.push).toHaveBeenCalledWith('/onboarding/preferencias'));
    expect(corpos).toEqual([{ activity_level: 'moderado', work_posture: 'sentada' }]);
    expect(screen.getByRole('link', { name: 'Voltar' })).toHaveAttribute('href', '/onboarding/dados');
  });

  it('abre com o que foi salvo', async () => {
    server.use(respondendoOnboarding({ answers: { activity_level: 'intenso', work_posture: 'em-pe' } }));

    renderizar(<EtapaAtividade />);

    expect(await screen.findByRole('radio', { name: /5 ou 6 vezes na semana/ })).toHaveAttribute('aria-checked', 'true');
    expect(screen.getByRole('radio', { name: 'Em pé' })).toHaveAttribute('aria-checked', 'true');
  });
});
```

Run: `docker compose run --rm web npx vitest run --project integration src/features/onboarding`
Expected: FAIL — `./EtapaObjetivo`, `./EtapaDados`, `./EtapaAtividade` não resolvem.

- [ ] **Step 2: Implementar**

`src/features/onboarding/components/EtapaObjetivo.tsx`:
```tsx
"use client";

import { useState } from "react";
import { OptionRow } from "@/components/ui/OptionRow";
import { cascata } from "@/lib/motion";
import type { Goal } from "@/lib/types";
import { erroDe } from "../regras";
import type { Catalogo, Respostas } from "../tipos";
import { type Etapa, useEtapa } from "../useEtapa";
import { EtapaEsperando } from "./EtapaEsperando";
import { MensagemDoGrupo } from "./MensagemDoGrupo";
import { OnboardingStep } from "./OnboardingStep";

/** S02 — sem pré-seleção, para não enviesar a escolha. */
export function EtapaObjetivo() {
  const etapa = useEtapa("objetivo");
  if (!etapa.catalogo || !etapa.dados) return <EtapaEsperando etapa={etapa} />;
  return <FormObjetivo etapa={etapa} catalogo={etapa.catalogo} respostas={etapa.dados.answers} />;
}

function FormObjetivo({ etapa, catalogo, respostas }: { etapa: Etapa; catalogo: Catalogo; respostas: Respostas }) {
  const [objetivo, setObjetivo] = useState<Goal | null>(respostas.goal);

  return (
    <OnboardingStep {...etapa.casca} podeContinuar={objetivo !== null} aoContinuar={() => void etapa.salvar({ goal: objetivo })}>
      <div className="flex flex-col gap-2.5" role="radiogroup" aria-label="Objetivo">
        {catalogo.goals.map((opcao, i) => (
          <OptionRow
            key={opcao.value}
            className="animate-entra"
            style={cascata(i, 70, 180)}
            marcado={objetivo === opcao.value}
            onClick={() => setObjetivo(opcao.value)}
            titulo={opcao.label}
            descricao={opcao.description}
          />
        ))}
      </div>
      <MensagemDoGrupo texto={erroDe(etapa.errosCampo, "goal")} />
    </OnboardingStep>
  );
}
```

`src/features/onboarding/components/EtapaDados.tsx`:
```tsx
"use client";

import { useState } from "react";
import { Field, Segmento } from "@/components/ui/Field";
import { cascata } from "@/lib/motion";
import { type CamposDados, erroDe, escreverNumero, lerNumero, pedeMeta, validarDados } from "../regras";
import type { Respostas, Sexo } from "../tipos";
import { type Etapa, useEtapa } from "../useEtapa";
import { EtapaEsperando } from "./EtapaEsperando";
import { GoalWeightField } from "./GoalWeightField";
import { OnboardingStep } from "./OnboardingStep";

const SEXOS: { valor: Sexo; rotulo: string }[] = [
  { valor: "feminino", rotulo: "Feminino" },
  { valor: "masculino", rotulo: "Masculino" },
  { valor: "nao-dizer", rotulo: "Prefiro não dizer" },
];

/** S03 — dados corporais e meta de peso (RN09, RN10). No modo edição, o peso vira a pesagem de hoje (RN34). */
export function EtapaDados() {
  const etapa = useEtapa("dados");
  if (!etapa.catalogo || !etapa.dados) return <EtapaEsperando etapa={etapa} />;
  return <FormDados etapa={etapa} respostas={etapa.dados.answers} />;
}

function FormDados({ etapa, respostas }: { etapa: Etapa; respostas: Respostas }) {
  const objetivo = respostas.goal;
  const [campos, setCampos] = useState<CamposDados>(() => ({
    preferredName: respostas.preferredName ?? "",
    age: respostas.age?.toString() ?? "",
    heightCm: respostas.heightCm?.toString() ?? "",
    weightKg: escreverNumero(respostas.weightKg),
    sex: respostas.sex,
    // Meta sugerida ou automática não volta para o campo: vazio quer dizer "sugira para mim".
    goalWeightKg: respostas.goalWeightSource === "user" ? escreverNumero(respostas.goalWeightKg) : "",
  }));
  const [errosLocais, setErrosLocais] = useState<Partial<Record<keyof CamposDados, string>>>({});

  function mudar<C extends keyof CamposDados>(campo: C, valor: CamposDados[C]) {
    setCampos((atual) => ({ ...atual, [campo]: valor }));
    setErrosLocais((atual) => ({ ...atual, [campo]: undefined }));
  }

  const erro = (campo: keyof CamposDados) => errosLocais[campo] ?? erroDe(etapa.errosCampo, campo);

  function continuar() {
    const encontrados = validarDados(campos, objetivo);
    setErrosLocais(encontrados);
    if (Object.keys(encontrados).length > 0) return;

    void etapa.salvar({
      preferredName: campos.preferredName.trim(),
      age: Number(campos.age),
      heightCm: Number(campos.heightCm),
      weightKg: lerNumero(campos.weightKg),
      sex: campos.sex,
      goalWeightKg: pedeMeta(objetivo) ? lerNumero(campos.goalWeightKg) : null,
    });
  }

  const altura = lerNumero(campos.heightCm);

  return (
    <OnboardingStep {...etapa.casca} aoContinuar={continuar}>
      <div className="flex flex-col gap-4">
        <Field
          id="nome"
          label="Como podemos te chamar"
          autoComplete="given-name"
          value={campos.preferredName}
          placeholder="Seu primeiro nome"
          onChange={(e) => mudar("preferredName", e.target.value)}
          erro={erro("preferredName")}
          className="animate-entra"
          style={cascata(0, 60, 160)}
        />
        <div className="flex animate-entra gap-3" style={cascata(1, 60, 160)}>
          <Field
            id="idade"
            label="Idade"
            sufixo="anos"
            inputMode="numeric"
            className="flex-1"
            value={campos.age}
            placeholder="27"
            onChange={(e) => mudar("age", e.target.value)}
            erro={erro("age")}
          />
          <Field
            id="altura"
            label="Altura"
            sufixo="cm"
            inputMode="numeric"
            className="flex-1"
            value={campos.heightCm}
            placeholder="164"
            onChange={(e) => mudar("heightCm", e.target.value)}
            erro={erro("heightCm")}
          />
        </div>
        <Field
          id="peso"
          label="Peso de hoje"
          sufixo="kg"
          inputMode="decimal"
          value={campos.weightKg}
          placeholder="58,4"
          ajuda={etapa.editando ? "Vira a pesagem de hoje na sua evolução." : "Se não souber agora, a balança da Zfit fica na recepção."}
          onChange={(e) => mudar("weightKg", e.target.value)}
          erro={erro("weightKg")}
          className="animate-entra"
          style={cascata(2, 60, 160)}
        />
        <div className="animate-entra" style={cascata(3, 60, 160)}>
          <Segmento
            label="Sexo biológico"
            valor={campos.sex}
            onChange={(v) => mudar("sex", v)}
            ajuda="Usamos só no cálculo do gasto de energia."
            erro={erro("sex")}
            opcoes={SEXOS}
          />
        </div>
        <GoalWeightField
          objetivo={objetivo}
          alturaCm={altura !== null && !Number.isNaN(altura) ? altura : null}
          valor={campos.goalWeightKg}
          onChange={(v) => mudar("goalWeightKg", v)}
          erro={erro("goalWeightKg")}
          className="animate-entra"
          style={cascata(4, 60, 160)}
        />
      </div>
    </OnboardingStep>
  );
}
```

`src/features/onboarding/components/EtapaAtividade.tsx`:
```tsx
"use client";

import { useState } from "react";
import { Segmento } from "@/components/ui/Field";
import { OptionRow } from "@/components/ui/OptionRow";
import { cascata } from "@/lib/motion";
import type { ActivityLevel } from "@/lib/types";
import { erroDe } from "../regras";
import type { Catalogo, PosturaTrabalho, Respostas } from "../tipos";
import { type Etapa, useEtapa } from "../useEtapa";
import { EtapaEsperando } from "./EtapaEsperando";
import { MensagemDoGrupo } from "./MensagemDoGrupo";
import { OnboardingStep } from "./OnboardingStep";

/** S04 — frequência de treino e tipo de trabalho, ambos obrigatórios. */
export function EtapaAtividade() {
  const etapa = useEtapa("atividade");
  if (!etapa.catalogo || !etapa.dados) return <EtapaEsperando etapa={etapa} />;
  return <FormAtividade etapa={etapa} catalogo={etapa.catalogo} respostas={etapa.dados.answers} />;
}

function FormAtividade({ etapa, catalogo, respostas }: { etapa: Etapa; catalogo: Catalogo; respostas: Respostas }) {
  const [nivel, setNivel] = useState<ActivityLevel | null>(respostas.activityLevel);
  const [postura, setPostura] = useState<PosturaTrabalho | null>(respostas.workPosture);

  return (
    <OnboardingStep
      {...etapa.casca}
      podeContinuar={nivel !== null && postura !== null}
      aoContinuar={() => void etapa.salvar({ activityLevel: nivel, workPosture: postura })}
    >
      <div className="flex flex-col gap-2.5" role="radiogroup" aria-label="Frequência de treino">
        {catalogo.activityLevels.map((opcao, i) => (
          <OptionRow
            key={opcao.value}
            className="animate-entra"
            style={cascata(i, 70, 180)}
            marcado={nivel === opcao.value}
            onClick={() => setNivel(opcao.value)}
            titulo={opcao.label}
            descricao={opcao.description}
          />
        ))}
      </div>
      <MensagemDoGrupo texto={erroDe(etapa.errosCampo, "activityLevel")} />

      <div className="mt-6 animate-entra" style={{ animationDelay: "480ms" }}>
        <Segmento
          label="E fora da academia, como é seu trabalho?"
          valor={postura}
          onChange={setPostura}
          erro={erroDe(etapa.errosCampo, "workPosture")}
          opcoes={catalogo.workPostures.map((p) => ({ valor: p.value, rotulo: p.label }))}
        />
      </div>
    </OnboardingStep>
  );
}
```

`src/app/onboarding/objetivo/page.tsx` (substituir inteiro):
```tsx
import { EtapaObjetivo } from "@/features/onboarding/components/EtapaObjetivo";

export default function Page() {
  return <EtapaObjetivo />;
}
```
`src/app/onboarding/dados/page.tsx` e `src/app/onboarding/atividade/page.tsx`: iguais, com `EtapaDados` e `EtapaAtividade`.

- [ ] **Step 3: Rodar e ver passar**

Run: `docker compose run --rm web npx vitest run --project integration src/features/onboarding`
Expected: PASS.

- [ ] **Step 4: Refinar com `frontend-design` (D11) e verificar**

Carregue `frontend-design:frontend-design` para as três etapas (a Boas-vindas e o mock são a referência): cascata dos cartões, o "pop" do marcador ao escolher, transição entre etapas coerente com `animate-entra-lado`, a faixa saudável aparecendo quando a altura fica válida. Mesmas restrições de sempre (textos, `role`s, rótulos, props intocados; só tokens; `musgo` só no escuro).

Run: `docker compose run --rm web bash -c "npm run lint && npm run typecheck && npm test && npm run build"`
Expected: tudo verde.

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -m "feat(onboarding): etapas Objetivo, Dados e Atividade salvas no servidor

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---
### Task 9: Front — etapas Preferências, Restrições e Rotina ligadas à API

**Files (repo front):**
- Create: `src/features/onboarding/components/{EtapaPreferencias,EtapaRestricoes,EtapaRotina}.tsx`
- Modify: `src/app/onboarding/{preferencias,restricoes,rotina}/page.tsx` (substituir inteiros)
- Test: `src/features/onboarding/components/{EtapaPreferencias,EtapaRestricoes,EtapaRotina}.integration.test.tsx`

**Interfaces:**
- Consumes: Tasks 6–8 (`useEtapa`, `OnboardingStep`, `EtapaEsperando`, `MensagemDoGrupo`, `gravandoEtapa`, `respondendoOnboarding`, `separarOutrasRestricoes`, `validarRotina`, `erroDe`, `MENSAGENS`); `Chip`, `OptionRow`, `EtiquetaAlergia`, `Field` (existentes).
- Produces: `EtapaPreferencias()`, `EtapaRestricoes()`, `EtapaRotina()`. Corpos: `{ pantryItems }` (na ordem do catálogo); `{ restrictions, otherRestrictions }`; `{ wakeTime, trainingTime, sleepTime, trainingDays (ordenado), lunchPlace }`.

- [ ] **Step 1: Testes que devem falhar**

`src/features/onboarding/components/EtapaPreferencias.integration.test.tsx`:
```tsx
import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { gravandoEtapa, respondendoOnboarding } from '@/mocks/handlers/onboarding';
import { server } from '@/mocks/server';
import { definirUrl, redefinirNavegacao, roteador } from '@/test/next-navigation';
import { renderizar } from '@/test/renderizar';
import { EtapaPreferencias } from './EtapaPreferencias';

vi.mock('next/navigation', () => import('@/test/next-navigation'));

beforeEach(() => {
  redefinirNavegacao();
  definirUrl('/onboarding/preferencias');
});

describe('Etapa Preferências (S05)', () => {
  it('marca itens da cozinha, conta, dá a dica e salva na ordem do catálogo', async () => {
    const { handler, corpos } = gravandoEtapa('preferencias');
    server.use(handler);
    const usuario = userEvent.setup();

    renderizar(<EtapaPreferencias />);
    expect(await screen.findByText('Nada marcado ainda')).toBeInTheDocument();
    await usuario.click(screen.getByRole('button', { name: 'Frango' }));
    await usuario.click(screen.getByRole('button', { name: 'Ovos' }));

    expect(screen.getByText('2 alimentos marcados')).toBeInTheDocument();
    expect(screen.getByText('Marque pelo menos uns 5 para o cardápio ficar com a sua cara.')).toBeInTheDocument();
    await usuario.click(screen.getByRole('button', { name: 'Continuar' }));

    await waitFor(() => expect(roteador.push).toHaveBeenCalledWith('/onboarding/restricoes'));
    expect(corpos).toEqual([{ pantry_items: ['ovos', 'frango'] }]);
  });

  it('com 5 ou mais marcados a dica some', async () => {
    server.use(respondendoOnboarding({ answers: { pantry_items: ['ovos', 'frango', 'arroz-e-feijao', 'banana', 'aveia'] } }));

    renderizar(<EtapaPreferencias />);

    expect(await screen.findByText('5 alimentos marcados')).toBeInTheDocument();
    expect(screen.queryByText(/Marque pelo menos uns 5/)).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Aveia' })).toHaveAttribute('aria-pressed', 'true');
  });
});
```

`src/features/onboarding/components/EtapaRestricoes.integration.test.tsx`:
```tsx
import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { gravandoEtapa, respondendoOnboarding } from '@/mocks/handlers/onboarding';
import { server } from '@/mocks/server';
import { definirUrl, redefinirNavegacao, roteador } from '@/test/next-navigation';
import { renderizar } from '@/test/renderizar';
import { MENSAGENS } from '../regras';
import { EtapaRestricoes } from './EtapaRestricoes';

vi.mock('next/navigation', () => import('@/test/next-navigation'));

beforeEach(() => {
  redefinirNavegacao();
  definirUrl('/onboarding/restricoes');
});

describe('Etapa Restrições (S06)', () => {
  it('marca a alergia e separa "Mais alguma coisa" em itens', async () => {
    const { handler, corpos } = gravandoEtapa('restricoes');
    server.use(handler);
    const usuario = userEvent.setup();

    renderizar(<EtapaRestricoes />);
    const castanhas = await screen.findByRole('checkbox', { name: /Amendoim e castanhas/ });
    expect(castanhas).toHaveTextContent('Alergia');
    await usuario.click(castanhas);
    await usuario.type(screen.getByLabelText('Mais alguma coisa'), 'camarão, pimenta,,');
    await usuario.click(screen.getByRole('button', { name: 'Continuar' }));

    await waitFor(() => expect(roteador.push).toHaveBeenCalledWith('/onboarding/rotina'));
    expect(corpos).toEqual([{ restrictions: ['castanhas'], other_restrictions: ['camarão', 'pimenta'] }]);
  });

  it('abre com o que foi salvo', async () => {
    server.use(respondendoOnboarding({ answers: { restrictions: ['castanhas'], other_restrictions: ['camarão', 'pimenta'] } }));

    renderizar(<EtapaRestricoes />);

    expect(await screen.findByRole('checkbox', { name: /Amendoim e castanhas/ })).toHaveAttribute('aria-checked', 'true');
    expect(screen.getByLabelText('Mais alguma coisa')).toHaveValue('camarão, pimenta');
  });

  it('recusa mais de 10 itens, sem enviar', async () => {
    const { handler, corpos } = gravandoEtapa('restricoes');
    server.use(handler);
    const usuario = userEvent.setup();

    renderizar(<EtapaRestricoes />);
    await usuario.type(await screen.findByLabelText('Mais alguma coisa'), 'a1, a2, a3, a4, a5, a6, a7, a8, a9, b1, b2');
    await usuario.click(screen.getByRole('button', { name: 'Continuar' }));

    expect(await screen.findByText(MENSAGENS.outrasMuitas)).toBeInTheDocument();
    expect(corpos).toEqual([]);
  });
});
```

`src/features/onboarding/components/EtapaRotina.integration.test.tsx`:
```tsx
import { fireEvent, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { gravandoEtapa, respondendoOnboarding } from '@/mocks/handlers/onboarding';
import { server } from '@/mocks/server';
import { definirUrl, redefinirNavegacao, roteador } from '@/test/next-navigation';
import { renderizar } from '@/test/renderizar';
import { MENSAGENS } from '../regras';
import { EtapaRotina } from './EtapaRotina';

vi.mock('next/navigation', () => import('@/test/next-navigation'));

beforeEach(() => {
  redefinirNavegacao();
  definirUrl('/onboarding/rotina');
});

describe('Etapa Rotina (S07)', () => {
  it('salva a rotina do mock', async () => {
    const { handler, corpos } = gravandoEtapa('rotina');
    server.use(handler);
    const usuario = userEvent.setup();

    renderizar(<EtapaRotina />);
    for (const dia of ['sexta', 'segunda', 'quarta']) await usuario.click(await screen.findByRole('button', { name: dia }));
    await usuario.click(screen.getByRole('button', { name: 'Marmita no trabalho' }));
    expect(screen.getByText(/Quem leva marmita/)).toBeInTheDocument();
    await usuario.click(screen.getByRole('button', { name: 'Continuar' }));

    await waitFor(() => expect(roteador.push).toHaveBeenCalledWith('/onboarding/resumo'));
    expect(corpos).toEqual([
      { wake_time: '06:20', training_time: '19:00', sleep_time: '23:00', training_days: [1, 3, 5], lunch_place: 'marmita' },
    ]);
  });

  it('treino antes de acordar mostra o erro no campo e não envia (CA06)', async () => {
    const { handler, corpos } = gravandoEtapa('rotina');
    server.use(handler, respondendoOnboarding({ answers: { lunch_place: 'casa' } }));

    renderizar(<EtapaRotina />);
    fireEvent.change(await screen.findByLabelText('Treina às'), { target: { value: '05:00' } });
    await userEvent.setup().click(screen.getByRole('button', { name: 'Continuar' }));

    expect(await screen.findByText(MENSAGENS.treinoForaDaJanela)).toBeInTheDocument();
    expect(screen.getByLabelText('Treina às')).toHaveAttribute('aria-invalid', 'true');
    expect(corpos).toEqual([]);
  });

  it('pede o lugar do almoço', async () => {
    renderizar(<EtapaRotina />);
    await userEvent.setup().click(await screen.findByRole('button', { name: 'Continuar' }));

    expect(await screen.findByText(MENSAGENS.almoco)).toBeInTheDocument();
  });
});
```

Run: `docker compose run --rm web npx vitest run --project integration src/features/onboarding`
Expected: FAIL — os três componentes não resolvem.

- [ ] **Step 2: Implementar**

`src/features/onboarding/components/EtapaPreferencias.tsx`:
```tsx
"use client";

import { useState } from "react";
import { Chip } from "@/components/ui/Chip";
import { cascata } from "@/lib/motion";
import { erroDe } from "../regras";
import type { Catalogo, Respostas } from "../tipos";
import { type Etapa, useEtapa } from "../useEtapa";
import { EtapaEsperando } from "./EtapaEsperando";
import { MensagemDoGrupo } from "./MensagemDoGrupo";
import { OnboardingStep } from "./OnboardingStep";

/** S05 — itens da cozinha, vindos do catálogo. Nenhum é obrigatório. */
export function EtapaPreferencias() {
  const etapa = useEtapa("preferencias");
  if (!etapa.catalogo || !etapa.dados) return <EtapaEsperando etapa={etapa} />;
  return <FormPreferencias etapa={etapa} catalogo={etapa.catalogo} respostas={etapa.dados.answers} />;
}

function FormPreferencias({ etapa, catalogo, respostas }: { etapa: Etapa; catalogo: Catalogo; respostas: Respostas }) {
  const [marcados, setMarcados] = useState<string[]>(respostas.pantryItems);
  const total = marcados.length;
  const ordem = catalogo.pantry.flatMap((grupo) => grupo.items.map((item) => item.slug));

  const alternar = (slug: string) =>
    setMarcados((atual) => (atual.includes(slug) ? atual.filter((s) => s !== slug) : [...atual, slug]));

  return (
    <OnboardingStep
      {...etapa.casca}
      aoContinuar={() => void etapa.salvar({ pantryItems: ordem.filter((slug) => marcados.includes(slug)) })}
      acimaDoBotao={
        <div className="mb-2.5 text-center" aria-live="polite">
          <p className="text-[12.5px] text-fumo">
            {total === 0 ? "Nada marcado ainda" : `${total} ${total === 1 ? "alimento marcado" : "alimentos marcados"}`}
          </p>
          {total < 5 ? (
            <p className="mt-0.5 text-[12.5px] text-fumo">Marque pelo menos uns 5 para o cardápio ficar com a sua cara.</p>
          ) : null}
        </div>
      }
    >
      <div className="flex flex-col gap-[18px]">
        {catalogo.pantry.map((grupo, g) => (
          <div key={grupo.category}>
            <span className="animate-entra text-[12.5px] font-semibold text-fumo" style={cascata(g, 110, 160)}>
              {grupo.label}
            </span>
            <div className="mt-2.5 flex flex-wrap gap-2">
              {grupo.items.map((item, i) => (
                <Chip
                  key={item.slug}
                  className="animate-escala"
                  style={cascata(i, 34, 200 + g * 110)}
                  marcado={marcados.includes(item.slug)}
                  onClick={() => alternar(item.slug)}
                >
                  {item.label}
                </Chip>
              ))}
            </div>
          </div>
        ))}
      </div>
      <MensagemDoGrupo texto={erroDe(etapa.errosCampo, "pantryItems")} />
    </OnboardingStep>
  );
}
```

`src/features/onboarding/components/EtapaRestricoes.tsx`:
```tsx
"use client";

import { useState } from "react";
import { Field } from "@/components/ui/Field";
import { EtiquetaAlergia, OptionRow } from "@/components/ui/OptionRow";
import { cascata } from "@/lib/motion";
import { erroDe, MENSAGENS, separarOutrasRestricoes } from "../regras";
import type { Catalogo, Respostas } from "../tipos";
import { type Etapa, useEtapa } from "../useEtapa";
import { EtapaEsperando } from "./EtapaEsperando";
import { MensagemDoGrupo } from "./MensagemDoGrupo";
import { OnboardingStep } from "./OnboardingStep";

/** S06 — restrições do catálogo + "Mais alguma coisa" separado por vírgula (RN16/RN17 usam os dois). */
export function EtapaRestricoes() {
  const etapa = useEtapa("restricoes");
  if (!etapa.catalogo || !etapa.dados) return <EtapaEsperando etapa={etapa} />;
  return <FormRestricoes etapa={etapa} catalogo={etapa.catalogo} respostas={etapa.dados.answers} />;
}

function FormRestricoes({ etapa, catalogo, respostas }: { etapa: Etapa; catalogo: Catalogo; respostas: Respostas }) {
  const [marcadas, setMarcadas] = useState<string[]>(respostas.restrictions);
  const [outras, setOutras] = useState(respostas.otherRestrictions.join(", "));
  const [erroOutras, setErroOutras] = useState<string>();

  const alternar = (slug: string) =>
    setMarcadas((atual) => (atual.includes(slug) ? atual.filter((s) => s !== slug) : [...atual, slug]));

  function continuar() {
    const lista = separarOutrasRestricoes(outras);
    const problema =
      lista.length > 10
        ? MENSAGENS.outrasMuitas
        : lista.some((item) => [...item].length < 2 || [...item].length > 60)
          ? MENSAGENS.outraTamanho
          : undefined;
    setErroOutras(problema);
    if (problema) return;

    void etapa.salvar({
      restrictions: catalogo.restrictions.map((r) => r.slug).filter((slug) => marcadas.includes(slug)),
      otherRestrictions: lista,
    });
  }

  return (
    <OnboardingStep {...etapa.casca} aoContinuar={continuar}>
      <div className="flex flex-col gap-2">
        {catalogo.restrictions.map((restricao, i) => (
          <OptionRow
            key={restricao.slug}
            className="animate-entra"
            style={cascata(i, 55, 180)}
            quadrado
            compacto
            marcado={marcadas.includes(restricao.slug)}
            onClick={() => alternar(restricao.slug)}
            titulo={restricao.label}
            etiqueta={restricao.isAllergy ? <EtiquetaAlergia /> : undefined}
          />
        ))}
      </div>
      <MensagemDoGrupo texto={erroDe(etapa.errosCampo, "restrictions")} />

      <Field
        id="outra"
        label="Mais alguma coisa"
        className="mt-[18px] animate-entra"
        style={{ animationDelay: "520ms" }}
        placeholder="Ex.: camarão, pimenta, leite de vaca"
        ajuda="Separe por vírgula."
        value={outras}
        onChange={(e) => {
          setOutras(e.target.value);
          setErroOutras(undefined);
        }}
        erro={erroOutras ?? erroDe(etapa.errosCampo, "otherRestrictions")}
      />
    </OnboardingStep>
  );
}
```

`src/features/onboarding/components/EtapaRotina.tsx`:
```tsx
"use client";

import { useState } from "react";
import { Chip } from "@/components/ui/Chip";
import { cascata } from "@/lib/motion";
import { type CamposRotina, erroDe, validarRotina } from "../regras";
import type { Catalogo, LocalAlmoco, Respostas } from "../tipos";
import { type Etapa, useEtapa } from "../useEtapa";
import { EtapaEsperando } from "./EtapaEsperando";
import { MensagemDoGrupo } from "./MensagemDoGrupo";
import { OnboardingStep } from "./OnboardingStep";

const DIAS = [
  { n: 0, letra: "D", nome: "domingo" },
  { n: 1, letra: "S", nome: "segunda" },
  { n: 2, letra: "T", nome: "terça" },
  { n: 3, letra: "Q", nome: "quarta" },
  { n: 4, letra: "Q", nome: "quinta" },
  { n: 5, letra: "S", nome: "sexta" },
  { n: 6, letra: "S", nome: "sábado" },
];

const HORARIOS = [
  { id: "acorda", rotulo: "Acorda às", campo: "wakeTime" },
  { id: "treina", rotulo: "Treina às", campo: "trainingTime" },
  { id: "dorme", rotulo: "Dorme às", campo: "sleepTime" },
] as const;

type Horas = Pick<CamposRotina, "wakeTime" | "trainingTime" | "sleepTime">;

/** S07 — horários, dias de treino e almoço (RN12). Os horários das refeições saem daqui (RN14). */
export function EtapaRotina() {
  const etapa = useEtapa("rotina");
  if (!etapa.catalogo || !etapa.dados) return <EtapaEsperando etapa={etapa} />;
  return <FormRotina etapa={etapa} catalogo={etapa.catalogo} respostas={etapa.dados.answers} />;
}

function FormRotina({ etapa, catalogo, respostas }: { etapa: Etapa; catalogo: Catalogo; respostas: Respostas }) {
  const [horas, setHoras] = useState<Horas>({
    wakeTime: respostas.wakeTime ?? "06:20",
    trainingTime: respostas.trainingTime ?? "19:00",
    sleepTime: respostas.sleepTime ?? "23:00",
  });
  const [dias, setDias] = useState<number[]>(respostas.trainingDays);
  const [almoco, setAlmoco] = useState<LocalAlmoco | null>(respostas.lunchPlace);
  const [errosLocais, setErrosLocais] = useState<Partial<Record<keyof CamposRotina, string>>>({});

  const erro = (campo: keyof CamposRotina) => errosLocais[campo] ?? erroDe(etapa.errosCampo, campo);

  function mudarHora(campo: keyof Horas, valor: string) {
    setHoras((atual) => ({ ...atual, [campo]: valor }));
    setErrosLocais((atual) => ({ ...atual, [campo]: undefined }));
  }

  function continuar() {
    const encontrados = validarRotina({ ...horas, lunchPlace: almoco });
    setErrosLocais(encontrados);
    if (Object.keys(encontrados).length > 0) return;
    void etapa.salvar({ ...horas, trainingDays: [...dias].sort((a, b) => a - b), lunchPlace: almoco });
  }

  return (
    <OnboardingStep {...etapa.casca} aoContinuar={continuar}>
      <div className="rounded-[18px] bg-white px-4">
        {HORARIOS.map(({ id, rotulo, campo }, i) => {
          const mensagem = erro(campo);
          return (
            <div
              key={id}
              style={cascata(i, 70, 180)}
              className={`animate-entra py-2 ${i < HORARIOS.length - 1 ? "border-b border-fio" : ""}`}
            >
              <div className="flex min-h-12 items-center justify-between">
                <label htmlFor={id} className="text-[15px] font-medium">
                  {rotulo}
                </label>
                <input
                  id={id}
                  type="time"
                  value={horas[campo]}
                  aria-invalid={mensagem ? true : undefined}
                  aria-describedby={mensagem ? `${id}-mensagem` : undefined}
                  onChange={(e) => mudarHora(campo, e.target.value)}
                  className={`h-12 w-[110px] rounded-xl border bg-white text-center font-display text-[19px] font-semibold tracking-[-0.01em] focus:outline-none ${
                    mensagem ? "border-alerta" : "border-linha focus:border-tinta focus:shadow-[inset_0_0_0_1px_var(--color-tinta)]"
                  }`}
                />
              </div>
              {mensagem ? (
                <p id={`${id}-mensagem`} className="mb-1 animate-entra text-[12.5px] leading-snug font-medium text-alerta">
                  {mensagem}
                </p>
              ) : null}
            </div>
          );
        })}
      </div>

      <div className="mt-5">
        <span className="mb-2.5 block text-[12.5px] font-semibold text-fumo">Dias de treino</span>
        <div className="flex justify-between">
          {DIAS.map((dia, i) => {
            const marcado = dias.includes(dia.n);
            return (
              <button
                key={dia.n}
                type="button"
                style={cascata(i, 45, 420)}
                aria-pressed={marcado}
                aria-label={dia.nome}
                onClick={() => setDias((atual) => (marcado ? atual.filter((d) => d !== dia.n) : [...atual, dia.n]))}
                className={`flex size-10 animate-pop items-center justify-center rounded-full border text-sm font-semibold transition-[background-color,color,border-color,transform] duration-250 ease-[cubic-bezier(.34,1.56,.64,1)] active:scale-90 ${
                  marcado ? "scale-105 border-tinta bg-tinta text-white" : "border-linha bg-white text-tinta hover:border-pedra"
                }`}
              >
                {dia.letra}
              </button>
            );
          })}
        </div>
      </div>

      <div className="mt-6">
        <span className="mb-2.5 block text-[12.5px] font-semibold text-fumo">Onde você almoça durante a semana?</span>
        <div className="flex flex-wrap gap-2">
          {catalogo.lunchPlaces.map((lugar, i) => (
            <Chip
              key={lugar.value}
              className="animate-escala"
              style={cascata(i, 60, 560)}
              marcado={almoco === lugar.value}
              onClick={() => {
                setAlmoco(lugar.value);
                setErrosLocais((atual) => ({ ...atual, lunchPlace: undefined }));
              }}
            >
              {lugar.label}
            </Chip>
          ))}
        </div>
        <MensagemDoGrupo texto={erro("lunchPlace")} />
        {almoco === "marmita" ? (
          <p className="mt-2.5 animate-entra text-[12.5px] leading-snug text-fumo">
            Quem leva marmita ganha sugestões que aguentam a manhã inteira na bolsa.
          </p>
        ) : null}
      </div>
    </OnboardingStep>
  );
}
```

`src/app/onboarding/{preferencias,restricoes,rotina}/page.tsx` — como na Task 8, com `EtapaPreferencias`, `EtapaRestricoes` e `EtapaRotina`.

- [ ] **Step 3: Rodar e ver passar**

Run: `docker compose run --rm web npx vitest run --project integration src/features/onboarding`
Expected: PASS.

- [ ] **Step 4: Refinar com `frontend-design` (D11) e verificar**

Carregue `frontend-design:frontend-design` para as três etapas: o contador com número que "conta" (`CountUp` já existe), a etiqueta "Alergia" com `animate-pop`, os dias de treino com mola, a dica da marmita entrando. Mesmas restrições.

Run: `docker compose run --rm web bash -c "npm run lint && npm run typecheck && npm test && npm run build"`
Expected: tudo verde.

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -m "feat(onboarding): etapas Preferências, Restrições e Rotina salvas no servidor

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---
### Task 10: Front — Resumo com prévia e conclusão; fim do `localStorage` do onboarding

**Files (repo front):**
- Create: `src/features/onboarding/resumo.ts`, `src/features/onboarding/components/{EtapaResumo,SummaryList,PreviaDeMetas}.tsx`
- Modify: `src/app/onboarding/resumo/page.tsx` (substituir inteiro), `src/app/onboarding/layout.tsx`, `src/app/onboarding/gerando/page.tsx`, `src/app/onboarding/pronto/page.tsx`, `eslint.config.mjs`
- Delete: `src/lib/onboarding-store.tsx`, `src/components/app/OnboardingStep.tsx`
- Test: `src/features/onboarding/resumo.test.ts`, `src/features/onboarding/components/{SummaryList,PreviaDeMetas}.stories.tsx`, `src/features/onboarding/components/EtapaResumo.integration.test.tsx`

**Interfaces:**
- Consumes: `useCatalogo`, `useDadosOnboarding`, `usePrevia`, `useConcluirOnboarding` (Task 6); `OnboardingStep` (Task 7); `recarregarEm` (Plano 02); `kcal`, `peso` (`lib/format`); `DIAS_CURTOS` (`lib/labels`).
- Produces:
  - `linhasDoResumo(respostas: Respostas, catalogo: Catalogo): LinhaDoResumo[]` com `LinhaDoResumo = { etapa: EtapaEditavel; rotulo; valor; alerta? }`.
  - `SummaryList({ linhas })` — cada linha com "Editar {linha}" → `/onboarding/{etapa}?de=resumo`.
  - `PreviaDeMetas({ estado: 'carregando' | 'indisponivel' | Previa })`.
  - `EtapaResumo()` — "Gerar meu plano" → `POST /onboarding/complete` → recarga em `/onboarding/gerando`; 422 com `details.step` → aviso "Falta completar esta etapa." e `/onboarding/{step}?de=resumo`.

- [ ] **Step 1: Testes que devem falhar**

`src/features/onboarding/resumo.test.ts`:
```ts
import { describe, expect, it } from 'vitest';
import { camelizar } from '@/lib/api/case';
import { catalogoApi, respostasDaCamila, respostasVazias } from '@/mocks/fixtures/onboarding';
import { linhasDoResumo } from './resumo';
import type { Catalogo, Respostas } from './tipos';

const catalogo = camelizar<Catalogo>(catalogoApi);

describe('linhasDoResumo', () => {
  it('monta as seis linhas da Camila, com a alergia em destaque', () => {
    expect(linhasDoResumo(camelizar<Respostas>(respostasDaCamila), catalogo)).toEqual([
      { etapa: 'objetivo', rotulo: 'Objetivo', valor: 'Ganhar massa magra, meta 62,0 kg' },
      { etapa: 'dados', rotulo: 'Você', valor: 'Camila, 27 anos, 1,64 m, 58,4 kg' },
      { etapa: 'atividade', rotulo: 'Treino', valor: '3 ou 4 vezes na semana, às 19:00' },
      { etapa: 'preferencias', rotulo: 'Sua cozinha', valor: '3 alimentos marcados' },
      { etapa: 'restricoes', rotulo: 'Restrições', valor: 'Amendoim e castanhas, camarão', alerta: true },
      { etapa: 'rotina', rotulo: 'Rotina', valor: 'Acorda 06:20, dorme 23:00. Treina seg, qua, sex. Almoço: marmita no trabalho.' },
    ]);
  });

  it('não quebra com respostas vazias', () => {
    const linhas = linhasDoResumo(camelizar<Respostas>(respostasVazias), catalogo);
    expect(linhas.map((l) => l.valor)).toEqual([
      '—',
      'Camila',
      '—',
      'Nada marcado ainda',
      'Nenhuma',
      'Acorda —, dorme —. Treina em nenhum dia marcado.',
    ]);
  });
});
```

`src/features/onboarding/components/SummaryList.stories.tsx`:
```tsx
import type { Meta, StoryObj } from '@storybook/nextjs-vite';
import { expect, within } from 'storybook/test';
import { SummaryList } from './SummaryList';

const meta = {
  title: 'Onboarding/SummaryList',
  component: SummaryList,
  args: {
    linhas: [
      { etapa: 'objetivo', rotulo: 'Objetivo', valor: 'Ganhar massa magra' },
      { etapa: 'restricoes', rotulo: 'Restrições', valor: 'Amendoim e castanhas', alerta: true },
    ],
  },
} satisfies Meta<typeof SummaryList>;

export default meta;
type Story = StoryObj<typeof meta>;

export const ComAlergia: Story = {
  play: async ({ canvasElement }) => {
    const tela = within(canvasElement);
    await expect(tela.getByText('Amendoim e castanhas')).toHaveClass('text-alerta');
    await expect(tela.getByRole('link', { name: 'Editar objetivo' })).toHaveAttribute('href', '/onboarding/objetivo?de=resumo');
  },
};
```

`src/features/onboarding/components/PreviaDeMetas.stories.tsx`:
```tsx
import type { Meta, StoryObj } from '@storybook/nextjs-vite';
import { expect, within } from 'storybook/test';
import { PreviaDeMetas } from './PreviaDeMetas';

const meta = {
  title: 'Onboarding/PreviaDeMetas',
  component: PreviaDeMetas,
  args: { estado: { kcal: 2250, proteinG: 115, carbsG: 305, fatG: 65, meals: 5 } },
} satisfies Meta<typeof PreviaDeMetas>;

export default meta;
type Story = StoryObj<typeof meta>;

export const Pronta: Story = {
  play: async ({ canvasElement }) => {
    await expect(within(canvasElement).getByText(/Com isso, seu plano começa em/)).toHaveTextContent(
      'Com isso, seu plano começa em 2.250 kcal por dia, com 115 g de proteína divididos em 5 refeições.',
    );
  },
};

export const PreviaCarregando: Story = {
  args: { estado: 'carregando' },
  play: async ({ canvasElement }) => {
    await expect(within(canvasElement).getByLabelText('Calculando suas metas')).toHaveAttribute('aria-busy', 'true');
  },
};

export const PreviaIndisponivel: Story = {
  args: { estado: 'indisponivel' },
  play: async ({ canvasElement }) => {
    await expect(within(canvasElement).queryByText(/Com isso/)).toBeNull();
  },
};
```

`src/features/onboarding/components/EtapaResumo.integration.test.tsx`:
```tsx
import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { http, HttpResponse } from 'msw';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { recarregarEm } from '@/lib/navegar';
import { previaApi, respostasDaCamila } from '@/mocks/fixtures/onboarding';
import { erroDaApi, url } from '@/mocks/handlers/auth';
import { respondendoOnboarding } from '@/mocks/handlers/onboarding';
import { server } from '@/mocks/server';
import { definirUrl, redefinirNavegacao, roteador } from '@/test/next-navigation';
import { renderizar } from '@/test/renderizar';
import { EtapaResumo } from './EtapaResumo';

vi.mock('next/navigation', () => import('@/test/next-navigation'));
vi.mock('@/lib/navegar', () => ({ recarregarEm: vi.fn() }));

const SEIS = ['objetivo', 'dados', 'atividade', 'preferencias', 'restricoes', 'rotina'];

beforeEach(() => {
  redefinirNavegacao();
  definirUrl('/onboarding/resumo');
  vi.mocked(recarregarEm).mockReset();
  server.use(
    respondendoOnboarding({ answers: respostasDaCamila, completed_steps: SEIS, next_step: 'resumo' }),
    http.get(url('/plans/preview-targets'), () => HttpResponse.json({ data: previaApi })),
  );
});

describe('Resumo (S08)', () => {
  it('mostra as respostas e a prévia calculada pelo backend (CA10)', async () => {
    renderizar(<EtapaResumo />);

    expect(await screen.findByText(/Com isso, seu plano começa em/)).toHaveTextContent(
      'Com isso, seu plano começa em 2.250 kcal por dia, com 115 g de proteína divididos em 5 refeições.',
    );
    expect(screen.getByText('Amendoim e castanhas, camarão')).toHaveClass('text-alerta');
    expect(screen.getByRole('link', { name: 'Editar rotina' })).toHaveAttribute('href', '/onboarding/rotina?de=resumo');
    expect(screen.getByRole('link', { name: 'Voltar' })).toHaveAttribute('href', '/onboarding/rotina');
  });

  it('se a prévia falhar, esconde o bloco e deixa gerar', async () => {
    server.use(http.get(url('/plans/preview-targets'), () => erroDaApi(500, 'SERVER_ERROR', 'Algo deu errado do nosso lado. Tente de novo.')));

    renderizar(<EtapaResumo />);

    expect(await screen.findByRole('button', { name: 'Gerar meu plano' })).toBeEnabled();
    await waitFor(() => expect(screen.queryByLabelText('Calculando suas metas')).not.toBeInTheDocument());
    expect(screen.queryByText(/Com isso/)).not.toBeInTheDocument();
  });

  it('conclui e recarrega na tela Gerando', async () => {
    let concluiu = false;
    server.use(
      http.post(url('/onboarding/complete'), () => {
        concluiu = true;
        return HttpResponse.json({ data: { plan: null } }, { status: 202 });
      }),
    );

    renderizar(<EtapaResumo />);
    await userEvent.setup().click(await screen.findByRole('button', { name: 'Gerar meu plano' }));

    await waitFor(() => expect(recarregarEm).toHaveBeenCalledWith('/onboarding/gerando'));
    expect(concluiu).toBe(true);
  });

  it('etapa incompleta leva a ela, avisando', async () => {
    server.use(
      http.post(url('/onboarding/complete'), () =>
        erroDaApi(422, 'VALIDATION_ERROR', 'Falta completar uma etapa.', { details: { step: 'rotina' } }),
      ),
    );

    renderizar(<EtapaResumo />);
    await userEvent.setup().click(await screen.findByRole('button', { name: 'Gerar meu plano' }));

    await waitFor(() => expect(roteador.push).toHaveBeenCalledWith('/onboarding/rotina?de=resumo'));
    expect(await screen.findByText('Falta completar esta etapa.')).toBeInTheDocument();
    expect(recarregarEm).not.toHaveBeenCalled();
  });

  it('sem rede, mostra o erro e libera o botão', async () => {
    server.use(http.post(url('/onboarding/complete'), () => HttpResponse.error()));

    renderizar(<EtapaResumo />);
    await userEvent.setup().click(await screen.findByRole('button', { name: 'Gerar meu plano' }));

    expect(await screen.findByRole('alert')).toHaveTextContent('Sem conexão. Confira a internet e tente de novo.');
    expect(screen.getByRole('button', { name: 'Gerar meu plano' })).toBeEnabled();
  });
});
```

Run: `docker compose run --rm web bash -c "npx vitest run --project unit --project integration src/features/onboarding; npx vitest run --project storybook src/features/onboarding"`
Expected: FAIL — `./resumo`, `./EtapaResumo`, `./SummaryList`, `./PreviaDeMetas` não resolvem.

- [ ] **Step 2: Implementar**

`src/features/onboarding/resumo.ts`:
```ts
import { peso } from '@/lib/format';
import { DIAS_CURTOS } from '@/lib/labels';
import type { Catalogo, EtapaEditavel, Respostas } from './tipos';

export interface LinhaDoResumo {
  etapa: EtapaEditavel;
  rotulo: string;
  valor: string;
  alerta?: boolean;
}

const metros = (cm: number) => `${(cm / 100).toFixed(2).replace('.', ',')} m`;
const plural = (n: number, um: string, varios: string) => `${n} ${n === 1 ? um : varios}`;

/** Linhas do resumo (S08), com rótulos do catálogo. A linha de restrições fica em `alerta` se há alergia. */
export function linhasDoResumo(r: Respostas, c: Catalogo): LinhaDoResumo[] {
  const objetivo = c.goals.find((g) => g.value === r.goal)?.label ?? '—';
  const meta = r.goalWeightKg !== null && r.goalWeightSource === 'user' ? `, meta ${peso(r.goalWeightKg)}` : '';
  const pessoa = [
    r.preferredName || 'Você',
    r.age ? `${r.age} anos` : null,
    r.heightCm ? metros(r.heightCm) : null,
    r.weightKg ? peso(r.weightKg) : null,
  ].filter(Boolean);
  const nivel = c.activityLevels.find((a) => a.value === r.activityLevel)?.label ?? '—';
  const restricoes = c.restrictions.filter((x) => r.restrictions.includes(x.slug));
  const naoPode = [...restricoes.map((x) => x.label), ...r.otherRestrictions];
  const dias = r.trainingDays.map((d) => DIAS_CURTOS[d]).join(', ');
  const almoco = c.lunchPlaces.find((l) => l.value === r.lunchPlace)?.label;

  return [
    { etapa: 'objetivo', rotulo: 'Objetivo', valor: `${objetivo}${meta}` },
    { etapa: 'dados', rotulo: 'Você', valor: pessoa.join(', ') },
    { etapa: 'atividade', rotulo: 'Treino', valor: `${nivel}${r.trainingTime ? `, às ${r.trainingTime}` : ''}` },
    {
      etapa: 'preferencias',
      rotulo: 'Sua cozinha',
      valor: r.pantryItems.length === 0 ? 'Nada marcado ainda' : plural(r.pantryItems.length, 'alimento marcado', 'alimentos marcados'),
    },
    {
      etapa: 'restricoes',
      rotulo: 'Restrições',
      valor: naoPode.length > 0 ? naoPode.join(', ') : 'Nenhuma',
      ...(restricoes.some((x) => x.isAllergy) ? { alerta: true } : {}),
    },
    {
      etapa: 'rotina',
      rotulo: 'Rotina',
      valor: `Acorda ${r.wakeTime ?? '—'}, dorme ${r.sleepTime ?? '—'}. Treina ${dias || 'em nenhum dia marcado'}.${almoco ? ` Almoço: ${almoco.toLowerCase()}.` : ''}`,
    },
  ];
}
```

`src/features/onboarding/components/SummaryList.tsx`:
```tsx
import Link from "next/link";
import { cascata } from "@/lib/motion";
import type { LinhaDoResumo } from "../resumo";

/** Linhas do resumo, cada uma com "Editar" que volta ao resumo depois de salvar. */
export function SummaryList({ linhas }: { linhas: LinhaDoResumo[] }) {
  return (
    <div className="rounded-[18px] bg-white px-4">
      {linhas.map((linha, i) => (
        <div
          key={linha.etapa}
          style={cascata(i, 60, 180)}
          className={`flex min-h-[62px] animate-entra items-center gap-3 py-3 ${i < linhas.length - 1 ? "border-b border-fio" : ""}`}
        >
          <div className="flex-1">
            <span className="block text-[12.5px] text-fumo">{linha.rotulo}</span>
            <span className={`mt-0.5 block text-[15px] font-semibold ${linha.alerta ? "text-alerta" : ""}`}>{linha.valor}</span>
          </div>
          <Link
            href={`/onboarding/${linha.etapa}?de=resumo`}
            className="shrink-0 py-2 pl-3 text-[13px] font-semibold text-mata hover:text-tinta"
          >
            Editar
            <span className="sr-only"> {linha.rotulo.toLowerCase()}</span>
          </Link>
        </div>
      ))}
    </div>
  );
}
```

`src/features/onboarding/components/PreviaDeMetas.tsx`:
```tsx
import { Skeleton } from "@/components/ui/Skeleton";
import { kcal } from "@/lib/format";
import type { Previa } from "../tipos";

/** RF08 — prévia das metas no resumo. Com erro, some: não impede gerar o plano. */
export function PreviaDeMetas({ estado }: { estado: "carregando" | "indisponivel" | Previa }) {
  if (estado === "indisponivel") return null;

  return (
    <div className="mt-4 animate-escala rounded-[18px] bg-tinta px-[18px] py-4 text-neve" style={{ animationDelay: "560ms" }}>
      {estado === "carregando" ? (
        <div aria-busy="true" aria-label="Calculando suas metas">
          <Skeleton className="h-4 w-4/5" />
          <Skeleton className="mt-2 h-4 w-3/5" atraso={90} />
        </div>
      ) : (
        <p className="text-[14.5px] leading-normal text-salvia">
          Com isso, seu plano começa em <span className="font-semibold text-gema">{kcal(estado.kcal)}</span> por dia, com{" "}
          {estado.proteinG} g de proteína divididos em {estado.meals} refeições.
        </p>
      )}
    </div>
  );
}
```

`src/features/onboarding/components/EtapaResumo.tsx`:
```tsx
"use client";

import { useRouter } from "next/navigation";
import { useState } from "react";
import { useToast } from "@/components/ui/Toaster";
import { type ApiError, comoApiError } from "@/lib/api/errors";
import { recarregarEm } from "@/lib/navegar";
import { numeroDaEtapa, TEXTOS, TOTAL_ETAPAS } from "../etapas";
import { useCatalogo, useConcluirOnboarding, useDadosOnboarding, usePrevia } from "../hooks";
import { linhasDoResumo } from "../resumo";
import { OnboardingStep } from "./OnboardingStep";
import { PreviaDeMetas } from "./PreviaDeMetas";
import { SummaryList } from "./SummaryList";

/** S08 — confere tudo, mostra a prévia (RF08) e conclui o onboarding (RF07). */
export function EtapaResumo() {
  const catalogo = useCatalogo();
  const dados = useDadosOnboarding();
  const previa = usePrevia();
  const concluir = useConcluirOnboarding();
  const router = useRouter();
  const avisar = useToast();
  const [erro, setErro] = useState<ApiError | null>(null);

  const casca = {
    ...TEXTOS.resumo,
    numero: numeroDaEtapa("resumo"),
    total: TOTAL_ETAPAS,
    voltarPara: "/onboarding/rotina",
    rotuloBotao: "Gerar meu plano",
    rotuloSalvando: "Gerando…",
  };

  if (!catalogo.data || !dados.data) {
    const falhou = Boolean(catalogo.error || dados.error);
    return (
      <OnboardingStep
        {...casca}
        carregando={!falhou}
        erroAoCarregar={falhou ? { aoTentarDeNovo: () => { void catalogo.refetch(); void dados.refetch(); } } : null}
      />
    );
  }

  async function gerar() {
    setErro(null);
    try {
      await concluir.mutateAsync();
    } catch (e) {
      const falha = comoApiError(e);
      const etapa = falha.details.step;
      if (falha.code === "VALIDATION_ERROR" && typeof etapa === "string") {
        avisar({ texto: "Falta completar esta etapa." });
        router.push(`/onboarding/${etapa}?de=resumo`);
        return;
      }
      setErro(falha);
      return;
    }
    // Recarga: o `['me']` em cache ainda diz "onboarding incompleto" e o guarda voltaria para cá.
    recarregarEm("/onboarding/gerando");
  }

  return (
    <OnboardingStep
      {...casca}
      salvando={concluir.isPending || concluir.isSuccess}
      erroAoSalvar={erro}
      aoContinuar={() => void gerar()}
    >
      <SummaryList linhas={linhasDoResumo(dados.data.answers, catalogo.data)} />
      <PreviaDeMetas estado={previa.isPending ? "carregando" : (previa.data ?? "indisponivel")} />
    </OnboardingStep>
  );
}
```

`src/app/onboarding/resumo/page.tsx` (substituir inteiro):
```tsx
import { EtapaResumo } from "@/features/onboarding/components/EtapaResumo";

export default function Page() {
  return <EtapaResumo />;
}
```

`src/app/onboarding/layout.tsx` (substituir inteiro — as respostas agora vivem no servidor):
```tsx
import { Suspense } from "react";
import { TelaCarregando } from "@/components/app/TelaCarregando";
import { AuthGate } from "@/features/auth/components/AuthGate";

export default function OnboardingLayout({ children }: { children: React.ReactNode }) {
  return (
    <Suspense fallback={<TelaCarregando />}>
      <AuthGate area="onboarding">{children}</AuthGate>
    </Suspense>
  );
}
```

Em `src/app/onboarding/gerando/page.tsx` (ainda de protótipo até o Plano 04): remover o `import { useOnboarding } …` e a linha `const { respostas } = useOnboarding();`; trocar `generatePlan(respostas)` por `generatePlan({})` e as dependências do efeito `[respostas, router, tentativa]` por `[router, tentativa]`.

Em `src/app/onboarding/pronto/page.tsx`: trocar `import { useOnboarding } from "@/lib/onboarding-store";` por
```tsx
import { useMe } from "@/features/auth/hooks";
import { useDadosOnboarding } from "@/features/onboarding/hooks";
```
e as linhas `const { respostas } = useOnboarding();` / `const nome = respostas.name.trim();` por
```tsx
  const nome = useMe().data?.preferredName ?? "";
  const treino = useDadosOnboarding().data?.answers.trainingTime ?? "…";
```
e `o treino das {respostas.trainingTime}.` por `o treino das {treino}.`.

Apagar o que ficou sem uso e tirar do bloco legado do ESLint:
```bash
git rm src/lib/onboarding-store.tsx src/components/app/OnboardingStep.tsx
sed -i "/'src\/lib\/onboarding-store.tsx',/d" eslint.config.mjs
grep -rn "onboarding-store\|components/app/OnboardingStep" src eslint.config.mjs || echo "sem restos"
```
Expected: `sem restos`.

- [ ] **Step 3: Rodar e ver passar**

Run: `docker compose run --rm web bash -c "npx vitest run --project unit --project integration src/features/onboarding && npx vitest run --project storybook src/features/onboarding"`
Expected: PASS.

- [ ] **Step 4: Refinar com `frontend-design` (D11) e verificar**

Carregue `frontend-design:frontend-design` para o Resumo: a prévia é o momento de fechamento do onboarding (número de kcal contando com `CountUp`, bloco escuro entrando com escala), as linhas em cascata; alerta só na linha de restrições com alergia. Mesmas restrições.

Run: `docker compose run --rm web bash -c "npm run lint && npm run typecheck && npm test && npm run build"`
Expected: tudo verde, sem `localStorage` do onboarding (`grep -rn "prato-forte:onboarding" src` vazio).

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -m "feat(onboarding): resumo com prévia das metas e conclusão; respostas só no servidor

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---
### Task 11: Front — Perfil (S17) e Preferências e restrições (S18) lendo a API

**Files (repo front):**
- Create: `src/features/perfil/components/{GoalCard,PerfilTela,PreferenciasTela}.tsx`, `src/features/perfil/formato.ts`
- Modify: `src/components/ui/Chip.tsx` (acrescentar `ChipRemovivel`), `src/components/ui/Rail.tsx` (`ReguaPeso` sem divisão por zero), `src/app/(app)/perfil/page.tsx` e `src/app/(app)/perfil/preferencias/page.tsx` (substituir inteiros), `src/lib/labels.ts`
- Test: `src/features/perfil/formato.test.ts`, `src/features/perfil/components/GoalCard.stories.tsx`, `src/components/ui/Chip.stories.tsx`, `src/features/perfil/components/{PerfilTela,PreferenciasTela}.integration.test.tsx`

**Interfaces:**
- Consumes: `usePerfil`, `useSalvarPreferencias`, `useCatalogo` (Task 6); `perfilApi`, `catalogoApi` (MSW); `ErrorState`, `BottomNav`, `NutriBar`, `TopBar`, `Skeleton`, `OptionRow`, `EtiquetaAlergia`, `Chip`, `Field`, `Button`, `FormError`, `useToast` (existentes).
- Produces:
  - `formato.ts`: `iniciais(nome)`, `desdeQuando(createdAt)` ("agosto de 2026"), `metros(cm)` ("1,64 m").
  - `GoalCard({ objetivo, rotuloObjetivo, inicioKg, atualKg, metaKg, origemMeta, rotuloAtividade, academia, cidade })` — régua oculta em `mais-disposicao` (CA05) ou sem meta; "meta sugerida" quando `suggested`; "Trocar objetivo" → `/onboarding/objetivo?editar=1`.
  - `PerfilTela()`, `PreferenciasTela()`; `ChipRemovivel({ children: string; aoRemover })`.
  - `labels.ts` fica só com `DIAS_CURTOS`.

- [ ] **Step 1: Testes que devem falhar**

`src/features/perfil/formato.test.ts`:
```ts
import { describe, expect, it } from 'vitest';
import { desdeQuando, iniciais, metros } from './formato';

describe('formato do perfil', () => {
  it.each([
    ['Camila Réus', 'CR'],
    ['Rafael Lima Souza', 'RS'],
    ['Nina', 'N'],
    ['  ana  maria ', 'AM'],
  ])('iniciais de %j → %s', (nome, esperado) => {
    expect(iniciais(nome)).toBe(esperado);
  });

  it('diz desde quando a pessoa usa o app, no fuso de São Paulo (P1)', () => {
    expect(desdeQuando('2026-08-11T09:00:00-03:00')).toBe('agosto de 2026');
    expect(desdeQuando('2026-09-01T01:00:00+00:00')).toBe('agosto de 2026');
  });

  it('escreve a altura em metros', () => {
    expect(metros(164)).toBe('1,64 m');
  });
});
```

`src/features/perfil/components/GoalCard.stories.tsx`:
```tsx
import type { Meta, StoryObj } from '@storybook/nextjs-vite';
import { expect, within } from 'storybook/test';
import { GoalCard } from './GoalCard';

const meta = {
  title: 'Perfil/GoalCard',
  component: GoalCard,
  args: {
    objetivo: 'ganhar-massa',
    rotuloObjetivo: 'Ganhar massa magra',
    inicioKg: 56.8,
    atualKg: 58.4,
    metaKg: 62,
    origemMeta: 'user',
    rotuloAtividade: '3 ou 4 vezes na semana',
    academia: 'Zfit',
    cidade: 'Capivari de Baixo',
  },
} satisfies Meta<typeof GoalCard>;

export default meta;
type Story = StoryObj<typeof meta>;

export const ComMeta: Story = {
  play: async ({ canvasElement }) => {
    const tela = within(canvasElement);
    await expect(tela.getByText('58,4 kg hoje')).toBeInTheDocument();
    await expect(tela.getByText('meta 62,0 kg')).toBeInTheDocument();
    await expect(tela.getByRole('link', { name: 'Trocar objetivo' })).toHaveAttribute('href', '/onboarding/objetivo?editar=1');
  },
};

export const MetaSugerida: Story = {
  args: { origemMeta: 'suggested', metaKg: 61.5 },
  play: async ({ canvasElement }) => {
    await expect(within(canvasElement).getByText('meta sugerida 61,5 kg')).toBeInTheDocument();
  },
};

export const SemMeta: Story = {
  args: { objetivo: 'mais-disposicao', rotuloObjetivo: 'Ter mais disposição', metaKg: null, origemMeta: null },
  play: async ({ canvasElement }) => {
    const tela = within(canvasElement);
    await expect(tela.getByRole('heading', { name: 'Ter mais disposição' })).toBeInTheDocument();
    await expect(tela.queryByText(/hoje$/)).toBeNull();
  },
};

export const ManterPeso: Story = {
  args: { objetivo: 'manter-peso', rotuloObjetivo: 'Manter o peso', inicioKg: 70, atualKg: 70, metaKg: 70, origemMeta: 'auto' },
  play: async ({ canvasElement }) => {
    await expect(canvasElement.querySelectorAll('[style*="NaN"]')).toHaveLength(0);
  },
};
```

`src/components/ui/Chip.stories.tsx`:
```tsx
import type { Meta, StoryObj } from '@storybook/nextjs-vite';
import { expect, fn, userEvent, within } from 'storybook/test';
import { Chip, ChipRemovivel } from './Chip';

const meta = { title: 'UI/Chip', component: Chip, args: { marcado: false, onClick: fn(), children: 'Ovos' } } satisfies Meta<typeof Chip>;

export default meta;
type Story = StoryObj<typeof meta>;

export const Desmarcado: Story = {
  play: async ({ canvasElement, args }) => {
    const chip = within(canvasElement).getByRole('button', { name: 'Ovos' });
    await expect(chip).toHaveAttribute('aria-pressed', 'false');
    await userEvent.click(chip);
    await expect(args.onClick).toHaveBeenCalledOnce();
  },
};

export const Marcado: Story = { args: { marcado: true } };

const remover = fn();

export const Removivel: Story = {
  render: () => <ChipRemovivel aoRemover={remover}>camarão</ChipRemovivel>,
  play: async ({ canvasElement }) => {
    await userEvent.click(within(canvasElement).getByRole('button', { name: 'Remover camarão' }));
    await expect(remover).toHaveBeenCalledOnce();
  },
};
```

`src/features/perfil/components/PerfilTela.integration.test.tsx`:
```tsx
import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { http, HttpResponse } from 'msw';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { perfilApi } from '@/mocks/fixtures/onboarding';
import { url } from '@/mocks/handlers/auth';
import { server } from '@/mocks/server';
import { definirUrl, redefinirNavegacao } from '@/test/next-navigation';
import { renderizar } from '@/test/renderizar';
import { PerfilTela } from './PerfilTela';

vi.mock('next/navigation', () => import('@/test/next-navigation'));

const comPerfil = (troca: Partial<typeof perfilApi> = {}) =>
  server.use(http.get(url('/profile'), () => HttpResponse.json({ data: { ...perfilApi, ...troca } })));

beforeEach(() => {
  redefinirNavegacao();
  definirUrl('/perfil');
});

describe('Perfil (S17)', () => {
  it('mostra o perfil vindo da API, com os atalhos de edição', async () => {
    comPerfil();

    renderizar(<PerfilTela />);

    expect(await screen.findByRole('heading', { name: 'Camila Réus' })).toBeInTheDocument();
    expect(screen.getByText('CR')).toBeInTheDocument();
    expect(screen.getByText('No Prato Forte desde agosto de 2026')).toBeInTheDocument();
    expect(screen.getByText('meta 62,0 kg')).toBeInTheDocument();
    expect(screen.getByText('3 ou 4 vezes na semana, na Zfit de Capivari de Baixo')).toBeInTheDocument();

    const dados = screen.getByRole('link', { name: /Dados pessoais/ });
    expect(dados).toHaveAttribute('href', '/onboarding/dados?editar=1');
    expect(dados).toHaveTextContent('27 anos, 1,64 m, 58,4 kg');
    expect(screen.getByRole('link', { name: /Preferências alimentares/ })).toHaveTextContent('3 alimentos na sua cozinha');
    const restricoes = screen.getByRole('link', { name: /Restrições e alergias/ });
    expect(within(restricoes).getByText('Amendoim e castanhas')).toHaveClass('text-alerta');
    expect(screen.getByRole('link', { name: /Rotina e horários/ })).toHaveAttribute('href', '/onboarding/rotina?editar=1');
    expect(screen.getByRole('link', { name: /Rotina e horários/ })).toHaveTextContent('Treino às 19:00, seg, qua, sex');
  });

  it('sem restrições, diz "Nenhuma restrição"', async () => {
    comPerfil({ restrictions: [], other_restrictions: [] });

    renderizar(<PerfilTela />);

    expect(await screen.findByRole('link', { name: /Restrições e alergias/ })).toHaveTextContent('Nenhuma restrição');
  });

  it('quem quer mais disposição não vê régua de meta (CA05)', async () => {
    comPerfil({ goal: 'mais-disposicao', goal_weight_kg: null, goal_weight_source: null });

    renderizar(<PerfilTela />);

    expect(await screen.findByRole('heading', { name: 'Ter mais disposição' })).toBeInTheDocument();
    expect(screen.queryByText(/^meta/)).not.toBeInTheDocument();
  });

  it('se não carregar, mostra o erro e tenta de novo', async () => {
    let tentativas = 0;
    server.use(
      http.get(url('/profile'), () => {
        tentativas++;
        return tentativas === 1 ? HttpResponse.error() : HttpResponse.json({ data: perfilApi });
      }),
    );

    renderizar(<PerfilTela />);
    await userEvent.setup().click(await screen.findByRole('button', { name: 'Tentar de novo' }));

    expect(await screen.findByRole('heading', { name: 'Camila Réus' })).toBeInTheDocument();
  });
});
```

`src/features/perfil/components/PreferenciasTela.integration.test.tsx`:
```tsx
import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { http, HttpResponse } from 'msw';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { perfilApi } from '@/mocks/fixtures/onboarding';
import { url } from '@/mocks/handlers/auth';
import { server } from '@/mocks/server';
import { definirUrl, redefinirNavegacao, roteador } from '@/test/next-navigation';
import { renderizar } from '@/test/renderizar';
import { PreferenciasTela } from './PreferenciasTela';

vi.mock('next/navigation', () => import('@/test/next-navigation'));

let corpos: unknown[];

beforeEach(() => {
  redefinirNavegacao();
  definirUrl('/perfil/preferencias');
  corpos = [];
  server.use(
    http.get(url('/profile'), () => HttpResponse.json({ data: perfilApi })),
    http.put(url('/profile/preferences'), async ({ request }) => {
      corpos.push(await request.json());
      return HttpResponse.json({ data: perfilApi, meta: { plan_effect: 'none', plan_id: null } });
    }),
  );
});

describe('Preferências e restrições (S18)', () => {
  it('abre com o perfil e só libera "Salvar alterações" quando algo muda', async () => {
    const usuario = userEvent.setup();
    renderizar(<PreferenciasTela />);

    const salvar = await screen.findByRole('button', { name: 'Salvar alterações' });
    expect(salvar).toBeDisabled();
    expect(screen.getByRole('checkbox', { name: /Amendoim e castanhas/ })).toHaveAttribute('aria-checked', 'true');
    expect(screen.getByRole('button', { name: 'Fígado bovino' })).toHaveAttribute('aria-pressed', 'true');
    expect(screen.getByText('Restrições e alergias refazem seu plano na hora. O resto entra quando você refizer o plano.')).toBeInTheDocument();

    await usuario.click(screen.getByRole('button', { name: 'Maçã' }));
    expect(salvar).toBeEnabled();
    await usuario.click(screen.getByRole('button', { name: 'Maçã' }));
    expect(salvar).toBeDisabled();
  });

  it('salva as quatro listas inteiras e volta ao perfil (RF17)', async () => {
    const usuario = userEvent.setup();
    renderizar(<PreferenciasTela />);

    await usuario.click(await screen.findByRole('checkbox', { name: /Intolerância a lactose/ }));
    await usuario.click(screen.getByRole('button', { name: 'Maçã' }));
    await usuario.click(screen.getByRole('button', { name: 'Jiló' }));
    await usuario.click(screen.getByRole('button', { name: 'Remover camarão' }));
    await usuario.click(screen.getByRole('button', { name: 'Adicionar outro alimento' }));
    await usuario.type(screen.getByLabelText('Outro alimento'), 'pimenta{Enter}');
    expect(screen.getByRole('button', { name: 'Remover pimenta' })).toBeInTheDocument();
    await usuario.click(screen.getByRole('button', { name: 'Salvar alterações' }));

    await waitFor(() => expect(roteador.push).toHaveBeenCalledWith('/perfil'));
    expect(await screen.findByText('Salvo.')).toBeInTheDocument();
    expect(corpos).toEqual([
      {
        restrictions: ['lactose', 'castanhas'],
        other_restrictions: ['pimenta'],
        pantry_items: ['ovos', 'frango', 'arroz-e-feijao', 'maca'],
        disliked_food_ids: [88, 93],
      },
    ]);
  });

  it('não aceita outro alimento repetido', async () => {
    const usuario = userEvent.setup();
    renderizar(<PreferenciasTela />);

    await usuario.click(await screen.findByRole('button', { name: 'Adicionar outro alimento' }));
    await usuario.type(screen.getByLabelText('Outro alimento'), 'Camarão');
    await usuario.click(screen.getByRole('button', { name: 'Adicionar' }));

    expect(await screen.findByText('Esse item já está na lista.')).toBeInTheDocument();
  });

  it('sem rede, avisa e fica na tela', async () => {
    server.use(http.put(url('/profile/preferences'), () => HttpResponse.error()));
    const usuario = userEvent.setup();
    renderizar(<PreferenciasTela />);

    await usuario.click(await screen.findByRole('button', { name: 'Maçã' }));
    await usuario.click(screen.getByRole('button', { name: 'Salvar alterações' }));

    expect(await screen.findByRole('alert')).toHaveTextContent('Sem conexão. Confira a internet e tente de novo.');
    expect(roteador.push).not.toHaveBeenCalled();
  });
});
```

Run: `docker compose run --rm web bash -c "npx vitest run --project unit --project integration src/features/perfil; npx vitest run --project storybook src/features/perfil src/components/ui/Chip.stories.tsx"`
Expected: FAIL — módulos novos não resolvem; `ChipRemovivel` não existe.

- [ ] **Step 2: Implementar**

`src/features/perfil/formato.ts`:
```ts
/** "Camila Réus" → "CR" (primeira e última palavra). */
export function iniciais(nome: string): string {
  const partes = nome.trim().split(/\s+/).filter(Boolean);
  const primeira = partes[0]?.[0] ?? '';
  const ultima = partes.length > 1 ? partes[partes.length - 1][0] : '';
  return `${primeira}${ultima}`.toLocaleUpperCase('pt-BR');
}

/** Mês e ano do cadastro, no fuso da comunidade (P1: "No Prato Forte desde …"). */
export function desdeQuando(createdAt: string): string {
  return new Intl.DateTimeFormat('pt-BR', { month: 'long', year: 'numeric', timeZone: 'America/Sao_Paulo' }).format(new Date(createdAt));
}

export const metros = (cm: number) => `${(cm / 100).toFixed(2).replace('.', ',')} m`;
```

Em `src/components/ui/Rail.tsx`, na `ReguaPeso`, trocar a linha do `pos` por:
```tsx
  // Manter o peso: início = meta; a régua fica cheia em vez de dividir por zero.
  const distancia = meta - inicio;
  const pos = distancia === 0 ? 100 : Math.min(100, Math.max(0, ((atual - inicio) / distancia) * 100));
```

Em `src/components/ui/Chip.tsx`, acrescentar `import { IconeMais } from "@/components/icons";` no topo e, ao fim:
```tsx
/** Item digitado pela pessoa (ex.: "outras restrições"), com botão para tirar da lista. */
export function ChipRemovivel({
  children,
  aoRemover,
  className = "",
  style,
}: {
  children: string;
  aoRemover: () => void;
  className?: string;
  style?: React.CSSProperties;
}) {
  return (
    <span
      style={style}
      className={`inline-flex min-h-11 items-center gap-1 rounded-full border border-tinta bg-tinta pr-1.5 pl-4 text-sm font-medium text-white ${className}`}
    >
      {children}
      <button
        type="button"
        aria-label={`Remover ${children}`}
        onClick={aoRemover}
        className="flex size-8 items-center justify-center rounded-full transition-[background-color,transform] duration-200 hover:bg-white/15 active:scale-90"
      >
        <IconeMais size={16} className="rotate-45" />
      </button>
    </span>
  );
}
```

`src/features/perfil/components/GoalCard.tsx`:
```tsx
import Link from "next/link";
import { ReguaPeso } from "@/components/ui/Rail";
import type { OrigemDaMeta } from "@/features/onboarding/tipos";
import { peso } from "@/lib/format";
import type { Goal } from "@/lib/types";

/** Cartão do objetivo no Perfil (S17). Sem meta (disposição, CA05), a régua some. */
export function GoalCard({
  objetivo,
  rotuloObjetivo,
  inicioKg,
  atualKg,
  metaKg,
  origemMeta,
  rotuloAtividade,
  academia,
  cidade,
}: {
  objetivo: Goal;
  rotuloObjetivo: string;
  inicioKg: number;
  atualKg: number;
  metaKg: number | null;
  origemMeta: OrigemDaMeta | null;
  rotuloAtividade: string;
  academia: string;
  cidade: string;
}) {
  const comRegua = objetivo !== "mais-disposicao" && metaKg !== null;

  return (
    <section className="animate-escala rounded-[22px] bg-tinta p-[18px] text-neve" style={{ animationDelay: "180ms" }}>
      <p className="text-[12.5px] text-musgo">Seu objetivo</p>
      <h2 className="mt-1 font-display text-2xl font-bold tracking-[-0.025em]">{rotuloObjetivo}</h2>
      {comRegua ? (
        <>
          <div className="mt-3.5 flex">
            <ReguaPeso escuro inicio={inicioKg} atual={atualKg} meta={metaKg} />
          </div>
          <div className="mt-2 flex justify-between text-[12.5px]">
            <span className="text-salvia">{peso(atualKg)} hoje</span>
            <span className="text-musgo">
              {origemMeta === "suggested" ? "meta sugerida" : "meta"} {peso(metaKg)}
            </span>
          </div>
        </>
      ) : null}
      <Link
        href="/onboarding/objetivo?editar=1"
        className="mt-4 flex h-[42px] items-center justify-center rounded-full border-[1.5px] border-grafite text-sm font-semibold text-neve transition hover:bg-neve/10"
      >
        Trocar objetivo
      </Link>
      <p className="mt-3 text-[12.5px] text-musgo">
        {rotuloAtividade}, na {academia} de {cidade}
      </p>
    </section>
  );
}
```

`src/features/perfil/components/PerfilTela.tsx`:
```tsx
"use client";

import Link from "next/link";
import { BottomNav } from "@/components/app/BottomNav";
import { ErrorState } from "@/components/app/ErrorState";
import { NutriBar } from "@/components/app/NutriBar";
import { Screen } from "@/components/app/Screen";
import { IconeAjustes, IconeAvancar } from "@/components/icons";
import { Skeleton } from "@/components/ui/Skeleton";
import { useCatalogo } from "@/features/onboarding/hooks";
import { peso } from "@/lib/format";
import { DIAS_CURTOS } from "@/lib/labels";
import { cascata } from "@/lib/motion";
import { desdeQuando, iniciais, metros } from "../formato";
import { usePerfil } from "../hooks";
import type { Perfil } from "../tipos";
import type { Catalogo } from "@/features/onboarding/tipos";
import { GoalCard } from "./GoalCard";

/** S17 — Perfil vindo de `GET /profile`; os rótulos vêm do catálogo. */
export function PerfilTela() {
  const perfil = usePerfil();
  const catalogo = useCatalogo();

  if (perfil.error || catalogo.error) {
    return (
      <Screen>
        <main className="flex-1 pt-6">
          <ErrorState
            titulo="Não foi possível carregar seu perfil"
            descricao="Confira a internet e tente de novo."
            aoTentarDeNovo={() => {
              void perfil.refetch();
              void catalogo.refetch();
            }}
          />
        </main>
        <BottomNav />
      </Screen>
    );
  }

  if (!perfil.data || !catalogo.data) {
    return (
      <Screen>
        <main className="flex-1 px-5 pt-6" aria-busy="true" aria-label="Carregando seu perfil">
          <Skeleton className="h-16" />
          <Skeleton className="mt-4 h-[200px] rounded-3xl" atraso={90} />
          <Skeleton className="mt-4 h-[320px] rounded-3xl" atraso={180} />
        </main>
        <BottomNav />
      </Screen>
    );
  }

  return <Conteudo perfil={perfil.data} catalogo={catalogo.data} />;
}

function Conteudo({ perfil, catalogo }: { perfil: Perfil; catalogo: Catalogo }) {
  const rotuloObjetivo = catalogo.goals.find((g) => g.value === perfil.goal)?.label ?? "";
  const rotuloAtividade = catalogo.activityLevels.find((a) => a.value === perfil.activityLevel)?.label ?? "";
  const alergia = perfil.restrictions.find((r) => r.isAllergy);
  const restricao = alergia?.label ?? perfil.restrictions[0]?.label ?? perfil.otherRestrictions[0] ?? "Nenhuma restrição";
  const dias = perfil.trainingDays.map((d) => DIAS_CURTOS[d]).join(", ") || "sem dias marcados";
  const cozinha = perfil.pantryItems.length;

  const itens = [
    { href: "/onboarding/dados?editar=1", titulo: "Dados pessoais", valor: `${perfil.age} anos, ${metros(perfil.heightCm)}, ${peso(perfil.currentWeightKg)}` },
    { href: "/perfil/preferencias", titulo: "Preferências alimentares", valor: `${cozinha} ${cozinha === 1 ? "alimento" : "alimentos"} na sua cozinha` },
    { href: "/perfil/preferencias", titulo: "Restrições e alergias", valor: restricao, alerta: Boolean(alergia) },
    { href: "/onboarding/rotina?editar=1", titulo: "Rotina e horários", valor: `Treino às ${perfil.trainingTime}, ${dias}` },
    // Resumo das notificações ligadas: Plano 07 (GET /settings).
    { href: "/perfil/configuracoes", titulo: "Notificações e conta", valor: "Avisos, medidas e conta" },
  ];

  return (
    <Screen>
      <header className="flex shrink-0 items-center gap-3.5 px-5 pt-[22px] pb-3.5 area-segura-cima">
        <span
          aria-hidden="true"
          className="flex size-[58px] shrink-0 animate-pop items-center justify-center rounded-full bg-tinta text-[19px] font-semibold text-neve"
        >
          {iniciais(perfil.name)}
        </span>
        <div className="flex-1 animate-entra" style={{ animationDelay: "90ms" }}>
          <h1 className="font-display text-[22px] font-bold tracking-[-0.02em]">{perfil.name}</h1>
          <p className="mt-0.5 text-[13px] text-fumo">No Prato Forte desde {desdeQuando(perfil.createdAt)}</p>
        </div>
        <Link
          href="/perfil/configuracoes"
          aria-label="Abrir configurações"
          className="group flex size-[42px] shrink-0 animate-entra items-center justify-center rounded-full border border-linha bg-white transition-[border-color] duration-250 hover:border-pedra"
          style={{ animationDelay: "160ms" }}
        >
          <IconeAjustes size={20} className="transition-transform duration-700 ease-[cubic-bezier(.22,1,.36,1)] group-hover:rotate-90" />
        </Link>
      </header>

      <main className="flex-1 px-5">
        <GoalCard
          objetivo={perfil.goal}
          rotuloObjetivo={rotuloObjetivo}
          inicioKg={perfil.startWeightKg}
          atualKg={perfil.currentWeightKg}
          metaKg={perfil.goalWeightKg}
          origemMeta={perfil.goalWeightSource}
          rotuloAtividade={rotuloAtividade}
          academia={perfil.gym}
          cidade={perfil.city}
        />

        <nav aria-label="Seu perfil" className="mt-4 rounded-[20px] bg-white px-[18px]">
          {itens.map((item, i) => (
            <Link
              key={item.titulo}
              href={item.href}
              style={cascata(i, 60, 320)}
              className={`group flex min-h-15 animate-entra items-center gap-3.5 py-3 transition-colors duration-200 hover:text-mata ${
                i < itens.length - 1 ? "border-b border-fio" : ""
              }`}
            >
              <span className="flex-1">
                <span className="block text-[15px] font-semibold">{item.titulo}</span>
                <span className={`mt-0.5 block text-[13px] ${item.alerta ? "text-alerta" : "text-fumo"}`}>{item.valor}</span>
              </span>
              <IconeAvancar
                size={18}
                className="shrink-0 text-fumo transition-[color,transform] duration-250 group-hover:translate-x-1 group-hover:text-tinta"
              />
            </Link>
          ))}
        </nav>

        {/* "Refazer meu plano" de verdade chega com o plano alimentar (Plano 04). */}
        <NutriBar className="mt-3.5 animate-entra" style={{ animationDelay: "640ms" }} texto="Refazer meu plano com o Nutri" />
      </main>

      <div className="h-4 shrink-0" />
      <BottomNav />
    </Screen>
  );
}
```

`src/features/perfil/components/PreferenciasTela.tsx`:
```tsx
"use client";

import { useRouter } from "next/navigation";
import { useState } from "react";
import { ErrorState } from "@/components/app/ErrorState";
import { Screen } from "@/components/app/Screen";
import { TopBar } from "@/components/app/TopBar";
import { IconeMais } from "@/components/icons";
import { Button } from "@/components/ui/Button";
import { Chip, ChipRemovivel } from "@/components/ui/Chip";
import { Field } from "@/components/ui/Field";
import { FormError } from "@/components/ui/FormError";
import { EtiquetaAlergia, OptionRow } from "@/components/ui/OptionRow";
import { Skeleton } from "@/components/ui/Skeleton";
import { useToast } from "@/components/ui/Toaster";
import { useCatalogo } from "@/features/onboarding/hooks";
import { MENSAGENS } from "@/features/onboarding/regras";
import type { Catalogo } from "@/features/onboarding/tipos";
import { type ApiError, comoApiError } from "@/lib/api/errors";
import { cascata } from "@/lib/motion";
import { usePerfil, useSalvarPreferencias } from "../hooks";
import type { EntradaPreferencias, Perfil } from "../tipos";

/** S18 — restrições, cozinha e "prefiro não ver", salvos juntos (RF17). */
export function PreferenciasTela() {
  const perfil = usePerfil();
  const catalogo = useCatalogo();

  return (
    <Screen>
      <TopBar voltarPara="/perfil" rotuloVoltar="Voltar para o perfil" />
      {perfil.error || catalogo.error ? (
        <main className="flex-1">
          <ErrorState
            titulo="Não foi possível carregar suas preferências"
            descricao="Confira a internet e tente de novo."
            aoTentarDeNovo={() => {
              void perfil.refetch();
              void catalogo.refetch();
            }}
          />
        </main>
      ) : !perfil.data || !catalogo.data ? (
        <main className="flex-1 px-5 pt-2" aria-busy="true" aria-label="Carregando suas preferências">
          <Skeleton className="h-16" />
          <Skeleton className="mt-6 h-[240px] rounded-3xl" atraso={90} />
        </main>
      ) : (
        <FormPreferencias perfil={perfil.data} catalogo={catalogo.data} />
      )}
    </Screen>
  );
}

const iguais = (a: (string | number)[], b: (string | number)[]) =>
  a.length === b.length && [...a].sort().join("|") === [...b].sort().join("|");

function FormPreferencias({ perfil, catalogo }: { perfil: Perfil; catalogo: Catalogo }) {
  const router = useRouter();
  const avisar = useToast();
  const salvar = useSalvarPreferencias();
  const [inicial] = useState<EntradaPreferencias>(() => ({
    restrictions: perfil.restrictions.map((r) => r.slug),
    otherRestrictions: perfil.otherRestrictions,
    pantryItems: perfil.pantryItems.map((i) => i.slug),
    dislikedFoodIds: perfil.dislikedFoods.map((f) => f.id),
  }));
  const [escolhas, setEscolhas] = useState<EntradaPreferencias>(inicial);
  const [adicionando, setAdicionando] = useState(false);
  const [novo, setNovo] = useState("");
  const [erroNovo, setErroNovo] = useState<string>();
  const [erro, setErro] = useState<ApiError | null>(null);

  const mudou = (Object.keys(inicial) as (keyof EntradaPreferencias)[]).some((campo) => !iguais(inicial[campo], escolhas[campo]));

  function alternar<C extends "restrictions" | "pantryItems">(campo: C, slug: string) {
    setEscolhas((atual) => ({
      ...atual,
      [campo]: atual[campo].includes(slug) ? atual[campo].filter((s) => s !== slug) : [...atual[campo], slug],
    }));
  }

  function alternarNaoCurte(id: number) {
    setEscolhas((atual) => ({
      ...atual,
      dislikedFoodIds: atual.dislikedFoodIds.includes(id) ? atual.dislikedFoodIds.filter((x) => x !== id) : [...atual.dislikedFoodIds, id],
    }));
  }

  function adicionarOutro() {
    const item = novo.trim();
    const repetido = escolhas.otherRestrictions.some((o) => o.toLocaleLowerCase("pt-BR") === item.toLocaleLowerCase("pt-BR"));
    const problema =
      [...item].length < 2 || [...item].length > 60
        ? MENSAGENS.outraTamanho
        : repetido
          ? "Esse item já está na lista."
          : escolhas.otherRestrictions.length >= 10
            ? MENSAGENS.outrasMuitas
            : undefined;
    setErroNovo(problema);
    if (problema) return;
    setEscolhas((atual) => ({ ...atual, otherRestrictions: [...atual.otherRestrictions, item] }));
    setNovo("");
    setAdicionando(false);
  }

  async function enviar() {
    setErro(null);
    try {
      await salvar.mutateAsync({
        restrictions: catalogo.restrictions.map((r) => r.slug).filter((s) => escolhas.restrictions.includes(s)),
        otherRestrictions: escolhas.otherRestrictions,
        pantryItems: catalogo.pantry.flatMap((g) => g.items.map((i) => i.slug)).filter((s) => escolhas.pantryItems.includes(s)),
        dislikedFoodIds: catalogo.dislikeOptions.map((d) => d.id).filter((id) => escolhas.dislikedFoodIds.includes(id)),
      });
    } catch (e) {
      setErro(comoApiError(e));
      return;
    }
    // Com o plano alimentar (Plano 04), restrição nova refaz o plano (RN21); até lá, só salva.
    avisar({ texto: "Salvo." });
    router.push("/perfil");
  }

  return (
    <>
      <main className="flex-1 px-5 pt-1.5">
        <h1 className="animate-entra font-display text-[28px] leading-tight font-bold tracking-[-0.028em]">Preferências e restrições</h1>
        <p className="mt-2 animate-entra text-sm leading-normal text-fumo" style={{ animationDelay: "80ms" }}>
          Restrições e alergias refazem seu plano na hora. O resto entra quando você refizer o plano.
        </p>

        <h2 className="mt-[22px] text-[12.5px] font-semibold text-fumo">O que você não pode comer</h2>
        <div className="mt-2.5 flex flex-col gap-2">
          {catalogo.restrictions.map((r, i) => (
            <OptionRow
              key={r.slug}
              className="animate-entra"
              style={cascata(i, 45, 140)}
              quadrado
              compacto
              marcado={escolhas.restrictions.includes(r.slug)}
              onClick={() => alternar("restrictions", r.slug)}
              titulo={r.label}
              etiqueta={r.isAllergy ? <EtiquetaAlergia /> : undefined}
            />
          ))}
        </div>
        {escolhas.otherRestrictions.length > 0 ? (
          <div className="mt-3 flex flex-wrap gap-2">
            {escolhas.otherRestrictions.map((item) => (
              <ChipRemovivel
                key={item}
                className="animate-escala"
                aoRemover={() => setEscolhas((atual) => ({ ...atual, otherRestrictions: atual.otherRestrictions.filter((o) => o !== item) }))}
              >
                {item}
              </ChipRemovivel>
            ))}
          </div>
        ) : null}
        {adicionando ? (
          <div className="mt-3 flex animate-entra items-start gap-2">
            <Field
              id="novo-alimento"
              label="Outro alimento"
              className="flex-1"
              autoFocus
              value={novo}
              placeholder="Ex.: pimenta"
              onChange={(e) => {
                setNovo(e.target.value);
                setErroNovo(undefined);
              }}
              onKeyDown={(e) => {
                if (e.key === "Enter") {
                  e.preventDefault();
                  adicionarOutro();
                }
              }}
              erro={erroNovo}
            />
            <Button tamanho="media" variante="contorno" className="mt-[26px] h-[52px]" onClick={adicionarOutro}>
              Adicionar
            </Button>
          </div>
        ) : (
          <button
            type="button"
            onClick={() => setAdicionando(true)}
            className="mt-2 flex min-h-13 w-full items-center gap-2.5 rounded-2xl border-[1.5px] border-dashed border-pedra px-4 text-sm font-semibold text-fumo transition hover:border-tinta hover:text-tinta"
          >
            <IconeMais size={18} />
            Adicionar outro alimento
          </button>
        )}

        <h2 className="mt-6 text-[12.5px] font-semibold text-fumo">O que costuma ter na sua cozinha</h2>
        <div className="mt-2.5 flex flex-wrap gap-2">
          {catalogo.pantry.flatMap((g) => g.items).map((item) => (
            <Chip key={item.slug} marcado={escolhas.pantryItems.includes(item.slug)} onClick={() => alternar("pantryItems", item.slug)}>
              {item.label}
            </Chip>
          ))}
        </div>

        <h2 className="mt-6 text-[12.5px] font-semibold text-fumo">O que você prefere não ver no cardápio</h2>
        <div className="mt-2.5 flex flex-wrap gap-2">
          {catalogo.dislikeOptions.map((opcao) => (
            <Chip key={opcao.id} marcado={escolhas.dislikedFoodIds.includes(opcao.id)} onClick={() => alternarNaoCurte(opcao.id)}>
              {opcao.name}
            </Chip>
          ))}
        </div>

        <p className="mt-5 text-[12.5px] leading-normal text-fumo">Alergias nunca aparecem, nem em substituições.</p>
      </main>

      <footer className="shrink-0 px-5 pt-3.5 pb-7 area-segura-baixo">
        <FormError erro={erro} />
        <Button carregando={salvar.isPending} rotuloCarregando="Salvando…" disabled={!mudou} onClick={() => void enviar()}>
          Salvar alterações
        </Button>
      </footer>
    </>
  );
}
```

`src/app/(app)/perfil/page.tsx` (substituir inteiro):
```tsx
import { PerfilTela } from "@/features/perfil/components/PerfilTela";

export default function Page() {
  return <PerfilTela />;
}
```

`src/app/(app)/perfil/preferencias/page.tsx` (substituir inteiro):
```tsx
import { PreferenciasTela } from "@/features/perfil/components/PreferenciasTela";

export default function Page() {
  return <PreferenciasTela />;
}
```

`src/lib/labels.ts` (substituir inteiro — os rótulos de domínio agora vêm do catálogo):
```ts
/** Dias da semana abreviados, 0 = domingo (RN12). */
export const DIAS_CURTOS = ["dom", "seg", "ter", "qua", "qui", "sex", "sáb"];
```

- [ ] **Step 3: Rodar e ver passar**

Run: `docker compose run --rm web bash -c "npx vitest run --project unit --project integration src/features/perfil && npx vitest run --project storybook src/features/perfil src/components/ui"`
Expected: PASS.

- [ ] **Step 4: Refinar com `frontend-design` (D11) e verificar**

Carregue `frontend-design:frontend-design` para Perfil e Preferências: a régua de peso animando do início até o atual, iniciais com `animate-pop`, a lista em cascata, o chip removível saindo com escala, o campo "Outro alimento" entrando. Mesmas restrições (textos, `role`s, rótulos e props intocados; só tokens; `musgo` só no escuro).

Run: `docker compose run --rm web bash -c "npm run lint && npm run typecheck && npm test && npm run build"`
Expected: tudo verde.

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -m "feat(perfil): Perfil e Preferências lendo e salvando na API

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---
### Task 12: E2E-01 (até "Gerando") e E2E-03, seeder e specs atualizadas

**Files:**
- Repo backend: Modify `database/seeders/E2ESeeder.php`, `tests/Feature/Seeders/E2ESeederTest.php`, `specs/02-onboarding-perfil/spec.md`, `specs/00-fundacao/modelo-de-dados.md`, `specs/99-inconsistencias.md`
- Repo front: Modify `e2e/cadastro.spec.ts`; Create `e2e/retomar.spec.ts`

**Interfaces:**
- Consumes: tudo das Tasks 1–11.
- Produces: `E2ESeeder` semeia o catálogo e deixa `novo@e2e.pratoforte.test` com objetivo e dados respondidos (Nina, 30 anos, 170 cm, 70 kg, perder gordura).

- [ ] **Step 1 (backend): seeder — teste que deve falhar**

Em `tests/Feature/Seeders/E2ESeederTest.php`, acrescentar:
```php
it('semeia o catálogo e deixa a conta "novo" com objetivo e dados respondidos (E2E-03)', function () {
    $this->seed(E2ESeeder::class);

    $novo = User::where('email', 'novo@e2e.pratoforte.test')->sole()->profile;

    expect(\App\Models\Restriction::count())->toBe(6)
        ->and(\App\Models\Food::count())->toBeGreaterThan(50)
        ->and([$novo->goal, $novo->preferred_name, $novo->age, $novo->height_cm, (float) $novo->start_weight_kg, $novo->sex])
        ->toBe(['perder-gordura', 'Nina', 30, 170, 70.0, 'feminino']);
});
```
Run: `docker compose run --rm api php artisan test tests/Feature/Seeders/E2ESeederTest.php`
Expected: FAIL — `Restriction::count()` é 0.

- [ ] **Step 2 (backend): implementar e commitar**

Em `database/seeders/E2ESeeder.php`, no começo de `run()`:
```php
        $this->call(CatalogSeeder::class);
```
e trocar a criação da conta `novo@` por:
```php
        $novo = User::factory()->withCompletedSteps(['objetivo', 'dados'])
            ->create(['name' => 'Nina Souza', 'email' => 'novo@e2e.pratoforte.test']);
        $novo->profile->update([
            'goal' => 'perder-gordura', 'preferred_name' => 'Nina', 'age' => 30, 'height_cm' => 170, 'start_weight_kg' => 70.0, 'sex' => 'feminino',
        ]);
```

Run: `docker compose run --rm api php artisan test && docker compose run --rm --no-deps api composer lint`
Expected: tudo verde.

```bash
git add -A && git commit -m "test(e2e): E2ESeeder com catálogo e retomada do onboarding

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

- [ ] **Step 3 (front): E2E**

`e2e/cadastro.spec.ts` (substituir inteiro — um cadastro por navegador por execução, por causa do limite de 3 cadastros/min por IP):
```ts
import { expect, test } from '@playwright/test';

test('das Boas-vindas ao plano sendo gerado (CA01, E2E-01 até "Gerando")', async ({ page }, info) => {
  const email = `cadastro-${info.project.name}-${Date.now()}@e2e.pratoforte.test`;
  const continuar = () => page.getByRole('button', { name: 'Continuar' }).click();

  await page.goto('/');
  await page.getByRole('link', { name: 'Montar meu plano' }).click();
  await expect(page).toHaveURL(/\/cadastro$/);
  await page.getByLabel('Nome completo').fill('Teste de Ponta');
  await page.getByLabel('E-mail').fill(email);
  await page.getByLabel('Senha', { exact: true }).fill('senha1234');
  await page.getByRole('checkbox', { name: /Li e aceito/ }).click();
  await page.getByRole('button', { name: 'Criar conta' }).click();

  await expect(page).toHaveURL(/\/onboarding\/objetivo$/);
  await page.getByRole('radio', { name: /Ganhar massa magra/ }).click();
  await continuar();

  await expect(page).toHaveURL(/\/onboarding\/dados$/);
  await page.getByLabel('Idade').fill('27');
  await page.getByLabel('Altura').fill('164');
  await page.getByLabel('Peso de hoje').fill('58,4');
  await page.getByRole('radio', { name: 'Feminino' }).click();
  await continuar();

  await expect(page).toHaveURL(/\/onboarding\/atividade$/);
  await page.getByRole('radio', { name: /3 ou 4 vezes na semana/ }).click();
  await page.getByRole('radio', { name: 'Sentada' }).click();
  await continuar();

  await expect(page).toHaveURL(/\/onboarding\/preferencias$/);
  for (const item of ['Ovos', 'Frango', 'Arroz e feijão', 'Banana', 'Aveia']) {
    await page.getByRole('button', { name: item, exact: true }).click();
  }
  await continuar();

  await expect(page).toHaveURL(/\/onboarding\/restricoes$/);
  await page.getByRole('checkbox', { name: /Amendoim e castanhas/ }).click();
  await continuar();

  await expect(page).toHaveURL(/\/onboarding\/rotina$/);
  for (const dia of ['segunda', 'quarta', 'sexta']) await page.getByRole('button', { name: dia, exact: true }).click();
  await page.getByRole('button', { name: 'Marmita no trabalho' }).click();
  await continuar();

  // Prévia calculada pelo backend (RN13): 27 anos, 164 cm, 58,4 kg, ganhar massa, 3–4 treinos, trabalho sentado.
  await expect(page).toHaveURL(/\/onboarding\/resumo$/);
  await expect(page.getByText(/Com isso, seu plano começa em/)).toContainText('2.250 kcal por dia, com 115 g de proteína');
  await expect(page.getByText('Amendoim e castanhas', { exact: true })).toBeVisible();
  await page.getByRole('button', { name: 'Gerar meu plano' }).click();

  await expect(page).toHaveURL(/\/onboarding\/gerando$/);
});
```

`e2e/retomar.spec.ts`:
```ts
import { expect, test } from '@playwright/test';
import { entrar, NOVO } from './contas';

test('retoma o onboarding de onde parou, com as respostas salvas (E2E-03, CA01)', async ({ page }) => {
  await entrar(page, NOVO);
  await expect(page).toHaveURL(/\/onboarding\/atividade$/);

  await page.getByRole('link', { name: 'Voltar' }).click();

  await expect(page).toHaveURL(/\/onboarding\/dados$/);
  await expect(page.getByLabel('Como podemos te chamar')).toHaveValue('Nina');
  await expect(page.getByLabel('Idade')).toHaveValue('30');
  await expect(page.getByLabel('Altura')).toHaveValue('170');
  await expect(page.getByLabel('Peso de hoje')).toHaveValue('70');
});
```

- [ ] **Step 4: Rodar os E2E contra o backend real**

```bash
cd /home/alvez/atividade-extensionista/backend
docker compose up -d
docker compose exec api php artisan migrate:fresh --seeder=E2ESeeder --force
docker compose exec api php artisan cache:clear
cd /home/alvez/atividade-extensionista/frontend
docker compose run --rm web npm run e2e
```
Expected: **14 passed** (7 testes × 2 navegadores: 4 de conta, cadastro+onboarding, senha, retomada). Logins por minuto por e-mail ficam ≤ 5: `novo@` é usado 2× por navegador.

- [ ] **Step 5: Specs (repo backend)**

- `specs/02-onboarding-perfil/spec.md`:
  - §4 S03, fim do item "Unidade imperial": acrescentar ` — **entra no Plano 07**, junto com `GET/PATCH /settings` (até lá ninguém escolhe imperial).`
  - §4 S17, no item "Exibe": trocar `subtítulo (ver pendência P1: hoje "Treina na Zfit desde agosto")` por `subtítulo "No Prato Forte desde {mês de ano}" (P1, opção a)`; e acrescentar ao item "Endpoints": ` Até o Plano 07, a linha "Notificações e conta" mostra "Avisos, medidas e conta"; "Avaliar o app" entra no Plano 08.`
  - §5 `PATCH /profile/steps/{step}`, depois da linha **Response 200**: `- **Avisos (`meta.warnings`):** `GOAL_WEIGHT_OUT_OF_HEALTHY_RANGE` (RN10) e `GOAL_WEIGHT_RESET` (RN11). `plan_effect` é sempre `none` até o plano alimentar existir (Plano 04).`
  - §5 `POST /onboarding/complete`, depois de **Response 202**: `- **Até o Plano 04:** `{ "data": { "plan": null } }` (sem plano nem job); 200 com o mesmo corpo se já concluído.`
  - §5 `GET /profile`: trocar `config('app.parceiro')` por `config('prato.parceiro')`.
  - §9: marcar `[x]` em "Endpoints catalog, onboarding, steps, complete, profile, preferences" (com ` — complete sem plano até o Plano 04`), "GoalWeightResolver + NutritionCalculator…", "Feature tests: cada etapa, cada regra do §6" (acrescentar ` — as linhas de RN21 entram no Plano 04`), "Telas do onboarding ligadas à API…", "Listas de opções vindas do catálogo…", "Campo de meta de peso e avisos com stories", "axe limpo; progresso anunciado; foco no título…"; em "E2E-01, E2E-03, E2E-11 verdes" acrescentar ` — E2E-01 até "Gerando" e E2E-03 no Plano 03; E2E-11 e o resto do E2E-01 no Plano 04`; em "CA01–CA10" acrescentar ` — CA07, CA08 (toast "Refazer") e CA09 dependem do plano (Plano 04)`.
- `specs/00-fundacao/modelo-de-dados.md` §12, linha do `FoodSeeder`: trocar `≈ 120 alimentos` por `≈ 60 alimentos no Plano 03, ampliado quando precisar` e acrescentar ` Fonte por linha: `TACO 4ª ed.`, `Rótulo`, `Tabela USDA` ou `Receita caseira (TACO)` — conferência pendente (P5).`
- `specs/99-inconsistencias.md`, seção D, depois de P4:
```markdown
### P5 — Valores nutricionais do catálogo · **Pendente (não bloqueia o MVP)**
- **Problema:** `database/data/foods.csv` (Plano 03) foi montado com valores por 100 g da TACO 4ª ed. quando o alimento existe nela, de rótulo ou da tabela USDA quando não existe, e de receita caseira para preparos (ovos mexidos, salada). Ninguém da nutrição conferiu.
- **Impacto:** as metas (RN13) não mudam; as porções e os totais do cardápio (Plano 04) herdam qualquer erro da tabela.
- **Recomendação técnica:** antes da rodada de validação, conferir a planilha com a TACO (e, se possível, com o(a) nutricionista de P4); a coluna `source` diz de onde veio cada linha.
```

```bash
cd /home/alvez/atividade-extensionista/backend
git add specs && git commit -m "docs(specs): onboarding e perfil no Plano 03 — o que espera o plano alimentar, P5

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
cd /home/alvez/atividade-extensionista/frontend
git add -A && git commit -m "test(e2e): onboarding completo até Gerando e retomada (E2E-01, E2E-03)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

- [ ] **Step 6: Verificação final**

Run: `docker compose run --rm web bash -c "npm run lint && npm run typecheck && npm test && npm audit --audit-level=high"` (front) e `docker compose run --rm api php artisan test && docker compose run --rm --no-deps api composer lint` (backend)
Expected: tudo verde.
