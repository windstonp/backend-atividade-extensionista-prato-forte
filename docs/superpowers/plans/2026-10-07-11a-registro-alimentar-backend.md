# Plano 11A — Registro alimentar (backend) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A API passa a registrar o que o usuário comeu (g/ml, catálogo ou alimento próprio), trata o plano como sugestão, calcula a meta da refeição (RN48) e usa os registros como "consumido" em todo o app.

**Architecture:** Duas tabelas novas (`meal_entries` com retrato dos números, `custom_foods` com exclusão lógica) e duas colunas em `foods` (`measure`, `in_plans`). `EntryService` é a única porta de escrita dos registros e mantém `day_meals.done_at` (= horário do 1º registro, RN46). O formato do dia continua compatível: `items` = sugestão, `calories`/`macros` = meta, `done` = tem registro; entram `entries`, `consumed`, `status`, `goal_met`. Todo "consumido" passa por `DayMeal::consumed()`.

**Tech Stack:** Laravel 12, PHP 8.3, MySQL 8, Pest, Larastan 6, Pint. Tudo roda com `docker compose run --rm api …` a partir de `backend/`.

**Spec:** `specs/09-registro-alimentar/spec.md` (RF31–RF37, CA31–CA45), `specs/00-fundacao/regras-de-negocio.md` (RN16, RN17, RN22–RN27, RN29, RN36, RN46–RN52), `specs/00-fundacao/modelo-de-dados.md` (`foods`, `day_meals`, `meal_entries`, `custom_foods`).

**Onde rodar:** backend, branch `registro-alimentar` (já criada a partir de `ajuste-cardapio-ia`; tem só as mudanças de spec). O catálogo ≥ 700 é o Plano 11B; as telas, o 11C.

## Global Constraints

- Mensagens de erro e textos em português, como os de `ErrorCode::message()`.
- Erros de domínio via `App\Exceptions\DomainException(ErrorCode::…)`; 404 de posse via `NotFoundHttpException` (RN43: nunca 403 para recurso de outro usuário).
- Escrita de registro: **hoje e ontem** (fuso `America/Sao_Paulo`). Trocar, aplicar ação do Nutri e desfazer: **só hoje** (RN23).
- Registro guarda retrato: `name`, `measure`, `amount`, `calories` (inteiro), `protein`/`carbs`/`fat` (1 casa) — mesmo arredondamento de `DayTotals` (RN49).
- `amount`: > 0 e ≤ 2000, até 1 casa. `entries` por requisição: 1–10.
- Alimento próprio: nome 2–60, único por usuário entre os não apagados (sem maiúsculas/acentos); `measure` ∈ {g, ml}; kcal 0–900; macros 0–100, soma ≤ 100; coerência `4p + 4c + 9g ≤ kcal × 1,25 + 20` ("Os números não batem: confira as calorias."). Sem limite de quantidade. Nunca entra em `FoodFilter`.
- Meta batida = calorias ≥ 90% **e** proteína ≥ 90% da meta da refeição; passar das calorias ou da gordura não desfaz (RN48).
- `FoodFilter` só considera `foods.in_plans = true` (RN52); busca considera todo o catálogo ativo.
- Nada de segredo em código; nada de `backend (legado node)/`.
- Comandos: `docker compose run --rm api php artisan test --compact <filtro>`, `docker compose run --rm api ./vendor/bin/pint --dirty`, `docker compose run --rm api ./vendor/bin/phpstan analyse --memory-limit=1G`.

## Review Focus

1. **Registro ligado à sugestão depois de trocar ou desfazer** — trocar um item já registrado deve dar 409 `SUGGESTION_ALREADY_REGISTERED`; desfazer outra troca da mesma refeição não pode apagar a ligação (`suggestion_item_id`) dos itens registrados. Teste em Task 6.
2. **Ontem não materializado** — abrir ontem sem nada gravado mostra a prévia editável; a primeira escrita grava ontem uma única vez (duas abas ao mesmo tempo não duplicam). Teste em Task 4.
3. **Virada da meia-noite** — um registro enviado para a data de anteontem (a tela de ontem ficou aberta) recebe `DAY_NOT_EDITABLE`, nunca grava em outra data. Teste em Task 4.
4. **Alimento próprio apagado ou editado** — registros antigos mantêm nome e números; registrar com alimento apagado dá 404. Teste em Task 8.
5. **Posse** — registro, alimento próprio e item sugerido de outro usuário sempre 404, inclusive misturados num lote. Teste em Task 4 e Task 8.

---

### Task 1: Esquema e modelos

**Files:**
- Create: `database/migrations/2026_10_07_000100_create_registro_alimentar.php`
- Create: `app/Models/CustomFood.php`, `app/Models/MealEntry.php`
- Modify: `app/Models/Food.php` (fillable/casts), `app/Models/DayMeal.php` (relação `entries`, `consumed()`, `target()`), `app/Models/User.php` (relação `customFoods`)
- Test: `tests/Feature/Models/RegistroAlimentarModelsTest.php`

**Interfaces:**
- Produces: `DayMeal::entries(): HasMany<MealEntry>` (ordem `position`); `DayMeal::consumed(): array{calories:int,protein:float,carbs:float,fat:float}` (soma dos retratos; usa a relação carregada); `DayMeal::target(): array` (soma de `DayTotals::item` dos `items`); `User::customFoods(): HasMany<CustomFood>`; `MealEntry` campos `user_id, day_meal_id, food_id, custom_food_id, suggestion_item_id, name, measure, amount, calories, protein, carbs, fat, position`; `CustomFood` campos `user_id, name, name_normalized, measure, kcal_per_100, protein_per_100, carbs_per_100, fat_per_100` + `SoftDeletes`; `Food::$measure` (`'g'|'ml'`), `Food::$in_plans` (bool).

- [ ] **Step 1: Escrever o teste que falha**

```php
<?php

use App\Models\CustomFood;
use App\Models\DayMeal;
use App\Models\Food;
use App\Models\MealEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;

beforeEach(function () {
    seedCatalog();
    $this->travelTo(CarbonImmutable::parse('2026-10-07 13:00', 'America/Sao_Paulo'));
    $this->user = login(User::factory()->onboarded()->create());
    planoPronto($this->user);
    $this->getJson('/api/v1/days/today')->assertOk();
    $this->almoco = DayMeal::where('slot', 'almoco')->sole();
});

it('soma o consumido pelos retratos dos registros, não pela sugestão', function () {
    MealEntry::create(['user_id' => $this->user->id, 'day_meal_id' => $this->almoco->id, 'food_id' => Food::first()->id,
        'name' => 'X', 'measure' => 'g', 'amount' => 100, 'calories' => 300, 'protein' => 20.0, 'carbs' => 30.0, 'fat' => 5.5, 'position' => 1]);
    MealEntry::create(['user_id' => $this->user->id, 'day_meal_id' => $this->almoco->id, 'food_id' => Food::first()->id,
        'name' => 'Y', 'measure' => 'g', 'amount' => 50, 'calories' => 101, 'protein' => 1.05, 'carbs' => 0.0, 'fat' => 0.0, 'position' => 2]);

    expect($this->almoco->fresh()->load('entries')->consumed())
        ->toBe(['calories' => 401, 'protein' => 21.1, 'carbs' => 30.0, 'fat' => 5.5]);
});

it('a meta da refeição é a soma da sugestão', function () {
    $meal = $this->almoco->fresh()->load('items.food');
    $soma = collect($meal->items)->sum(fn ($i) => (int) round($i->food->kcal_per_100g * $i->grams / 100));

    expect($meal->target()['calories'])->toBe($soma);
});

it('registro tem exatamente um alimento: catálogo ou próprio', function () {
    $proprio = CustomFood::create(['user_id' => $this->user->id, 'name' => 'Barra', 'name_normalized' => 'barra', 'measure' => 'g',
        'kcal_per_100' => 380, 'protein_per_100' => 30, 'carbs_per_100' => 35, 'fat_per_100' => 12]);

    expect(fn () => MealEntry::create(['user_id' => $this->user->id, 'day_meal_id' => $this->almoco->id,
        'food_id' => Food::first()->id, 'custom_food_id' => $proprio->id,
        'name' => 'X', 'measure' => 'g', 'amount' => 10, 'calories' => 1, 'protein' => 0, 'carbs' => 0, 'fat' => 0, 'position' => 1]))
        ->toThrow(QueryException::class);
});

it('alimento próprio apagado continua acessível pelo registro antigo', function () {
    $proprio = CustomFood::create(['user_id' => $this->user->id, 'name' => 'Barra', 'name_normalized' => 'barra', 'measure' => 'g',
        'kcal_per_100' => 380, 'protein_per_100' => 30, 'carbs_per_100' => 35, 'fat_per_100' => 12]);
    $registro = MealEntry::create(['user_id' => $this->user->id, 'day_meal_id' => $this->almoco->id, 'custom_food_id' => $proprio->id,
        'name' => 'Barra', 'measure' => 'g', 'amount' => 40, 'calories' => 152, 'protein' => 12, 'carbs' => 14, 'fat' => 4.8, 'position' => 1]);

    $proprio->delete();

    expect($this->user->customFoods()->count())->toBe(0)
        ->and($registro->fresh()->customFood->name)->toBe('Barra');
});

it('foods ganha medida e in_plans; os alimentos de antes ficam no plano', function () {
    expect(Food::where('slug', 'leite-integral')->sole()->measure)->toBe('ml')
        ->and(Food::where('slug', 'arroz-branco-cozido')->sole()->measure)->toBe('g')
        ->and(Food::where('in_plans', false)->count())->toBe(0);
});
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `docker compose run --rm api php artisan test --compact tests/Feature/Models/RegistroAlimentarModelsTest.php`
Expected: FAIL — `Class "App\Models\MealEntry" not found`.

- [ ] **Step 3: Migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Spec 09 (D13): registro do que foi comido, alimento próprio, g/ml e catálogo fora do plano. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('foods', function (Blueprint $table) {
            $table->string('measure', 2)->default('g')->after('source');   // RN47
            $table->boolean('in_plans')->default(false)->after('measure'); // RN52
            $table->index(['is_active', 'in_plans']);
        });
        DB::table('foods')->update(['in_plans' => true]); // os alimentos de antes continuam no plano

        Schema::create('custom_foods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->string('name_normalized', 60);
            $table->string('measure', 2);
            $table->decimal('kcal_per_100', 5, 1);
            $table->decimal('protein_per_100', 4, 1);
            $table->decimal('carbs_per_100', 4, 1);
            $table->decimal('fat_per_100', 4, 1);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['user_id', 'name_normalized']);
        });

        Schema::create('meal_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('day_meal_id')->constrained()->cascadeOnDelete();
            $table->foreignId('food_id')->nullable()->constrained('foods')->restrictOnDelete();
            $table->foreignId('custom_food_id')->nullable()->constrained('custom_foods')->restrictOnDelete();
            $table->foreignId('suggestion_item_id')->nullable()->constrained('day_meal_items')->nullOnDelete();
            $table->string('name', 120);
            $table->string('measure', 2);
            $table->decimal('amount', 6, 1);
            $table->unsignedSmallInteger('calories');
            $table->decimal('protein', 5, 1);
            $table->decimal('carbs', 5, 1);
            $table->decimal('fat', 5, 1);
            $table->unsignedTinyInteger('position');
            $table->timestamps();
            $table->index(['day_meal_id', 'position']);
            $table->index(['user_id', 'created_at']);
            $table->unique(['day_meal_id', 'suggestion_item_id']);
        });
        DB::statement('ALTER TABLE meal_entries ADD CONSTRAINT meal_entries_um_alimento CHECK ((food_id IS NOT NULL) + (custom_food_id IS NOT NULL) = 1)');
    }

    public function down(): void
    {
        Schema::dropIfExists('meal_entries');
        Schema::dropIfExists('custom_foods');
        Schema::table('foods', function (Blueprint $table) {
            $table->dropIndex(['is_active', 'in_plans']);
            $table->dropColumn(['measure', 'in_plans']);
        });
    }
};
```

- [ ] **Step 4: Modelos**

`app/Models/CustomFood.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Alimento cadastrado pelo usuário (RN50): valores por 100 g ou 100 ml; nunca entra em FoodFilter. */
class CustomFood extends Model
{
    use SoftDeletes;

    protected $fillable = ['user_id', 'name', 'name_normalized', 'measure', 'kcal_per_100', 'protein_per_100', 'carbs_per_100', 'fat_per_100'];

    protected function casts(): array
    {
        return ['kcal_per_100' => 'float', 'protein_per_100' => 'float', 'carbs_per_100' => 'float', 'fat_per_100' => 'float'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
```

`app/Models/MealEntry.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** O que o usuário comeu, com retrato dos números do momento (RN49). */
class MealEntry extends Model
{
    protected $fillable = [
        'user_id', 'day_meal_id', 'food_id', 'custom_food_id', 'suggestion_item_id',
        'name', 'measure', 'amount', 'calories', 'protein', 'carbs', 'fat', 'position',
    ];

    protected function casts(): array
    {
        return ['amount' => 'float', 'calories' => 'integer', 'protein' => 'float', 'carbs' => 'float', 'fat' => 'float'];
    }

    /** @return array{calories: int, protein: float, carbs: float, fat: float} */
    public function macros(): array
    {
        return ['calories' => $this->calories, 'protein' => $this->protein, 'carbs' => $this->carbs, 'fat' => $this->fat];
    }

    /** @return BelongsTo<DayMeal, $this> */
    public function dayMeal(): BelongsTo
    {
        return $this->belongsTo(DayMeal::class);
    }

    /** @return BelongsTo<Food, $this> */
    public function food(): BelongsTo
    {
        return $this->belongsTo(Food::class);
    }

    /** Inclui apagados: o registro antigo continua mostrando o alimento (RN49). @return BelongsTo<CustomFood, $this> */
    public function customFood(): BelongsTo
    {
        return $this->belongsTo(CustomFood::class)->withTrashed();
    }
}
```

Em `app/Models/DayMeal.php`, acrescente (com os `use` de `App\Services\Nutrition\DayTotals`):

```php
    /** O que foi comido (RN46). @return HasMany<MealEntry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(MealEntry::class)->orderBy('position');
    }

    /**
     * Consumido = soma dos retratos dos registros (RN24, RN49).
     *
     * @return array{calories: int, protein: float, carbs: float, fat: float}
     */
    public function consumed(): array
    {
        return DayTotals::sum($this->entries->map(fn (MealEntry $entry) => $entry->macros())->all());
    }

    /**
     * Meta da refeição = soma da sugestão (RN48).
     *
     * @return array{calories: int, protein: float, carbs: float, fat: float}
     */
    public function target(): array
    {
        return DayTotals::sum($this->items->map(fn (DayMealItem $item) => DayTotals::item($item->food, (float) $item->grams))->all());
    }
```

Atualize o docblock de `isDone()` para: `/** RN46 — feita = tem registro; done_at é mantido pelo EntryService. */`.

Em `app/Models/Food.php`: some `'measure', 'in_plans'` ao `$fillable`, `'in_plans' => 'boolean'` aos casts, e troque o docblock da classe para `Alimento do catálogo (valores por 100 g, ou por 100 ml quando measure = ml — RN47).`

Em `app/Models/User.php`:

```php
    /** @return HasMany<CustomFood, $this> */
    public function customFoods(): HasMany
    {
        return $this->hasMany(CustomFood::class);
    }
```

- [ ] **Step 5: Medida dos líquidos no CSV e no seeder**

Em `database/data/foods.csv`, acrescente as colunas `measure,in_plans` ao cabeçalho e `,g,1` ao fim de cada linha; depois troque para `,ml,1` as linhas `leite-integral` e `cafe-sem-acucar`:

```bash
cd backend
awk 'BEGIN{FS=OFS=","} NR==1{print $0",measure,in_plans"; next} {print $0",g,1"}' database/data/foods.csv > /tmp/foods.csv && mv /tmp/foods.csv database/data/foods.csv
sed -i -E 's/^((leite-integral|cafe-sem-acucar),.*),g,1$/\1,ml,1/' database/data/foods.csv
grep -E "^(leite-integral|cafe-sem-acucar)," database/data/foods.csv   # termina em ,ml,1
```

(awk é seguro aqui: as vírgulas dentro de aspas não importam porque só anexamos ao fim da linha.)

Em `database/seeders/FoodSeeder.php`, dentro do `updateOrCreate`, acrescente:

```php
                'measure' => ($row['measure'] ?? 'g') === 'ml' ? 'ml' : 'g',
                'in_plans' => ($row['in_plans'] ?? '1') === '1',
```

- [ ] **Step 6: Rodar e ver passar**

Run: `docker compose run --rm api php artisan test --compact tests/Feature/Models/RegistroAlimentarModelsTest.php`
Expected: PASS (5 testes).

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_10_07_000100_create_registro_alimentar.php app/Models database/data/foods.csv database/seeders/FoodSeeder.php tests/Feature/Models/RegistroAlimentarModelsTest.php
git commit -m "feat(registro): meal_entries, custom_foods e medida g/ml no catálogo (spec 09)"
```

---

### Task 2: Contas puras — porção em g/ml e situação da meta (RN47, RN48, RN49)

**Files:**
- Modify: `app/Services/Nutrition/DayTotals.php` (novo `portion()`), `app/Services/Nutrition/PortionFormatter.php` (medida)
- Create: `app/Services/Nutrition/MealGoalStatus.php`
- Test: `tests/Unit/Nutrition/MealGoalStatusTest.php`, `tests/Unit/Nutrition/DayTotalsTest.php` (acrescentar), `tests/Unit/Nutrition/PortionFormatterTest.php` (criar se não existir; senão acrescentar)

**Interfaces:**
- Produces: `DayTotals::portion(float $kcalPer100, float $proteinPer100, float $carbsPer100, float $fatPer100, float $amount): array{calories:int,protein:float,carbs:float,fat:float}`; `DayTotals::item()` passa a delegar a `portion()`; `PortionFormatter::format(float $amount, ?string $unit, ?string $unitPlural, ?float $unitGrams, string $measure = 'g'): string`; `PortionFormatter::forFood()` usa `$food->measure`; `MealGoalStatus::of(array $target, array $consumed): array{status: array{calories:'below'|'ok'|'above', protein:'below'|'ok', fat:'ok'|'above'}, goal_met: bool}`.

- [ ] **Step 1: Testes que falham**

`tests/Unit/Nutrition/MealGoalStatusTest.php`:

```php
<?php

use App\Services\Nutrition\MealGoalStatus;

$meta = ['calories' => 450, 'protein' => 25.0, 'carbs' => 60.0, 'fat' => 12.0];
$com = fn (int $kcal, float $proteina = 25.0, float $gordura = 10.0) => ['calories' => $kcal, 'protein' => $proteina, 'carbs' => 50.0, 'fat' => $gordura];

it('calorias nas bordas de 90% e 110%', function (int $kcal, string $status, bool $batida) use ($meta, $com) {
    $r = MealGoalStatus::of($meta, $com($kcal));
    expect($r['status']['calories'])->toBe($status)->and($r['goal_met'])->toBe($batida);
})->with([
    [404, 'below', false],
    [405, 'ok', true],
    [495, 'ok', true],
    [496, 'above', true],   // passar das calorias não desfaz a meta (RN48, D13)
    [560, 'above', true],
]);

it('proteína abaixo de 90% impede a meta; acima não', function () use ($meta, $com) {
    expect(MealGoalStatus::of($meta, $com(450, 22.4))['status']['protein'])->toBe('below')
        ->and(MealGoalStatus::of($meta, $com(450, 22.4))['goal_met'])->toBeFalse()
        ->and(MealGoalStatus::of($meta, $com(450, 22.5))['status']['protein'])->toBe('ok')
        ->and(MealGoalStatus::of($meta, $com(450, 40.0))['status']['protein'])->toBe('ok');
});

it('gordura acima de 110% vira só aviso', function () use ($meta, $com) {
    $r = MealGoalStatus::of($meta, $com(450, 25.0, 13.3));
    expect($r['status']['fat'])->toBe('above')->and($r['goal_met'])->toBeTrue();
    expect(MealGoalStatus::of($meta, $com(450, 25.0, 13.2))['status']['fat'])->toBe('ok');
});

it('meta sem proteína ou sem gordura não acusa nada', function () {
    $r = MealGoalStatus::of(['calories' => 50, 'protein' => 0.0, 'carbs' => 10.0, 'fat' => 0.0], ['calories' => 50, 'protein' => 0.0, 'carbs' => 10.0, 'fat' => 0.0]);
    expect($r['status'])->toBe(['calories' => 'ok', 'protein' => 'ok', 'fat' => 'ok'])->and($r['goal_met'])->toBeTrue();
});
```

Acrescente a `tests/Unit/Nutrition/DayTotalsTest.php`:

```php
it('porção por 100 g ou 100 ml com o arredondamento do dia', function () {
    expect(App\Services\Nutrition\DayTotals::portion(61, 2.9, 4.3, 3.2, 200))
        ->toBe(['calories' => 122, 'protein' => 5.8, 'carbs' => 8.6, 'fat' => 6.4]);
});
```

Teste do formatador (crie `tests/Unit/Nutrition/PortionFormatterTest.php` se não existir; se existir, só acrescente o `it`):

```php
<?php

use App\Services\Nutrition\PortionFormatter;

it('líquido sai em ml com a medida caseira', function () {
    expect((new PortionFormatter)->format(200, 'copo', 'copos', 200, 'ml'))->toBe('200 ml, mais ou menos 1 copo')
        ->and((new PortionFormatter)->format(150, null, null, null, 'ml'))->toBe('150 ml')
        ->and((new PortionFormatter)->format(150, null, null, null))->toBe('150 g');
});
```

- [ ] **Step 2: Ver falhar**

Run: `docker compose run --rm api php artisan test --compact tests/Unit/Nutrition`
Expected: FAIL — `Class "App\Services\Nutrition\MealGoalStatus" not found` e `Call to undefined method …DayTotals::portion()`.

- [ ] **Step 3: Implementar**

Em `DayTotals`:

```php
    /**
     * Macros de uma porção a partir de valores por 100 (g ou ml): kcal inteira, macros com 1 casa.
     *
     * @return Macros
     */
    public static function portion(float $kcal, float $protein, float $carbs, float $fat, float $amount): array
    {
        $factor = $amount / 100;

        return [
            'calories' => (int) round($kcal * $factor),
            'protein' => round($protein * $factor, 1),
            'carbs' => round($carbs * $factor, 1),
            'fat' => round($fat * $factor, 1),
        ];
    }

    /** @return Macros */
    public static function item(Food $food, float $grams): array
    {
        return self::portion($food->kcal_per_100g, $food->protein_per_100g, $food->carbs_per_100g, $food->fat_per_100g, $grams);
    }
```

Em `PortionFormatter`:

```php
    public function forFood(Food $food, float $amount): string
    {
        return $this->format($amount, $food->unit_label, $food->unit_label_plural, $food->unit_grams, $food->measure ?? 'g');
    }

    public function format(float $amount, ?string $unit, ?string $unitPlural, ?float $unitGrams, string $measure = 'g'): string
    {
        $text = $this->number($amount).' '.$measure;
        // … resto igual, trocando $grams por $amount
    }
```

`app/Services/Nutrition/MealGoalStatus.php`:

```php
<?php

namespace App\Services\Nutrition;

/**
 * RN48 — situação da refeição: calorias em [90%, 110%] da meta, proteína ≥ 90%, gordura ≤ 110%.
 * Meta batida = calorias ≥ 90% e proteína ≥ 90%; passar das calorias ou da gordura não desfaz (D13). Puro.
 */
final class MealGoalStatus
{
    /**
     * @param  array{calories: int, protein: float, carbs: float, fat: float}  $target
     * @param  array{calories: int, protein: float, carbs: float, fat: float}  $consumed
     * @return array{status: array{calories: string, protein: string, fat: string}, goal_met: bool}
     */
    public static function of(array $target, array $consumed): array
    {
        // Comparação em décimos para não depender de ponto flutuante nas bordas (405/450 é exatamente 90%).
        $calories = match (true) {
            $consumed['calories'] * 10 < $target['calories'] * 9 => 'below',
            $consumed['calories'] * 10 > $target['calories'] * 11 => 'above',
            default => 'ok',
        };
        $protein = round($consumed['protein'] * 10, 1) >= round($target['protein'] * 9, 1) ? 'ok' : 'below';
        $fat = round($consumed['fat'] * 10, 1) <= round($target['fat'] * 11, 1) ? 'ok' : 'above';

        return [
            'status' => ['calories' => $calories, 'protein' => $protein, 'fat' => $fat],
            'goal_met' => $calories !== 'below' && $protein === 'ok',
        ];
    }
}
```

- [ ] **Step 4: Ver passar**

Run: `docker compose run --rm api php artisan test --compact tests/Unit/Nutrition`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Nutrition tests/Unit/Nutrition
git commit -m "feat(registro): porção em g/ml, retrato e situação da meta da refeição (RN47–RN49)"
```

---

### Task 3: `FoodFilter` só com `in_plans` e conflitos por alimento (RN16, RN51, RN52)

**Files:**
- Modify: `app/Services/Foods/FoodFilter.php`
- Test: `tests/Feature/Foods/FoodFilterInPlansTest.php`

**Interfaces:**
- Produces: `FoodFilter::allowedFor(User)` ignora `in_plans = false`; `FoodFilter::conflictsFor(User $user, Collection<int, Food> $foods): array<int, list<string>>` — para cada `food_id`, os rótulos das restrições/alergias do usuário ligadas a ele e os termos de "outras restrições" que batem no nome/sinônimos (ordem: ligadas primeiro, depois termos, sem repetição).

- [ ] **Step 1: Teste que falha**

```php
<?php

use App\Models\Food;
use App\Models\Restriction;
use App\Models\User;
use App\Services\Foods\FoodFilter;

beforeEach(function () {
    seedCatalog();
    $this->user = User::factory()->onboarded()->create();
});

it('alimento fora de in_plans não entra no plano, troca nem Nutri (RN52)', function () {
    $arroz = Food::where('slug', 'arroz-branco-cozido')->sole();
    expect(app(FoodFilter::class)->allowedFor($this->user)->has($arroz->id))->toBeTrue();

    $arroz->update(['in_plans' => false]);

    expect(app(FoodFilter::class)->allowedFor($this->user)->has($arroz->id))->toBeFalse();
});

it('conflitos trazem o rótulo da restrição e o termo digitado (RN51)', function () {
    $this->user->restrictions()->attach(Restriction::where('slug', 'lactose')->sole());
    $this->user->profile->update(['other_restrictions' => ['aveia']]);
    $foods = Food::whereIn('slug', ['leite-integral', 'aveia-em-flocos', 'arroz-branco-cozido'])->get()->keyBy('id');

    $conflitos = app(FoodFilter::class)->conflictsFor($this->user->fresh(), $foods);

    expect($conflitos[Food::where('slug', 'leite-integral')->value('id')])->toBe([Restriction::where('slug', 'lactose')->value('label')])
        ->and($conflitos[Food::where('slug', 'aveia-em-flocos')->value('id')])->toBe(['aveia'])
        ->and($conflitos[Food::where('slug', 'arroz-branco-cozido')->value('id')])->toBe([]);
});
```

- [ ] **Step 2: Ver falhar**

Run: `docker compose run --rm api php artisan test --compact tests/Feature/Foods/FoodFilterInPlansTest.php`
Expected: FAIL — o primeiro teste continua `true` e `conflictsFor` não existe.

- [ ] **Step 3: Implementar**

Em `allowedFor`, logo depois de `->where('is_active', true)`: `->where('in_plans', true)`. Atualize o docblock: `Catálogo ativo com in_plans (RN52) − restrições − …`.

Acrescente:

```php
    /**
     * RN51 — por alimento, as restrições do usuário que ele toca (para avisar; nunca para bloquear registro).
     *
     * @param  Collection<int, Food>  $foods
     * @return array<int, list<string>>
     */
    public function conflictsFor(User $user, Collection $foods): array
    {
        $labels = $user->restrictions()->pluck('label', 'restrictions.id');
        $linked = DB::table('food_restriction')
            ->whereIn('food_id', $foods->keys())
            ->whereIn('restriction_id', $labels->keys())
            ->get()
            ->groupBy('food_id');
        $terms = array_values(array_filter(array_map('trim', $user->profile->other_restrictions)));

        $result = [];
        foreach ($foods as $id => $food) {
            $found = ($linked->get($id) ?? collect())->map(fn ($row) => (string) $labels[$row->restriction_id])->all();
            foreach ($terms as $term) {
                if ($this->matchesAny($food, self::variants(self::normalize($term)))) {
                    $found[] = $term;
                }
            }
            $result[$id] = array_values(array_unique($found));
        }

        return $result;
    }
```

(`matchesAny` e `variants` já existem como métodos privados da classe; mantenha-os privados.)

- [ ] **Step 4: Ver passar e rodar a suíte de alimentos e planos**

Run: `docker compose run --rm api php artisan test --compact tests/Feature/Foods tests/Feature/Plans`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Foods/FoodFilter.php tests/Feature/Foods/FoodFilterInPlansTest.php
git commit -m "feat(registro): FoodFilter só com in_plans e conflitos por alimento (RN51, RN52)"
```

---

### Task 4: Registrar, editar e remover (`EntryService` + rotas) — hoje e ontem

**Files:**
- Create: `app/Services/Days/EntryService.php`, `app/Http/Controllers/Api/V1/EntryController.php`
- Modify: `app/Services/Days/DayMaterializer.php` (ontem: prévia na leitura, gravação na escrita), `app/Enums/ErrorCode.php` (`AlreadyRegistered`, `SuggestionAlreadyRegistered`), `routes/api.php`
- Test: `tests/Feature/Days/EntriesTest.php`, `tests/Feature/Days/DayTest.php` (ajuste do teste de passado)

**Interfaces:**
- Consumes: `DayTotals::portion`, `PortionFormatter`, `MealEntry`, `CustomFood`, `DayMeal::entries()` (Tasks 1–2).
- Produces: `EntryService::assertRegistrable(CarbonImmutable $date): void`; `EntryService::add(User $user, CarbonImmutable $date, string $slot, array $entries): void` (cada entrada: `['suggestion_item_id' => int, 'amount'? => float] | ['food_id' => int, 'amount' => float] | ['custom_food_id' => int, 'amount' => float]`); `EntryService::update(User, CarbonImmutable, int $entryId, float $amount): void`; `EntryService::remove(User, CarbonImmutable, int $entryId): void`; `DayMaterializer::mealsForWriting(User, CarbonImmutable): Collection<int, DayMeal>`; `ErrorCode::AlreadyRegistered = 'ALREADY_REGISTERED'`, `ErrorCode::SuggestionAlreadyRegistered = 'SUGGESTION_ALREADY_REGISTERED'` (409). Rotas: `POST days/{date}/meals/{slot}/entries`, `PATCH days/{date}/entries/{entry}`, `DELETE days/{date}/entries/{entry}` — todas respondem `DayResource` (201 no POST).

- [ ] **Step 1: Testes que falham**

`tests/Feature/Days/EntriesTest.php`:

```php
<?php

use App\Models\CustomFood;
use App\Models\DayMeal;
use App\Models\DayMealItem;
use App\Models\Food;
use App\Models\MealEntry;
use App\Models\User;
use Carbon\CarbonImmutable;

beforeEach(function () {
    seedCatalog();
    $this->travelTo(CarbonImmutable::parse('2026-10-07 13:00', 'America/Sao_Paulo'));
    $this->user = login(User::factory()->onboarded()->create());
    planoPronto($this->user);
    $this->dia = $this->getJson('/api/v1/days/today')->json('data');
    $this->almoco = collect($this->dia['meals'])->firstWhere('slot', 'almoco');
});

it('"+" registra o item sugerido com as gramas sugeridas e marca a refeição como feita (CA31)', function () {
    $item = $this->almoco['items'][0];

    $this->postJson('/api/v1/days/today/meals/almoco/entries', ['entries' => [['suggestion_item_id' => $item['id']]]])
        ->assertCreated()
        ->assertJsonPath('data.meals.2.done', true)
        ->assertJsonPath('data.meals.2.entries.0.suggestion_item_id', $item['id'])
        ->assertJsonPath('data.meals.2.entries.0.amount', $item['grams'])
        ->assertJsonPath('data.meals.2.entries.0.calories', $item['calories'])
        ->assertJsonPath('data.meals.2.items.0.registered', true)
        ->assertJsonPath('data.totals.consumed.calories', $item['calories']);

    expect(DayMeal::where('slot', 'almoco')->sole()->done_at)->not->toBeNull();
});

it('"Adicionar os n" registra todos os itens numa requisição (CA32)', function () {
    $ids = array_map(fn ($i) => ['suggestion_item_id' => $i['id']], $this->almoco['items']);

    $dia = $this->postJson('/api/v1/days/today/meals/almoco/entries', ['entries' => $ids])->assertCreated()->json('data');

    $almoco = collect($dia['meals'])->firstWhere('slot', 'almoco');
    expect(count($almoco['entries']))->toBe(count($ids))
        ->and($almoco['consumed']['calories'])->toBe($almoco['calories']);
});

it('registra do catálogo em ml com retrato dos números (CA33)', function () {
    $leite = Food::where('slug', 'leite-integral')->sole();

    $this->postJson('/api/v1/days/today/meals/cafe/entries', ['entries' => [['food_id' => $leite->id, 'amount' => 200]]])
        ->assertCreated()
        ->assertJsonPath('data.meals.0.entries.0.measure', 'ml')
        ->assertJsonPath('data.meals.0.entries.0.amount', 200)
        ->assertJsonPath('data.meals.0.entries.0.calories', 122)
        ->assertJsonPath('data.meals.0.entries.0.amount_text', '200 ml, mais ou menos 1 copo');

    $leite->update(['kcal_per_100g' => 99]);
    $this->getJson('/api/v1/days/today')->assertJsonPath('data.meals.0.entries.0.calories', 122); // RN49
});

it('registra alimento próprio', function () {
    $barra = CustomFood::create(['user_id' => $this->user->id, 'name' => 'Barra caseira', 'name_normalized' => 'barra caseira', 'measure' => 'g',
        'kcal_per_100' => 380, 'protein_per_100' => 30, 'carbs_per_100' => 35, 'fat_per_100' => 12]);

    $this->postJson('/api/v1/days/today/meals/lanche/entries', ['entries' => [['custom_food_id' => $barra->id, 'amount' => 40]]])
        ->assertCreated()
        ->assertJsonPath('data.meals.1.entries.0.name', 'Barra caseira')
        ->assertJsonPath('data.meals.1.entries.0.calories', 152)
        ->assertJsonPath('data.meals.1.entries.0.custom_food_id', $barra->id);
});

it('o mesmo item sugerido não é registrado duas vezes', function () {
    $item = ['suggestion_item_id' => $this->almoco['items'][0]['id']];
    $this->postJson('/api/v1/days/today/meals/almoco/entries', ['entries' => [$item]])->assertCreated();

    $this->postJson('/api/v1/days/today/meals/almoco/entries', ['entries' => [$item]])
        ->assertStatus(409)->assertJsonPath('code', 'ALREADY_REGISTERED');
    $this->postJson('/api/v1/days/today/meals/jantar/entries', ['entries' => [['suggestion_item_id' => $this->almoco['items'][1]['id']]]])
        ->assertUnprocessable();
});

it('edita a quantidade recalculando o retrato e remove o último registro desfazendo o "feita" (CA38)', function () {
    $this->postJson('/api/v1/days/today/meals/almoco/entries', ['entries' => [['suggestion_item_id' => $this->almoco['items'][0]['id']]]]);
    $registro = MealEntry::sole();
    $esperado = (int) round(Food::find($registro->food_id)->kcal_per_100g * 1.5);

    $this->patchJson("/api/v1/days/today/entries/{$registro->id}", ['amount' => 150])
        ->assertOk()->assertJsonPath('data.meals.2.entries.0.amount', 150)->assertJsonPath('data.meals.2.entries.0.calories', $esperado);

    $this->deleteJson("/api/v1/days/today/entries/{$registro->id}")
        ->assertOk()->assertJsonPath('data.meals.2.done', false)->assertJsonPath('data.meals.2.entries', []);
    expect(DayMeal::where('slot', 'almoco')->sole()->done_at)->toBeNull();
});

it('o "Desfazer" da remoção devolve o vínculo com a sugestão e a quantidade editada', function () {
    $id = $this->almoco['items'][0]['id'];
    $this->postJson('/api/v1/days/today/meals/almoco/entries', ['entries' => [['suggestion_item_id' => $id]]]);
    $this->deleteJson('/api/v1/days/today/entries/'.MealEntry::sole()->id);

    $this->postJson('/api/v1/days/today/meals/almoco/entries', ['entries' => [['suggestion_item_id' => $id, 'amount' => 150]]])
        ->assertCreated()->assertJsonPath('data.meals.2.entries.0.amount', 150)->assertJsonPath('data.meals.2.items.0.registered', true);
});

it('ontem sem nada gravado vem em prévia editável e a primeira escrita grava ontem uma vez (CA39)', function () {
    $ontem = $this->getJson('/api/v1/days/2026-10-06')->assertOk()
        ->assertJsonPath('data.editable', true)->assertJsonPath('data.materialized', false)->json('data');
    $comida = Food::where('slug', 'arroz-branco-cozido')->sole();

    $this->postJson('/api/v1/days/2026-10-06/meals/jantar/entries', ['entries' => [['food_id' => $comida->id, 'amount' => 100]]])
        ->assertCreated()->assertJsonPath('data.materialized', true)->assertJsonPath('data.date', '2026-10-06');
    $this->postJson('/api/v1/days/2026-10-06/meals/jantar/entries', ['entries' => [['food_id' => $comida->id, 'amount' => 50]]])->assertCreated();

    expect(DayMeal::whereDate('date', '2026-10-06')->count())->toBe(count($ontem['meals']))
        ->and(MealEntry::count())->toBe(2);
});

it('anteontem e amanhã não aceitam registro (CA39, Review Focus 3)', function (string $data) {
    $comida = Food::first();
    $this->postJson("/api/v1/days/{$data}/meals/jantar/entries", ['entries' => [['food_id' => $comida->id, 'amount' => 100]]])
        ->assertStatus(409)->assertJsonPath('code', 'DAY_NOT_EDITABLE');
})->with(['2026-10-05', '2026-10-08']);

it('valida o lote', function (array $corpo) {
    $this->postJson('/api/v1/days/today/meals/almoco/entries', $corpo)->assertUnprocessable();
})->with([
    'vazio' => [['entries' => []]],
    'onze' => [['entries' => array_fill(0, 11, ['food_id' => 1, 'amount' => 10])]],
    'dois alimentos' => [['entries' => [['food_id' => 1, 'custom_food_id' => 1, 'amount' => 10]]]],
    'sem quantidade' => [['entries' => [['food_id' => 1]]]],
    'zero' => [['entries' => [['food_id' => 1, 'amount' => 0]]]],
    'demais' => [['entries' => [['food_id' => 1, 'amount' => 2000.1]]]],
    'inativo' => [['entries' => [['food_id' => 999999, 'amount' => 10]]]],
]);

it('nada de outro usuário: registro, alimento próprio e item sugerido dão 404 (CA42)', function () {
    $outro = User::factory()->onboarded()->create();
    login($outro);
    planoPronto($outro);
    $this->getJson('/api/v1/days/today');
    $itemDoOutro = DayMealItem::whereHas('dayMeal', fn ($q) => $q->where('user_id', $outro->id))->first();
    $proprioDoOutro = CustomFood::create(['user_id' => $outro->id, 'name' => 'Dele', 'name_normalized' => 'dele', 'measure' => 'g',
        'kcal_per_100' => 100, 'protein_per_100' => 1, 'carbs_per_100' => 1, 'fat_per_100' => 1]);
    $this->postJson('/api/v1/days/today/meals/almoco/entries', ['entries' => [['custom_food_id' => $proprioDoOutro->id, 'amount' => 10]]])->assertCreated();
    $registroDoOutro = MealEntry::sole();

    login($this->user);
    $this->patchJson("/api/v1/days/today/entries/{$registroDoOutro->id}", ['amount' => 10])->assertNotFound();
    $this->deleteJson("/api/v1/days/today/entries/{$registroDoOutro->id}")->assertNotFound();
    $this->postJson('/api/v1/days/today/meals/almoco/entries', ['entries' => [['custom_food_id' => $proprioDoOutro->id, 'amount' => 10]]])->assertNotFound();
    $this->postJson('/api/v1/days/today/meals/almoco/entries', ['entries' => [['suggestion_item_id' => $itemDoOutro->id]]])->assertNotFound();
    expect(MealEntry::count())->toBe(1);
});
```

Em `tests/Feature/Days/DayTest.php`, o teste `passado sem registro vem vazio e só para leitura` usa a data de ontem (2026-09-27 com hoje = 2026-09-28): troque a data para `2026-09-26` (anteontem). O comportamento de ontem fica coberto em `EntriesTest`.

- [ ] **Step 2: Ver falhar**

Run: `docker compose run --rm api php artisan test --compact tests/Feature/Days/EntriesTest.php`
Expected: FAIL — 404/405 nas rotas novas.

- [ ] **Step 3: `ErrorCode`**

Acrescente os casos (o `status()` padrão já é 409):

```php
    case AlreadyRegistered = 'ALREADY_REGISTERED';
    case SuggestionAlreadyRegistered = 'SUGGESTION_ALREADY_REGISTERED';
```

e as mensagens:

```php
            self::AlreadyRegistered => 'Esse alimento da sugestão já está registrado. Mude a quantidade no registro.',
            self::SuggestionAlreadyRegistered => 'Você já registrou esse alimento. Remova o registro para trocar.',
```

Troque a mensagem de `MealAlreadyDone` para `'Você já registrou o que comeu nessa refeição.'`. Se `tests/Unit/Enums/ErrorCodeTest.php` lista os códigos, acrescente os dois.

- [ ] **Step 4: `DayMaterializer` — ontem**

Troque o começo de `meals()`:

```php
        $stored = $this->stored($user, $date);
        $yesterday = CarbonImmutable::today()->subDay();
        if ($stored->isNotEmpty() || $date->lt($yesterday)) {
            return $stored; // antes de ontem: o que foi gravado (ou nada)
        }

        $plan = $user->activePlan()->with('meals.items.food')->first() ?? throw $this->noActivePlan($user);

        if (! $date->isToday()) {
            return $this->build($user, $plan, $date, save: false); // ontem sem registro e futuro: prévia (RN22)
        }
```

e acrescente:

```php
    /**
     * Refeições onde se vai escrever: ontem ainda não gravado é gravado agora (RN22, D13), uma vez só.
     *
     * @return Collection<int, DayMeal>
     */
    public function mealsForWriting(User $user, CarbonImmutable $date): Collection
    {
        if (! $date->isToday() && $this->stored($user, $date)->isEmpty()) {
            $plan = $user->activePlan()->with('meals.items.food')->first() ?? throw $this->noActivePlan($user);
            try {
                DB::transaction(fn () => $this->build($user, $plan, $date, save: true));
            } catch (UniqueConstraintViolationException) {
                // Outra aba gravou ao mesmo tempo.
            }
        }

        return $this->meals($user, $date);
    }
```

Em `stored()`, carregue também os registros: `->with(['items.food', 'items.replacedFood', 'plan', 'entries'])`.

- [ ] **Step 5: `EntryService`**

```php
<?php

namespace App\Services\Days;

use App\Enums\ErrorCode;
use App\Exceptions\DomainException;
use App\Models\CustomFood;
use App\Models\DayMeal;
use App\Models\DayMealItem;
use App\Models\Food;
use App\Models\MealEntry;
use App\Models\User;
use App\Services\Nutrition\DayTotals;
use App\Services\Nutrition\PortionFormatter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Única porta de escrita do registro alimentar (RN46, RN49, RN23: hoje e ontem). */
class EntryService
{
    public function __construct(private readonly DayMaterializer $days) {}

    public function assertRegistrable(CarbonImmutable $date): void
    {
        if (! $date->isToday() && ! $date->isYesterday()) {
            throw new DomainException(ErrorCode::DayNotEditable);
        }
    }

    /** @param list<array{suggestion_item_id?: int, food_id?: int, custom_food_id?: int, amount?: float}> $entries */
    public function add(User $user, CarbonImmutable $date, string $slot, array $entries): void
    {
        $this->assertRegistrable($date);
        $meal = $this->days->mealsForWriting($user, $date)->firstWhere('slot', $slot) ?? throw new NotFoundHttpException;

        DB::transaction(function () use ($user, $meal, $entries) {
            $meal = DayMeal::lockForUpdate()->findOrFail($meal->id);
            $position = (int) $meal->entries()->max('position');
            foreach ($entries as $entry) {
                $meal->entries()->create(['user_id' => $user->id, 'position' => ++$position, ...$this->resolve($user, $meal, $entry)]);
            }
            $this->syncDone($meal);
        });
    }

    public function update(User $user, CarbonImmutable $date, int $entryId, float $amount): void
    {
        $this->assertRegistrable($date);
        $entry = $this->entry($user, $date, $entryId);
        $snapshot = match (true) {
            $entry->food_id !== null => $this->fromFood(Food::findOrFail($entry->food_id), $amount),
            default => $this->fromCustom($entry->customFood()->firstOrFail(), $amount),
        };

        $entry->update($snapshot);
    }

    public function remove(User $user, CarbonImmutable $date, int $entryId): void
    {
        $this->assertRegistrable($date);
        $entry = $this->entry($user, $date, $entryId);

        DB::transaction(function () use ($entry) {
            $meal = $entry->dayMeal()->firstOrFail();
            $entry->delete();
            $this->syncDone($meal);
        });
    }

    /**
     * @param  array{suggestion_item_id?: int, food_id?: int, custom_food_id?: int, amount?: float}  $entry
     * @return array<string, mixed>
     */
    private function resolve(User $user, DayMeal $meal, array $entry): array
    {
        if (isset($entry['suggestion_item_id'])) {
            $item = DayMealItem::with(['food', 'dayMeal'])->find($entry['suggestion_item_id']);
            if ($item === null || $item->dayMeal->user_id !== $user->id) {
                throw new NotFoundHttpException;
            }
            if ($item->day_meal_id !== $meal->id) {
                throw ValidationException::withMessages(['entries' => 'Esse alimento é de outra refeição.']);
            }
            if ($meal->entries()->where('suggestion_item_id', $item->id)->exists()) {
                throw new DomainException(ErrorCode::AlreadyRegistered);
            }

            return ['suggestion_item_id' => $item->id, 'food_id' => $item->food_id,
                ...$this->fromFood($item->food, (float) ($entry['amount'] ?? $item->grams))];
        }

        if (isset($entry['custom_food_id'])) {
            $food = $user->customFoods()->find($entry['custom_food_id']) ?? throw new NotFoundHttpException;

            return ['custom_food_id' => $food->id, ...$this->fromCustom($food, (float) $entry['amount'])];
        }

        $food = Food::where('is_active', true)->find($entry['food_id'] ?? 0)
            ?? throw ValidationException::withMessages(['entries' => 'Esse alimento não está no catálogo.']);

        return ['food_id' => $food->id, ...$this->fromFood($food, (float) $entry['amount'])];
    }

    /** @return array<string, mixed> RN49 — retrato */
    private function fromFood(Food $food, float $amount): array
    {
        return ['name' => $food->name, 'measure' => $food->measure, 'amount' => $amount,
            ...DayTotals::portion($food->kcal_per_100g, $food->protein_per_100g, $food->carbs_per_100g, $food->fat_per_100g, $amount)];
    }

    /** @return array<string, mixed> RN49 — retrato */
    private function fromCustom(CustomFood $food, float $amount): array
    {
        return ['name' => $food->name, 'measure' => $food->measure, 'amount' => $amount,
            ...DayTotals::portion($food->kcal_per_100, $food->protein_per_100, $food->carbs_per_100, $food->fat_per_100, $amount)];
    }

    private function entry(User $user, CarbonImmutable $date, int $entryId): MealEntry
    {
        $entry = MealEntry::with('dayMeal')->find($entryId);
        if ($entry === null || $entry->user_id !== $user->id || ! $entry->dayMeal->date->isSameDay($date)) {
            throw new NotFoundHttpException;
        }

        return $entry;
    }

    /** RN46 — done_at = horário do primeiro registro; sem registro, null. */
    private function syncDone(DayMeal $meal): void
    {
        $first = $meal->entries()->min('created_at');
        $meal->update(['done_at' => $first]);
    }
}
```

(O `PortionFormatter` não é usado aqui; o texto da quantidade sai no `DayResource`, Task 5. Remova o `use` se o Pint/Larastan reclamar.)

- [ ] **Step 6: Controller e rotas**

`app/Http/Controllers/Api/V1/EntryController.php`:

```php
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
```

Em `routes/api.php`, dentro do grupo `onboarded`, logo abaixo de `days/{date}`:

```php
            Route::post('days/{date}/meals/{slot}/entries', [EntryController::class, 'store'])
                ->whereIn('slot', array_map(fn (MealSlot $slot) => $slot->value, MealSlot::cases()));
            Route::patch('days/{date}/entries/{entry}', [EntryController::class, 'update'])->whereNumber('entry');
            Route::delete('days/{date}/entries/{entry}', [EntryController::class, 'destroy'])->whereNumber('entry');
```

(com `use App\Http\Controllers\Api\V1\EntryController;`). Se a regra `array:` com chaves ou o `prohibits` com curinga não se comportarem como esperado nos datasets de validação, troque por uma closure de validação que confira "exatamente um de `suggestion_item_id`, `food_id`, `custom_food_id`" — registre a escolha no ledger.

- [ ] **Step 7: Rodar — verde parcial esperado**

Run: `docker compose run --rm api php artisan test --compact tests/Feature/Days/EntriesTest.php tests/Feature/Days/DayTest.php`
Expected: passam os testes de status/código (`DAY_NOT_EDITABLE`, `ALREADY_REGISTERED`, 404 de posse, validação, ontem materializado uma vez); **falham** os que leem `entries`, `items.*.registered`, `amount_text` ou `consumed` no JSON — eles dependem do `DayResource` da Task 5. **Não comite ainda:** faça a Task 5 (Steps 1–4) e só então rode o Step 8 abaixo e o Step 5 da Task 5. (Ruling do plano: as duas tasks entregam juntas o contrato da spec 09 §5; separadas só para revisão.)

- [ ] **Step 8: Commit (depois do Step 4 da Task 5)**

```bash
git add app/Services/Days app/Http/Controllers/Api/V1/EntryController.php app/Enums/ErrorCode.php routes/api.php tests/Feature/Days tests/Unit/Enums
git commit -m "feat(registro): registrar, editar e remover o que foi comido, hoje e ontem (RF32–RF34, RF36)"
```

---

### Task 5: Formato do dia — sugestão, registros, consumido e meta (spec 09 §5)

**Files:**
- Modify: `app/Http/Resources/DayResource.php`
- Test: `tests/Feature/Days/DayResourceShapeTest.php`

**Interfaces:**
- Consumes: `DayMeal::entries/consumed/target`, `MealGoalStatus::of`, `FoodFilter::conflictsFor`, `PortionFormatter::format` (Tasks 1–3).
- Produces (JSON por refeição): `done` (tem registro), `calories`/`macros` (meta), `consumed`, `status` (`null` sem registro), `goal_met` (`false` sem registro), `items[]` com `measure` e `registered`, `entries[]` com `id, food_id, custom_food_id, suggestion_item_id, name, amount, measure, amount_text, calories, macros, conflicts`. Dia: `editable` = hoje ou ontem; `totals.consumed` = soma dos `consumed`.

- [ ] **Step 1: Teste que falha**

```php
<?php

use App\Models\Food;
use App\Models\Restriction;
use App\Models\User;
use Carbon\CarbonImmutable;

beforeEach(function () {
    seedCatalog();
    $this->travelTo(CarbonImmutable::parse('2026-10-07 13:00', 'America/Sao_Paulo'));
    $this->user = login(User::factory()->onboarded()->create());
    planoPronto($this->user);
});

it('sem registro: refeição não feita, sem situação, consumido zero', function () {
    $almoco = collect($this->getJson('/api/v1/days/today')->json('data.meals'))->firstWhere('slot', 'almoco');

    expect($almoco)->toHaveKeys(['calories', 'macros', 'consumed', 'status', 'goal_met', 'items', 'entries'])
        ->and($almoco['done'])->toBeFalse()
        ->and($almoco['status'])->toBeNull()
        ->and($almoco['goal_met'])->toBeFalse()
        ->and($almoco['entries'])->toBe([])
        ->and($almoco['consumed']['calories'])->toBe(0)
        ->and($almoco['items'][0])->toHaveKeys(['measure', 'registered']);
});

it('situação e meta batida seguem RN48; consumido do dia vem dos registros (CA37, CA41)', function () {
    $almoco = collect($this->getJson('/api/v1/days/today')->json('data.meals'))->firstWhere('slot', 'almoco');
    $todos = array_map(fn ($i) => ['suggestion_item_id' => $i['id']], $almoco['items']);

    $dia = $this->postJson('/api/v1/days/today/meals/almoco/entries', ['entries' => $todos])->json('data');
    $depois = collect($dia['meals'])->firstWhere('slot', 'almoco');

    expect($depois['status'])->toBe(['calories' => 'ok', 'protein' => 'ok', 'fat' => 'ok'])
        ->and($depois['goal_met'])->toBeTrue()
        ->and($dia['totals']['consumed']['calories'])->toBe($depois['consumed']['calories']);
});

it('registro com restrição traz o conflito, sem bloquear (CA34)', function () {
    $lactose = Restriction::where('slug', 'lactose')->sole();
    $this->user->restrictions()->attach($lactose);
    $leite = Food::where('slug', 'leite-integral')->sole();

    $this->postJson('/api/v1/days/today/meals/cafe/entries', ['entries' => [['food_id' => $leite->id, 'amount' => 200]]])
        ->assertCreated()
        ->assertJsonPath('data.meals.0.entries.0.conflicts', [$lactose->label]);
});

it('hoje e ontem são editáveis; antes de ontem e o futuro não', function (string $data, bool $editavel) {
    $this->getJson("/api/v1/days/{$data}")->assertJsonPath('data.editable', $editavel);
})->with([['today', true], ['2026-10-06', true], ['2026-10-05', false], ['2026-10-08', false]]);
```

- [ ] **Step 2: Ver falhar**

Run: `docker compose run --rm api php artisan test --compact tests/Feature/Days/DayResourceShapeTest.php`
Expected: FAIL — chaves `consumed`, `status`, `entries` ausentes.

- [ ] **Step 3: Implementar**

Reescreva `DayResource::toArray`/`meal`/`item` assim (mantém `summary`, `note`, `last_change`, `targets` como estão):

```php
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $view = $this->resource;
        /** @var User $user */
        $user = $request->user();
        $isToday = $view->date->isToday();
        $next = $isToday ? $view->meals->first(fn (DayMeal $meal) => ! $meal->isDone()) : null;
        // Alimentos do catálogo usados nos registros: rótulos de restrição para avisar (RN51).
        $foodIds = $view->meals->flatMap(fn (DayMeal $m) => $m->relationLoaded('entries') ? $m->entries : collect())->pluck('food_id')->filter()->unique();
        $conflicts = app(FoodFilter::class)->conflictsFor($user, Food::whereIn('id', $foodIds)->get()->keyBy('id'));

        $meals = $view->meals->map(fn (DayMeal $meal) => $this->meal($meal, $next !== null && $meal->slot === $next->slot, $conflicts));
        $planned = DayTotals::sum($meals->pluck('target')->all());
        $consumed = DayTotals::sum($meals->pluck('consumed')->all());

        return [
            'date' => $view->date->toDateString(),
            'is_today' => $isToday,
            'editable' => $isToday || $view->date->isYesterday(), // RN23 (D13): registro hoje e ontem
            // … materialized, is_training_day, targets iguais
            'totals' => ['planned' => $planned, 'consumed' => $consumed, 'remaining' => DayTotals::remaining($planned, $consumed)],
            'meals' => $meals->map(fn (array $meal) => array_diff_key($meal, ['target' => true]))->all(),
            // … last_change igual
        ];
    }

    /**
     * @param  array<int, list<string>>  $conflicts
     * @return array<string, mixed>
     */
    private function meal(DayMeal $meal, bool $isNext, array $conflicts): array
    {
        $entries = $meal->relationLoaded('entries') ? $meal->entries : collect();
        $registered = $entries->pluck('suggestion_item_id')->filter()->all();
        $target = $meal->target();
        $consumed = $meal->setRelation('entries', $entries)->consumed();
        $situation = $entries->isEmpty() ? null : MealGoalStatus::of($target, $consumed);

        return [
            'id' => $meal->id,
            'slot' => $meal->slot,
            'name' => $meal->name,
            'time' => substr((string) $meal->time, 0, 5),
            'note' => $meal->note,
            'position' => $meal->position,
            'done' => $entries->isNotEmpty(), // RN46
            'is_next' => $isNext,
            'summary' => MealSummary::of($meal->items->map(fn (DayMealItem $i) => $i->food->name)->all()),
            'calories' => $target['calories'], // meta da refeição (RN48)
            'macros' => ['protein' => $target['protein'], 'carbs' => $target['carbs'], 'fat' => $target['fat']],
            'consumed' => $consumed,
            'status' => $situation['status'] ?? null,
            'goal_met' => $situation['goal_met'] ?? false,
            'items' => $meal->items->map(fn (DayMealItem $item) => $this->item($item, in_array($item->id, $registered, true)))->all(),
            'entries' => $entries->map(fn (MealEntry $entry) => $this->entry($entry, $conflicts))->values()->all(),
            'target' => $target,
        ];
    }

    /** @return array<string, mixed> */
    private function item(DayMealItem $item, bool $registered): array
    {
        $totals = DayTotals::item($item->food, $item->grams);

        return [
            'id' => $item->id,
            'food_id' => $item->food_id,
            'name' => $item->food->name,
            'grams' => $item->grams,
            'measure' => $item->food->measure,
            'amount' => app(PortionFormatter::class)->forFood($item->food, $item->grams),
            'calories' => $totals['calories'],
            'macros' => ['protein' => $totals['protein'], 'carbs' => $totals['carbs'], 'fat' => $totals['fat']],
            'source' => $item->source->value,
            'replaced_from' => $item->replacedFood?->name,
            'registered' => $registered,
        ];
    }

    /**
     * @param  array<int, list<string>>  $conflicts
     * @return array<string, mixed>
     */
    private function entry(MealEntry $entry, array $conflicts): array
    {
        $food = $entry->food_id !== null ? $entry->food : null;

        return [
            'id' => $entry->id,
            'food_id' => $entry->food_id,
            'custom_food_id' => $entry->custom_food_id,
            'suggestion_item_id' => $entry->suggestion_item_id,
            'name' => $entry->name,
            'amount' => $entry->amount,
            'measure' => $entry->measure,
            'amount_text' => app(PortionFormatter::class)->format($entry->amount, $food?->unit_label, $food?->unit_label_plural, $food?->unit_grams, $entry->measure),
            'calories' => $entry->calories,
            'macros' => ['protein' => $entry->protein, 'carbs' => $entry->carbs, 'fat' => $entry->fat],
            'conflicts' => $entry->food_id !== null ? ($conflicts[$entry->food_id] ?? []) : [],
        ];
    }
```

Imports novos no resource: `App\Models\Food`, `App\Models\MealEntry`, `App\Models\User`, `App\Services\Foods\FoodFilter`, `App\Services\Nutrition\MealGoalStatus`. Para não fazer N+1, em `DayMaterializer::stored()` carregue `'entries.food'`. As prévias (ontem sem gravar, futuro) não têm `entries` carregado: `relationLoaded` falso ⇒ coleção vazia, como acima.

- [ ] **Step 4: Ver passar (shape + entries)**

Run: `docker compose run --rm api php artisan test --compact tests/Feature/Days`
Expected: `DayResourceShapeTest` e `EntriesTest` PASS. `ToggleMealTest` e testes que usam `PATCH …/meals/{slot}` ainda passam (a rota só sai na Task 6); os que conferem `consumed` depois de marcar como feita **falham** agora — esperado, tratados na Task 6.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Resources/DayResource.php app/Services/Days/DayMaterializer.php tests/Feature/Days/DayResourceShapeTest.php
git commit -m "feat(registro): dia com sugestão, registros, consumido e situação da meta (spec 09 §5)"
```

---

### Task 6: Sai o "marcar como feita"; troca e desfazer respeitam os registros

**Files:**
- Modify: `routes/api.php` (remove a rota `PATCH days/{date}/meals/{slot}`), `app/Http/Controllers/Api/V1/DayController.php` (remove `toggleMeal`), `app/Services/Days/DayService.php` (remove `setDone`; guarda na troca; mensagem de `editableMeal`; desfazer por posição), `tests/Pest.php` (helpers), testes que marcavam refeição
- Delete: `tests/Feature/Days/ToggleMealTest.php`
- Test: `tests/Feature/Days/SuggestionVsEntriesTest.php`

**Interfaces:**
- Produces (Pest): `registrarRefeicao(string $slot, string $data = 'today'): array` (registra todos os itens sugeridos e devolve o dia); `apagarRegistros(string $slot, string $data = 'today'): void`.

- [ ] **Step 1: Teste que falha**

```php
<?php

use App\Models\MealEntry;
use App\Models\User;
use Carbon\CarbonImmutable;

beforeEach(function () {
    seedCatalog();
    $this->travelTo(CarbonImmutable::parse('2026-10-07 10:00', 'America/Sao_Paulo'));
    $this->user = login(User::factory()->onboarded()->create());
    planoPronto($this->user);
});

it('a rota de marcar como feita não existe mais', function () {
    $this->patchJson('/api/v1/days/today/meals/almoco', ['done' => true])->assertStatus(405);
});

it('item sugerido já registrado não pode ser trocado (RN26, Review Focus 1)', function () {
    $item = itemDeHoje('carboidrato');
    $this->postJson("/api/v1/days/today/meals/{$item->dayMeal->slot}/entries", ['entries' => [['suggestion_item_id' => $item->id]]])->assertCreated();
    $opcao = $this->getJson("/api/v1/days/today/items/{$item->id}/substitutions")->json('data.options.0');

    $this->postJson("/api/v1/days/today/items/{$item->id}/swap", ['food_id' => $opcao['food_id']])
        ->assertStatus(409)->assertJsonPath('code', 'SUGGESTION_ALREADY_REGISTERED');
});

it('desfazer a troca de um item não solta os outros itens registrados da refeição (RN27, Review Focus 1)', function () {
    $carbo = itemDeHoje('carboidrato');
    $slot = $carbo->dayMeal->slot;
    $outro = $carbo->dayMeal->items()->where('id', '!=', $carbo->id)->first();
    $this->postJson("/api/v1/days/today/meals/{$slot}/entries", ['entries' => [['suggestion_item_id' => $outro->id]]])->assertCreated();

    trocarPelaPrimeiraOpcao($carbo->id);
    $this->postJson('/api/v1/days/today/undo')->assertOk();

    expect(MealEntry::sole()->suggestion_item_id)->toBe($outro->id);
    $refeicao = collect($this->getJson('/api/v1/days/today')->json('data.meals'))->firstWhere('slot', $slot);
    expect(collect($refeicao['items'])->firstWhere('id', $outro->id)['registered'])->toBeTrue();
});
```

- [ ] **Step 2: Ver falhar**

Run: `docker compose run --rm api php artisan test --compact tests/Feature/Days/SuggestionVsEntriesTest.php`
Expected: FAIL — o PATCH ainda responde 200; a troca de item registrado passa; o desfazer recria os itens e o registro perde o vínculo (`suggestion_item_id` vira `null`).

- [ ] **Step 3: Implementar**

1. Remova a rota `Route::patch('days/{date}/meals/{slot}', …)` e o método `DayController::toggleMeal`; remova `DayService::setDone`.
2. Em `DayService::swap`, logo depois de `$item = $this->item(...)`:

```php
        if (MealEntry::where('suggestion_item_id', $item->id)->exists()) {
            throw new DomainException(ErrorCode::SuggestionAlreadyRegistered);
        }
```

3. Em `editableMeal`, troque a mensagem: `'Você já registrou o que comeu nesse '.mb_strtolower($meal->name).'.'` e o comentário para `/** Refeição de hoje cuja sugestão ainda pode mudar inteira (RN23; com registro não troca — RN26). */`.
4. Em `DayService::undo`, troque o bloco da transação por (devolve linha a linha, sem apagar quem ficou igual):

```php
        DB::transaction(function () use ($change, $allowed) {
            $meal = $change->dayMeal()->firstOrFail();
            $rows = $meal->items()->get()->keyBy('position');
            $kept = [];
            foreach ($change->items_before as $item) {
                if (! $allowed->has($item['food_id'])) {
                    continue; // RN17: o que ficou proibido depois da troca não volta
                }
                $row = $rows->get($item['position']);
                $row !== null ? $row->update($item) : $meal->items()->create($item);
                $kept[] = $item['position'];
            }
            $meal->items()->whereNotIn('position', $kept)->delete();
            $change->update(['undone_at' => now()]);
        });
```

5. Helpers em `tests/Pest.php`:

```php
/** Registra todos os itens sugeridos de uma refeição (o antigo "marcar como feita") e devolve o dia. */
function registrarRefeicao(string $slot, string $data = 'today'): array
{
    $refeicao = collect(test()->getJson("/api/v1/days/{$data}")->json('data.meals'))->firstWhere('slot', $slot);
    $entradas = array_map(fn ($i) => ['suggestion_item_id' => $i['id']], $refeicao['items']);

    return test()->postJson("/api/v1/days/{$data}/meals/{$slot}/entries", ['entries' => $entradas])->assertCreated()->json('data');
}

/** Remove todos os registros de uma refeição (o antigo "desmarcar"). */
function apagarRegistros(string $slot, string $data = 'today'): void
{
    $refeicao = collect(test()->getJson("/api/v1/days/{$data}")->json('data.meals'))->firstWhere('slot', $slot);
    foreach ($refeicao['entries'] as $registro) {
        test()->deleteJson("/api/v1/days/{$data}/entries/{$registro['id']}")->assertOk();
    }
}
```

6. Apague `tests/Feature/Days/ToggleMealTest.php` (CA06 agora é coberto por `EntriesTest`). Nos demais testes, troque cada `$this->patchJson('/api/v1/days/today/meals/{slot}', ['done' => true])` por `registrarRefeicao('{slot}')` e cada `['done' => false]` por `apagarRegistros('{slot}')`:

```bash
grep -rn "meals/.*\['done' =>" tests
```

Arquivos esperados: `Profile/PlanEffectTest.php`, `Notifications/TipSelectorTest.php`, `Notifications/WeeklySummaryCommandTest.php`, `Nutri/ActionsTest.php`, `Days/SwapTest.php`, `Nutri/ContextTest.php`, `Plans/ActivationTest.php`, `Progress/ProgressTest.php`. Onde o teste conferia `->assertJsonPath('data.meals.N.done', true)` depois do PATCH, confira o mesmo caminho no array devolvido por `registrarRefeicao`. Onde o teste de `Nutri/ActionsTest.php` esperava `MEAL_ALREADY_DONE` com a mensagem antiga, use a nova.

- [ ] **Step 4: Ver passar**

Run: `docker compose run --rm api php artisan test --compact tests/Feature/Days tests/Feature/Nutri tests/Feature/Plans tests/Feature/Profile`
Expected: PASS. (Notificações e Evolução ainda podem falhar por somarem a sugestão — Task 7.)

- [ ] **Step 5: Commit**

```bash
git add -A app routes tests
git commit -m "feat(registro): sai marcar como feita; troca e desfazer preservam os registros (RN26, RN27)"
```

---

### Task 7: Consumido vem dos registros em Evolução, notificações e Nutri (RN24, RN29, CA40, CA41)

**Files:**
- Modify: `app/Services/Progress/ProgressService.php`, `app/Services/Notifications/WeeklySummaryBuilder.php`, `app/Services/Notifications/TipSelector.php`, `app/Services/Nutri/NutriContextBuilder.php`, `app/Ai/Prompts/NutriPrompt.php`
- Test: `tests/Feature/Days/ConsumedFromEntriesTest.php`

**Interfaces:**
- Consumes: `DayMeal::consumed()`, helpers `registrarRefeicao` (Task 6).
- Produces: contexto da IA com `refeicoes_hoje[].sugestao` (antes `itens`) e `refeicoes_hoje[].comido: list<{nome, quantidade, medida, kcal}>`; `NutriPrompt::VERSION = 'nutri-2026-10-07'`.

- [ ] **Step 1: Teste que falha**

```php
<?php

use App\Models\Food;
use App\Models\User;
use App\Services\Notifications\WeeklySummaryBuilder;
use App\Services\Nutri\NutriContextBuilder;
use Carbon\CarbonImmutable;

beforeEach(function () {
    seedCatalog();
    $this->travelTo(CarbonImmutable::parse('2026-10-07 13:00', 'America/Sao_Paulo'));
    $this->user = login(User::factory()->onboarded()->create());
    planoPronto($this->user);
    $this->getJson('/api/v1/days/today');
    $this->ovo = Food::where('slug', 'ovos-cozidos')->sole(); // 146 kcal, 13,3 g de proteína por 100 g
    $this->postJson('/api/v1/days/today/meals/almoco/entries', ['entries' => [['food_id' => $this->ovo->id, 'amount' => 200]]])->assertCreated();
});

it('médias da Evolução usam o que foi registrado (CA41)', function () {
    $this->getJson('/api/v1/progress?period=6w')->assertOk()
        ->assertJsonPath('data.averages.calories', 292);
});

it('o resumo de domingo soma a proteína registrada', function () {
    $domingo = CarbonImmutable::parse('2026-10-11');
    expect(app(WeeklySummaryBuilder::class)->body($this->user->fresh(), $domingo))->toContain('média de 27 g de proteína');
});

it('o Nutri vê a sugestão e o que foi comido (RN29)', function () {
    $contexto = app(NutriContextBuilder::class)->forAi($this->user->fresh());
    $almoco = collect($contexto['refeicoes_hoje'])->firstWhere('slot', 'almoco');

    expect($almoco)->toHaveKeys(['sugestao', 'comido'])
        ->and($almoco['comido'])->toBe([['nome' => 'Ovos cozidos', 'quantidade' => 200.0, 'medida' => 'g', 'kcal' => 292]])
        ->and($contexto['restante_do_dia']['calories'])->toBe($contexto['metas_do_dia']['calories'] - 292);
});
```

Antes de rodar, confira em `ProgressService`/`ProgressResource` o nome real da chave das médias (`data.averages.calories` acima) e o parâmetro de período (`period=6w`); ajuste o teste ao formato existente (veja `tests/Feature/Progress/ProgressTest.php`) sem mudar a intenção.

- [ ] **Step 2: Ver falhar**

Run: `docker compose run --rm api php artisan test --compact tests/Feature/Days/ConsumedFromEntriesTest.php`
Expected: FAIL — médias e resumo somam a sugestão inteira do almoço; o contexto não tem `comido`.

- [ ] **Step 3: Implementar**

- `ProgressService::averages`: troque `->with('items.food')` por `->with('entries')` e o `map` por:

```php
            ->map(fn ($meals) => DayTotals::sum($meals->map(fn (DayMeal $meal) => $meal->consumed())->values()->all()));
```

- `WeeklySummaryBuilder::body`: `->with('entries')` e

```php
        $proteina = (int) round($comFeita->avg(fn ($dia) => DayTotals::sum($dia->filter(fn (DayMeal $meal) => $meal->isDone())
            ->map(fn (DayMeal $meal) => $meal->consumed())->values()->all())['protein']));
```

- `TipSelector::protein`: mesmo padrão (`$meals->map(fn (DayMeal $meal) => $meal->consumed())`); garanta `->with('entries')` onde `$refeicoes` é carregado.
- `NutriContextBuilder::totals`: `$consumed = DayTotals::sum($meals->map(fn (DayMeal $meal) => $meal->consumed())->all());` (o planejado continua da sugestão). Em `forAi`, troque a chave `'itens'` por `'sugestao'` e acrescente:

```php
                'comido' => $meal->entries->map(fn (MealEntry $entry) => [
                    'nome' => $entry->name, 'quantidade' => $entry->amount, 'medida' => $entry->measure, 'kcal' => $entry->calories,
                ])->values()->all(),
```

Garanta que `todayMeals` traga `entries` (vem de `DayMaterializer::stored()`, Task 4). Remova `DayMealItem`/`Food` dos `use` se ficarem sem uso.
- `NutriPrompt`: suba `VERSION` para `'nutri-2026-10-07'` e acrescente ao texto, depois de "Baseie-se no plano e no contexto fornecidos.": `'Em cada refeição de hoje, `sugestao` é o que o plano sugere e `comido` é o que a pessoa registrou de fato; o que ela já comeu é `comido`. '`.
- Rode `grep -rn "'itens'" app tests` e atualize quem lia `refeicoes_hoje[].itens` (ex.: `tests/Feature/Nutri/ContextTest.php`, ações do Nutri que leem o contexto).

- [ ] **Step 4: Ver passar e rodar tudo**

Run: `docker compose run --rm api php artisan test --compact`
Expected: PASS na suíte inteira.

- [ ] **Step 5: Commit**

```bash
git add -A app tests
git commit -m "feat(registro): Evolução, avisos e Nutri usam o que foi registrado (RN24, RN29)"
```

---

### Task 8: Busca, recentes e alimento próprio (RF33, RF35, RF37, RN50)

**Files:**
- Create: `app/Services/Foods/FoodSearch.php`, `app/Services/Foods/CustomFoodService.php`, `app/Http/Controllers/Api/V1/FoodController.php`, `app/Http/Controllers/Api/V1/CustomFoodController.php`, `app/Http/Resources/FoodResultResource.php`
- Modify: `routes/api.php`, `app/Providers/AppServiceProvider.php` (limiter `search`)
- Test: `tests/Feature/Foods/FoodSearchTest.php`, `tests/Feature/Foods/CustomFoodsTest.php`

**Interfaces:**
- Produces: `GET foods?q=&limit=` e `GET foods/recent` → lista de `{id, kind: 'catalog'|'custom', name, measure, group, per_100{calories,protein,carbs,fat}, portion{amount,text}|null, household{label,label_plural,amount}|null, conflicts, last_amount?}`; `POST/PATCH/DELETE custom-foods`; `FoodSearch::search(User, string $q, int $limit = 20): Collection`; `FoodSearch::recent(User): Collection`; `CustomFoodService::create(User, array): CustomFood`, `update(User, int, array): CustomFood`, `delete(User, int): void`.

- [ ] **Step 1: Testes que falham**

`tests/Feature/Foods/FoodSearchTest.php`:

```php
<?php

use App\Models\CustomFood;
use App\Models\Food;
use App\Models\Restriction;
use App\Models\User;
use Carbon\CarbonImmutable;

beforeEach(function () {
    seedCatalog();
    $this->travelTo(CarbonImmutable::parse('2026-10-07 13:00', 'America/Sao_Paulo'));
    $this->user = login(User::factory()->onboarded()->create());
    planoPronto($this->user);
});

it('acha sem acento e por sinônimo; começa-com vem antes de contém', function () {
    $nomes = collect($this->getJson('/api/v1/foods?q=feijao')->assertOk()->json('data'))->pluck('name');
    expect($nomes->first())->toStartWith('Feijão');

    expect(collect($this->getJson('/api/v1/foods?q=cafezinho')->json('data'))->pluck('name'))->toContain('Café sem açúcar');
});

it('mostra o que está fora do plano e o conflito de restrição, sem esconder (RN51, RN52, CA34)', function () {
    Food::where('slug', 'leite-integral')->update(['in_plans' => false]);
    $lactose = Restriction::where('slug', 'lactose')->sole();
    $this->user->restrictions()->attach($lactose);

    $leite = collect($this->getJson('/api/v1/foods?q=leite')->json('data'))->firstWhere('name', 'Leite integral');

    expect($leite)->not->toBeNull()
        ->and($leite['measure'])->toBe('ml')
        ->and($leite['conflicts'])->toBe([$lactose->label])
        ->and($leite['portion'])->toBe(['amount' => 200, 'text' => '200 ml, mais ou menos 1 copo']);
});

it('alimento próprio só aparece para o dono e some ao apagar', function () {
    $outro = User::factory()->onboarded()->create();
    CustomFood::create(['user_id' => $outro->id, 'name' => 'Barra do outro', 'name_normalized' => 'barra do outro', 'measure' => 'g',
        'kcal_per_100' => 1, 'protein_per_100' => 0, 'carbs_per_100' => 0, 'fat_per_100' => 0]);
    $minha = CustomFood::create(['user_id' => $this->user->id, 'name' => 'Barra minha', 'name_normalized' => 'barra minha', 'measure' => 'g',
        'kcal_per_100' => 380, 'protein_per_100' => 30, 'carbs_per_100' => 35, 'fat_per_100' => 12]);

    $achados = collect($this->getJson('/api/v1/foods?q=barra')->json('data'));
    expect($achados->pluck('name')->all())->toContain('Barra minha')->not->toContain('Barra do outro')
        ->and($achados->firstWhere('name', 'Barra minha')['kind'])->toBe('custom');

    $minha->delete();
    expect(collect($this->getJson('/api/v1/foods?q=barra')->json('data'))->pluck('name'))->not->toContain('Barra minha');
});

it('recentes: os mais registrados nos últimos 30 dias, com a última quantidade', function () {
    $ovo = Food::where('slug', 'ovos-cozidos')->sole();
    $this->postJson('/api/v1/days/today/meals/cafe/entries', ['entries' => [['food_id' => $ovo->id, 'amount' => 100]]]);
    $this->postJson('/api/v1/days/today/meals/lanche/entries', ['entries' => [['food_id' => $ovo->id, 'amount' => 50]]]);

    $this->getJson('/api/v1/foods/recent')->assertOk()
        ->assertJsonPath('data.0.name', 'Ovos cozidos')
        ->assertJsonPath('data.0.last_amount', 50);
});

it('busca exige 2 letras', function () {
    $this->getJson('/api/v1/foods?q=a')->assertUnprocessable();
});
```

`tests/Feature/Foods/CustomFoodsTest.php`:

```php
<?php

use App\Models\CustomFood;
use App\Models\MealEntry;
use App\Models\User;
use Carbon\CarbonImmutable;

beforeEach(function () {
    seedCatalog();
    $this->travelTo(CarbonImmutable::parse('2026-10-07 13:00', 'America/Sao_Paulo'));
    $this->user = login(User::factory()->onboarded()->create());
    planoPronto($this->user);
    $this->corpo = ['name' => 'Barra de cereal caseira', 'measure' => 'g', 'per_100' => ['calories' => 380, 'protein' => 30, 'carbs' => 35, 'fat' => 12]];
});

it('cadastra e registra (CA35)', function () {
    $id = $this->postJson('/api/v1/custom-foods', $this->corpo)->assertCreated()
        ->assertJsonPath('data.kind', 'custom')->assertJsonPath('data.per_100.calories', 380)->json('data.id');

    $this->postJson('/api/v1/days/today/meals/lanche/entries', ['entries' => [['custom_food_id' => $id, 'amount' => 40]]])
        ->assertCreated()->assertJsonPath('data.meals.1.entries.0.calories', 152);
});

it('recusa números que não batem e campos fora do limite (CA36)', function (array $troca, string $campo) {
    $this->postJson('/api/v1/custom-foods', array_replace_recursive($this->corpo, $troca))
        ->assertUnprocessable()->assertJsonValidationErrors([$campo]);
})->with([
    'kcal baixa demais' => [['per_100' => ['calories' => 38]], 'per_100.calories'],
    'kcal acima de 900' => [['per_100' => ['calories' => 901]], 'per_100.calories'],
    'macros somam mais de 100' => [['per_100' => ['protein' => 60, 'carbs' => 50]], 'per_100'],
    'nome curto' => [['name' => 'A'], 'name'],
    'medida' => [['measure' => 'kg'], 'measure'],
]);

it('a mensagem da coerência é a da spec', function () {
    $this->postJson('/api/v1/custom-foods', array_replace_recursive($this->corpo, ['per_100' => ['calories' => 38]]))
        ->assertJsonPath('errors.per_100\.calories.0', 'Os números não batem: confira as calorias.');
});

it('nome repetido (sem acento/maiúscula) é recusado; apagado pode ser cadastrado de novo', function () {
    $id = $this->postJson('/api/v1/custom-foods', $this->corpo)->assertCreated()->json('data.id');
    $this->postJson('/api/v1/custom-foods', [...$this->corpo, 'name' => 'BARRA DE CEREAL CASEÍRA'])->assertUnprocessable()->assertJsonValidationErrors(['name']);

    $this->deleteJson("/api/v1/custom-foods/{$id}")->assertNoContent();
    $this->postJson('/api/v1/custom-foods', $this->corpo)->assertCreated();
});

it('editar muda só os próximos registros; apagar mantém os antigos (CA45, Review Focus 4)', function () {
    $id = $this->postJson('/api/v1/custom-foods', $this->corpo)->json('data.id');
    $this->postJson('/api/v1/days/today/meals/lanche/entries', ['entries' => [['custom_food_id' => $id, 'amount' => 100]]]);

    $this->patchJson("/api/v1/custom-foods/{$id}", ['name' => 'Barra nova', 'per_100' => ['calories' => 400]])->assertOk()
        ->assertJsonPath('data.name', 'Barra nova')->assertJsonPath('data.per_100.calories', 400);
    expect(MealEntry::sole()->only(['name', 'calories']))->toBe(['name' => 'Barra de cereal caseira', 'calories' => 380]);

    $this->deleteJson("/api/v1/custom-foods/{$id}")->assertNoContent();
    $this->getJson('/api/v1/days/today')->assertJsonPath('data.meals.1.entries.0.name', 'Barra de cereal caseira');
    $this->postJson('/api/v1/days/today/meals/lanche/entries', ['entries' => [['custom_food_id' => $id, 'amount' => 10]]])->assertNotFound();
});

it('não mexe no alimento de outro usuário (Review Focus 5)', function () {
    $dele = CustomFood::create(['user_id' => User::factory()->create()->id, 'name' => 'Dele', 'name_normalized' => 'dele', 'measure' => 'g',
        'kcal_per_100' => 100, 'protein_per_100' => 1, 'carbs_per_100' => 1, 'fat_per_100' => 1]);

    $this->patchJson("/api/v1/custom-foods/{$dele->id}", ['name' => 'Meu'])->assertNotFound();
    $this->deleteJson("/api/v1/custom-foods/{$dele->id}")->assertNotFound();
});

it('não há limite de quantidade', function () {
    foreach (range(1, 120) as $n) {
        CustomFood::create(['user_id' => $this->user->id, 'name' => "Item {$n}", 'name_normalized' => "item {$n}", 'measure' => 'g',
            'kcal_per_100' => 100, 'protein_per_100' => 1, 'carbs_per_100' => 1, 'fat_per_100' => 1]);
    }
    $this->postJson('/api/v1/custom-foods', $this->corpo)->assertCreated();
});
```

- [ ] **Step 2: Ver falhar**

Run: `docker compose run --rm api php artisan test --compact tests/Feature/Foods/FoodSearchTest.php tests/Feature/Foods/CustomFoodsTest.php`
Expected: FAIL — rotas inexistentes.

- [ ] **Step 3: `FoodSearch`**

```php
<?php

namespace App\Services\Foods;

use App\Models\CustomFood;
use App\Models\Food;
use App\Models\MealEntry;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** RN50 — busca no catálogo ativo (inclusive fora do plano) e nos alimentos próprios; recentes. */
class FoodSearch
{
    /** @return Collection<int, Food|CustomFood> */
    public function search(User $user, string $q, int $limit = 20): Collection
    {
        $term = FoodFilter::normalize($q);
        $uses = $this->uses($user);

        $catalog = Food::where('is_active', true)->get()
            ->map(fn (Food $f) => [$f, $this->rank($term, [$f->name, ...$f->aliases])])
            ->filter(fn (array $pair) => $pair[1] !== null);
        $custom = $user->customFoods()->get()
            ->map(fn (CustomFood $f) => [$f, $this->rank($term, [$f->name])])
            ->filter(fn (array $pair) => $pair[1] !== null);

        return $catalog->concat($custom)
            ->sortBy([
                fn (array $a, array $b) => $a[1] <=> $b[1],
                fn (array $a, array $b) => ($uses[$this->key($b[0])] ?? 0) <=> ($uses[$this->key($a[0])] ?? 0),
                fn (array $a, array $b) => strcmp(FoodFilter::normalize($a[0]->name), FoodFilter::normalize($b[0]->name)),
            ])
            ->take($limit)
            ->map(fn (array $pair) => $pair[0])
            ->values();
    }

    /** @return Collection<int, array{food: Food|CustomFood, last_amount: float}> */
    public function recent(User $user): Collection
    {
        $rows = MealEntry::where('user_id', $user->id)
            ->where('created_at', '>=', now()->subDays(30))
            ->orderByDesc('created_at')
            ->get(['food_id', 'custom_food_id', 'amount', 'created_at']);

        return $rows->groupBy(fn (MealEntry $e) => $e->food_id !== null ? "f{$e->food_id}" : "c{$e->custom_food_id}")
            ->sortByDesc(fn ($group) => $group->count())
            ->take(8)
            ->map(function ($group) use ($user) {
                $first = $group->first();
                $food = $first->food_id !== null
                    ? Food::where('is_active', true)->find($first->food_id)
                    : $user->customFoods()->find($first->custom_food_id);

                return $food === null ? null : ['food' => $food, 'last_amount' => $first->amount];
            })
            ->filter()
            ->values();
    }

    /** 0 = começa com o termo (nome ou sinônimo), 1 = contém, null = não bate. */
    private function rank(string $term, array $names): ?int
    {
        $best = null;
        foreach ($names as $name) {
            $n = FoodFilter::normalize((string) $name);
            if (str_starts_with($n, $term)) {
                return 0;
            }
            if (str_contains($n, $term)) {
                $best = 1;
            }
        }

        return $best;
    }

    /** @return array<string, int> quantas vezes cada alimento foi registrado em 30 dias */
    private function uses(User $user): array
    {
        return MealEntry::where('user_id', $user->id)->where('created_at', '>=', now()->subDays(30))
            ->select('food_id', 'custom_food_id', DB::raw('count(*) as n'))->groupBy('food_id', 'custom_food_id')->get()
            ->mapWithKeys(fn ($r) => [$r->food_id !== null ? "f{$r->food_id}" : "c{$r->custom_food_id}" => (int) $r->n])->all();
    }

    private function key(Food|CustomFood $food): string
    {
        return ($food instanceof Food ? 'f' : 'c').$food->id;
    }
}
```

(Carregar o catálogo ativo inteiro em memória é aceitável com ~700 linhas; se o Plano 11B passar de 2.000 itens, troque por `whereRaw` com coluna normalizada.)

- [ ] **Step 4: `CustomFoodService` e regras**

```php
<?php

namespace App\Services\Foods;

use App\Models\CustomFood;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** RF35/RF37 — alimento próprio: privado, sem limite, editável, exclusão lógica (RN50). */
class CustomFoodService
{
    /** @param array{name: string, measure: string, per_100: array{calories: float, protein: float, carbs: float, fat: float}} $data */
    public function create(User $user, array $data): CustomFood
    {
        $this->assertUniqueName($user, $data['name'], null);
        $this->assertCoherent($data['per_100']);

        return $user->customFoods()->create($this->columns($data));
    }

    /** @param array{name?: string, measure?: string, per_100?: array<string, float>} $data */
    public function update(User $user, int $id, array $data): CustomFood
    {
        $food = $user->customFoods()->find($id) ?? throw new NotFoundHttpException;
        $merged = [
            'name' => $data['name'] ?? $food->name,
            'measure' => $data['measure'] ?? $food->measure,
            'per_100' => array_merge(
                ['calories' => $food->kcal_per_100, 'protein' => $food->protein_per_100, 'carbs' => $food->carbs_per_100, 'fat' => $food->fat_per_100],
                $data['per_100'] ?? [],
            ),
        ];
        $this->assertUniqueName($user, $merged['name'], $food->id);
        $this->assertCoherent($merged['per_100']);
        $food->update($this->columns($merged));

        return $food;
    }

    public function delete(User $user, int $id): void
    {
        ($user->customFoods()->find($id) ?? throw new NotFoundHttpException)->delete();
    }

    /** @param array{calories: float, protein: float, carbs: float, fat: float} $per100 */
    private function assertCoherent(array $per100): void
    {
        if ($per100['protein'] + $per100['carbs'] + $per100['fat'] > 100) {
            throw ValidationException::withMessages(['per_100' => 'Proteína, carboidrato e gordura somam mais de 100 g.']);
        }
        if (4 * $per100['protein'] + 4 * $per100['carbs'] + 9 * $per100['fat'] > $per100['calories'] * 1.25 + 20) {
            throw ValidationException::withMessages(['per_100.calories' => 'Os números não batem: confira as calorias.']);
        }
    }

    private function assertUniqueName(User $user, string $name, ?int $except): void
    {
        $exists = $user->customFoods()->where('name_normalized', FoodFilter::normalize($name))
            ->when($except, fn ($q) => $q->where('id', '!=', $except))->exists();
        if ($exists) {
            throw ValidationException::withMessages(['name' => 'Você já cadastrou um alimento com esse nome.']);
        }
    }

    /** @return array<string, mixed> */
    private function columns(array $data): array
    {
        return [
            'name' => trim($data['name']), 'name_normalized' => FoodFilter::normalize($data['name']), 'measure' => $data['measure'],
            'kcal_per_100' => $data['per_100']['calories'], 'protein_per_100' => $data['per_100']['protein'],
            'carbs_per_100' => $data['per_100']['carbs'], 'fat_per_100' => $data['per_100']['fat'],
        ];
    }
}
```

`FoodFilter::normalize` tira acento com `Str::ascii` — "CASEÍRA" vira "caseira", como o teste espera.

- [ ] **Step 5: Resource, controllers, rotas e limiter**

`app/Http/Resources/FoodResultResource.php` (recebe `['food' => Food|CustomFood, 'conflicts' => list<string>, 'last_amount' => ?float]`):

```php
<?php

namespace App\Http\Resources;

use App\Models\CustomFood;
use App\Models\Food;
use App\Services\Nutrition\PortionFormatter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Resultado da busca/recentes/cadastro (spec 09 §5). @property array{food: Food|CustomFood, conflicts: list<string>, last_amount?: float|null} $resource */
class FoodResultResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $food = $this->resource['food'];
        $catalog = $food instanceof Food;
        $data = [
            'id' => $food->id,
            'kind' => $catalog ? 'catalog' : 'custom',
            'name' => $food->name,
            'measure' => $food->measure,
            'group' => $catalog ? $food->group : null,
            'per_100' => $catalog
                ? ['calories' => $food->kcal_per_100g, 'protein' => $food->protein_per_100g, 'carbs' => $food->carbs_per_100g, 'fat' => $food->fat_per_100g]
                : ['calories' => $food->kcal_per_100, 'protein' => $food->protein_per_100, 'carbs' => $food->carbs_per_100, 'fat' => $food->fat_per_100],
            'portion' => $catalog ? ['amount' => $food->typical_portion_g, 'text' => app(PortionFormatter::class)->forFood($food, $food->typical_portion_g)] : null,
            'household' => $catalog && $food->unit_label !== null && $food->unit_grams !== null
                ? ['label' => $food->unit_label, 'label_plural' => $food->unit_label_plural, 'amount' => $food->unit_grams] : null,
            'conflicts' => $this->resource['conflicts'],
        ];
        if (array_key_exists('last_amount', $this->resource)) {
            $data['last_amount'] = $this->resource['last_amount'];
        }

        return $data;
    }
}
```

`FoodController`:

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\FoodResultResource;
use App\Models\Food;
use App\Models\User;
use App\Services\Foods\FoodFilter;
use App\Services\Foods\FoodSearch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;

/** RF33 — buscar no catálogo e nos alimentos próprios; recentes. */
class FoodController extends Controller
{
    public function index(Request $request, FoodSearch $search, FoodFilter $filter): AnonymousResourceCollection
    {
        $data = $request->validate(['q' => ['required', 'string', 'min:2', 'max:60'], 'limit' => ['integer', 'between:1,20']],
            ['q.*' => 'Digite pelo menos 2 letras.']);
        /** @var User $user */
        $user = $request->user();
        $foods = $search->search($user, $data['q'], (int) ($data['limit'] ?? 20));

        return FoodResultResource::collection($this->withConflicts($user, $foods->map(fn ($f) => ['food' => $f]), $filter));
    }

    public function recent(Request $request, FoodSearch $search, FoodFilter $filter): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        return FoodResultResource::collection($this->withConflicts($user, $search->recent($user), $filter));
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function withConflicts(User $user, Collection $rows, FoodFilter $filter): Collection
    {
        $catalog = $rows->pluck('food')->filter(fn ($f) => $f instanceof Food)->keyBy('id');
        $conflicts = $filter->conflictsFor($user, $catalog);

        return $rows->map(fn (array $row) => [...$row, 'conflicts' => $row['food'] instanceof Food ? ($conflicts[$row['food']->id] ?? []) : []]);
    }
}
```

`CustomFoodController`:

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\FoodResultResource;
use App\Models\User;
use App\Services\Foods\CustomFoodService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** RF35/RF37 — alimento próprio. */
class CustomFoodController extends Controller
{
    /** @return array<string, list<string>> */
    private function rules(bool $partial): array
    {
        $r = $partial ? 'sometimes' : 'required';

        return [
            'name' => [$r, 'string', 'min:2', 'max:60', 'not_regex:/^\s*$/'],
            'measure' => [$r, 'in:g,ml'],
            'per_100' => [$r, 'array'],
            'per_100.calories' => [$r, 'numeric', 'between:0,900'],
            'per_100.protein' => [$r, 'numeric', 'between:0,100'],
            'per_100.carbs' => [$r, 'numeric', 'between:0,100'],
            'per_100.fat' => [$r, 'numeric', 'between:0,100'],
        ];
    }

    /** @var array<string, string> */
    private const MESSAGES = [
        'name.*' => 'Dê um nome de 2 a 60 letras.',
        'measure.*' => 'Escolha sólido (g) ou líquido (ml).',
        'per_100.calories.*' => 'Calorias entre 0 e 900 por 100.',
        'per_100.*.*' => 'Use um valor entre 0 e 100 g.',
    ];

    public function store(Request $request, CustomFoodService $service): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $food = $service->create($user, $request->validate($this->rules(false), self::MESSAGES));

        return (new FoodResultResource(['food' => $food, 'conflicts' => []]))->response()->setStatusCode(201);
    }

    public function update(Request $request, int $customFood, CustomFoodService $service): FoodResultResource
    {
        /** @var User $user */
        $user = $request->user();

        return new FoodResultResource(['food' => $service->update($user, $customFood, $request->validate($this->rules(true), self::MESSAGES)), 'conflicts' => []]);
    }

    public function destroy(Request $request, int $customFood, CustomFoodService $service): Response
    {
        /** @var User $user */
        $user = $request->user();
        $service->delete($user, $customFood);

        return response()->noContent();
    }
}
```

Rotas (grupo `onboarded`):

```php
            Route::get('foods', [FoodController::class, 'index'])->middleware('throttle:search');
            Route::get('foods/recent', [FoodController::class, 'recent']);
            Route::post('custom-foods', [CustomFoodController::class, 'store']);
            Route::patch('custom-foods/{customFood}', [CustomFoodController::class, 'update'])->whereNumber('customFood');
            Route::delete('custom-foods/{customFood}', [CustomFoodController::class, 'destroy'])->whereNumber('customFood');
```

Em `AppServiceProvider`, junto dos outros limiters:

```php
        RateLimiter::for('search', fn (Request $request) => Limit::perMinute(60)->by('search|'.$request->user()?->getAuthIdentifier()));
```

- [ ] **Step 6: Ver passar**

Run: `docker compose run --rm api php artisan test --compact tests/Feature/Foods`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add app routes tests/Feature/Foods
git commit -m "feat(registro): busca, recentes e alimento próprio editável/apagável (RF33, RF35, RF37)"
```

---

### Task 9: Dados antigos viram registros; demo e E2E com registros (CA44)

**Files:**
- Create: `database/migrations/2026_10_07_000200_convert_done_meals_to_entries.php`
- Modify: `database/seeders/DemoSeeder.php`, `database/seeders/E2ESeeder.php` (se marcar refeições), `docs/implantacao.md` (frase da demo)
- Test: `tests/Feature/Days/ConvertDoneMealsTest.php`

- [ ] **Step 1: Teste que falha**

```php
<?php

use App\Models\DayMeal;
use App\Models\MealEntry;
use App\Models\User;
use Carbon\CarbonImmutable;

beforeEach(function () {
    seedCatalog();
    $this->travelTo(CarbonImmutable::parse('2026-10-07 13:00', 'America/Sao_Paulo'));
    $this->user = login(User::factory()->onboarded()->create());
    planoPronto($this->user);
    $this->getJson('/api/v1/days/today');
});

it('refeição feita antes da mudança ganha registros iguais à sugestão; o consumido não muda (CA44)', function () {
    $almoco = DayMeal::where('slot', 'almoco')->sole();
    $almoco->update(['done_at' => now()]);
    $esperado = $almoco->load('items.food')->target();

    $migration = require database_path('migrations/2026_10_07_000200_convert_done_meals_to_entries.php');
    $migration->up();
    $migration->up(); // idempotente

    expect(MealEntry::count())->toBe($almoco->items->count())
        ->and($almoco->fresh()->load('entries')->consumed())->toBe($esperado)
        ->and(MealEntry::whereNull('suggestion_item_id')->count())->toBe(0);
});
```

- [ ] **Step 2: Ver falhar**

Run: `docker compose run --rm api php artisan test --compact tests/Feature/Days/ConvertDoneMealsTest.php`
Expected: FAIL — arquivo de migration não existe.

- [ ] **Step 3: Migration de dados**

```php
<?php

use App\Models\DayMeal;
use App\Models\DayMealItem;
use App\Services\Nutrition\DayTotals;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** CA44 — "feita" antes da D13 = comeu a sugestão: vira registro igual a cada item. Idempotente. */
return new class extends Migration
{
    public function up(): void
    {
        DayMeal::whereNotNull('done_at')->whereDoesntHave('entries')->with('items.food')->chunkById(200, function ($meals) {
            foreach ($meals as $meal) {
                DB::transaction(function () use ($meal) {
                    foreach ($meal->items as $i => $item) {
                        /** @var DayMealItem $item */
                        $meal->entries()->create([
                            'user_id' => $meal->user_id, 'food_id' => $item->food_id, 'suggestion_item_id' => $item->id,
                            'name' => $item->food->name, 'measure' => $item->food->measure, 'amount' => $item->grams,
                            'position' => $i + 1, 'created_at' => $meal->done_at, 'updated_at' => $meal->done_at,
                            ...DayTotals::item($item->food, (float) $item->grams),
                        ]);
                    }
                });
            }
        });
    }

    public function down(): void
    {
        // Sem volta: os registros passam a ser a fonte do consumido.
    }
};
```

- [ ] **Step 4: Demo e E2E**

Em `DemoSeeder.php` (linha que faz `$meal->update(['done_at' => …])`), troque por registrar os itens sugeridos com o retrato e `created_at` no horário da refeição:

```php
                $quando = $data->setTimeFromTimeString((string) $meal->time);
                foreach ($meal->items()->with('food')->get() as $i => $item) {
                    $meal->entries()->create([
                        'user_id' => $meal->user_id, 'food_id' => $item->food_id, 'suggestion_item_id' => $item->id,
                        'name' => $item->food->name, 'measure' => $item->food->measure, 'amount' => $item->grams,
                        'position' => $i + 1, 'created_at' => $quando, 'updated_at' => $quando,
                        ...DayTotals::item($item->food, (float) $item->grams),
                    ]);
                }
                $meal->update(['done_at' => $quando]);
```

Para a demo mostrar o registro livre, no **dia de hoje** da Camila registre o café com quantidades diferentes da sugestão (ex.: a aveia com 60 g a mais) em vez de copiar a sugestão — assim a régua da refeição aparece acima da meta. Rode `grep -n "done_at\|done" database/seeders/E2ESeeder.php`; se o E2E marca refeições, aplique a mesma troca.

Em `docs/implantacao.md` §7, troque "28 dias de constância (21 completos, sequência de 3)" por "28 dias de registros (21 completos, sequência de 3) e o café de hoje registrado acima da meta".

- [ ] **Step 5: Ver passar e semear a demo**

Run:
```bash
docker compose run --rm api php artisan test --compact tests/Feature/Days/ConvertDoneMealsTest.php tests/Feature/Seeders
docker compose run --rm api php artisan migrate:fresh --seed
```
Expected: PASS; seed sem erro.

- [ ] **Step 6: Commit**

```bash
git add database docs/implantacao.md tests/Feature/Days/ConvertDoneMealsTest.php
git commit -m "feat(registro): refeições feitas antes viram registros; demo com registro livre (CA44)"
```

---

### Task 10: Specs que citam "feita", verificação final

**Files:**
- Modify: `specs/04-nutri/spec.md`, `specs/05-evolucao/spec.md`, `specs/06-configuracoes-notificacoes/spec.md`, `specs/07-validacao-feedback/spec.md`, `specs/00-fundacao/integracao-ia.md` (contexto do Nutri: `sugestao`/`comido`), `specs/00-fundacao/api-convencoes-e-erros.md` (códigos `ALREADY_REGISTERED`, `SUGGESTION_ALREADY_REGISTERED`, mensagem nova de `MEAL_ALREADY_DONE`), `specs/09-registro-alimentar/spec.md` (DoD do backend)

- [ ] **Step 1: Ajustar textos**

```bash
grep -rn "feita\|marcar\|Marcar\|done" specs/04-nutri specs/05-evolucao specs/06-configuracoes-notificacoes specs/07-validacao-feedback specs/00-fundacao/integracao-ia.md specs/00-fundacao/api-convencoes-e-erros.md
```

Em cada ocorrência que fala de "refeição feita"/"marcar como feita", acrescente o sentido novo: "feita = com registro (RN46)". Onde descreve o contexto do Nutri, troque "refeições feitas" por "o que foi registrado em cada refeição (`comido`) e a sugestão (`sugestao`)". Na tabela de códigos, acrescente os dois códigos novos (409) e a mensagem nova de `MEAL_ALREADY_DONE`.

- [ ] **Step 2: Suíte completa e análise**

Run:
```bash
docker compose run --rm api php artisan test --compact
docker compose run --rm api ./vendor/bin/pint --test
docker compose run --rm api ./vendor/bin/phpstan analyse --memory-limit=1G
```
Expected: tudo verde.

- [ ] **Step 3: Marcar o DoD do backend na spec 09**

Marque `[x]` em: Migrations; Endpoints de registro, busca, recentes e alimento próprio; Consumido vem dos registros; Specs 03–07 atualizadas. Os demais itens (catálogo, telas, E2E) ficam para os Planos 11B e 11C.

- [ ] **Step 4: Commit**

```bash
git add specs
git commit -m "docs(specs): feita = com registro nas specs 04–07; códigos novos (spec 09)"
```
