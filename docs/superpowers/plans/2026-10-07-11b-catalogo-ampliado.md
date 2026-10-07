# Plano 11B — Catálogo com ≥ 700 alimentos — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** O catálogo passa de 62 para pelo menos 700 alimentos com valores de fontes públicas (TACO 4ª ed. e Tabela de Composição Nutricional do IBGE/POF 2008–2009), medida g/ml, porção de referência e ligação às restrições — para o usuário achar o que comeu na busca do registro.

**Architecture:** Um comando artisan `catalog:import` lê um **CSV intermediário normalizado** (uma linha por alimento, colunas fixas) gerado a partir dos arquivos oficiais, aplica regras determinísticas (grupo, medida, porção, sinônimo, restrições) e **acrescenta** linhas novas a `database/data/foods.csv` — o seeder continua sendo a única forma de carregar o catálogo. Os 62 alimentos atuais não mudam (mesmo `slug`, `in_plans = 1`); os novos entram com `in_plans = 0` (RN52).

**Tech Stack:** Laravel 12 (comando artisan), Pest, CSV. Arquivos oficiais baixados à mão (sem chamada de rede nos testes).

**Spec:** `specs/09-registro-alimentar/spec.md` (CA43, DoD), `specs/00-fundacao/regras-de-negocio.md` (RN16, RN47, RN52), `specs/99-inconsistencias.md` (P5).

**Onde rodar:** backend, branch `registro-alimentar`, **depois do Plano 11A** (precisa das colunas `measure` e `in_plans`).

## Global Constraints

- Fonte de cada linha em `source`: `TACO 4ª ed.` ou `IBGE POF 2008-2009`. Nada inventado: valor ausente ("NA", "*", vazio) vira 0 só para macro; linha sem kcal é descartada.
- "Tr" (traço) vira 0.
- Os 62 alimentos atuais não mudam de `slug`, valores nem `in_plans`.
- Novos alimentos: `in_plans = 0`, `is_staple = 0`, `common_dislike = 0`, sem `substitution_note`, sem item de cozinha.
- Restrições nos novos: ligação por regra **conservadora** (na dúvida, liga — o lado seguro do RN17 quando o alimento for promovido para o plano).
- `slug` único, minúsculo, sem acento, até 80 caracteres.
- Os arquivos oficiais **não** são versionados; o CSV intermediário (`database/data/fontes/*.csv`) e o `foods.csv` final, sim.

## Review Focus

1. **Duplicados** — um alimento da TACO igual (nome normalizado) a um dos 62 ou a outro já importado não entra duas vezes; o IBGE só completa o que a TACO não tem. Teste em Task 1.
2. **Restrição faltando em alimento com leite, trigo, castanha ou fruto do mar** — "Pão, trigo, francês" liga glúten; "Bolo, pronto, chocolate" liga glúten e lactose; "Paçoca, amendoim" liga castanhas; "Camarão" liga frutos do mar, sem carne e sem animal. Teste em Task 1.
3. **Bebidas em ml** — leite fluido, sucos, refrigerantes, café, chá em `ml`; leite em pó em `g`. Teste em Task 1.
4. **Seeder com 700+ linhas** — continua idempotente e rápido o bastante para os testes (`seedCatalog()` roda em quase todo teste). Teste em Task 3.
5. **Busca com nomes longos da TACO** ("Arroz, tipo 1, cozido") — o sinônimo curto ("arroz") faz o item aparecer na busca por "arroz". Teste em Task 1.

---

### Task 1: Comando `catalog:import` com as regras de normalização

**Files:**
- Create: `app/Console/Commands/ImportCatalog.php`, `app/Services/Foods/CatalogRowMapper.php`
- Create: `tests/Fixtures/catalogo/taco-amostra.csv`, `tests/Fixtures/catalogo/ibge-amostra.csv`
- Test: `tests/Unit/Foods/CatalogRowMapperTest.php`, `tests/Feature/Catalog/ImportCatalogTest.php`

**Interfaces:**
- Consumes: formato intermediário — CSV com cabeçalho `name,category,kcal,protein,carbs,fat,source` (valores por 100 g da fonte; `category` = categoria da fonte, texto livre).
- Produces: `CatalogRowMapper::map(array $row): ?array` → linha no formato de `foods.csv` (`slug,name,aliases,group,kcal,protein,carbs,fat,portion_g,unit,unit_plural,unit_g,note,dislike,staple,source,restrictions,pantry,measure,in_plans`) ou `null` quando descartada; comando `php artisan catalog:import {arquivo} {--dry-run}` que acrescenta ao `database/data/foods.csv` só as linhas novas e imprime "N novos, M já existiam, K descartados".

- [ ] **Step 1: Amostras (fixtures)**

`tests/Fixtures/catalogo/taco-amostra.csv`:

```csv
name,category,kcal,protein,carbs,fat,source
"Arroz, tipo 1, cozido",Cereais e derivados,128,2.5,28.1,0.2,TACO 4ª ed.
"Pão, trigo, francês",Cereais e derivados,300,8.0,58.6,3.1,TACO 4ª ed.
"Bolo, pronto, chocolate",Cereais e derivados,410,6.2,54.7,18.5,TACO 4ª ed.
"Leite, de vaca, desnatado, UHT",Leite e derivados,34,3.4,4.5,Tr,TACO 4ª ed.
"Leite, de vaca, integral, pó",Leite e derivados,497,25.4,39.2,26.9,TACO 4ª ed.
"Suco de laranja, pera",Bebidas (alcoólicas e não alcoólicas),33,0.7,7.6,0.1,TACO 4ª ed.
"Camarão, Rio Grande, grande, cozido",Pescados e frutos do mar,90,19.0,0.0,1.0,TACO 4ª ed.
"Paçoca, amendoim",Produtos açucarados,487,16.0,52.4,26.1,TACO 4ª ed.
"Frango, peito, sem pele, grelhado",Carnes e derivados,159,32.0,0.0,2.5,TACO 4ª ed.
"Feijão, carioca, cozido",Leguminosas e derivados,76,4.8,13.6,0.5,TACO 4ª ed.
"Banana, prata, crua",Frutas e derivados,98,1.3,26.0,0.1,TACO 4ª ed.
"Alface, crespa, crua",Verduras hortaliças e derivados,11,1.3,1.7,0.2,TACO 4ª ed.
"Castanha-do-Brasil, crua",Nozes e sementes,643,14.5,15.1,63.5,TACO 4ª ed.
"Ovo, de galinha, inteiro, cozido/10minutos",Ovos e derivados,146,13.3,0.6,9.5,TACO 4ª ed.
"Sal, dietético",Miscelâneas,NA,NA,NA,NA,TACO 4ª ed.
"Arroz, tipo 1, cozido",Cereais e derivados,128,2.5,28.1,0.2,TACO 4ª ed.
```

`tests/Fixtures/catalogo/ibge-amostra.csv`:

```csv
name,category,kcal,protein,carbs,fat,source
"Arroz, tipo 1, cozido",Cereais,130,2.5,28.0,0.3,IBGE POF 2008-2009
"Cuscuz de milho, cozido com sal",Cereais,113,2.2,25.3,0.7,IBGE POF 2008-2009
"Tapioca com manteiga",Preparações,348,0.3,63.6,10.2,IBGE POF 2008-2009
```

- [ ] **Step 2: Testes que falham**

`tests/Unit/Foods/CatalogRowMapperTest.php`:

```php
<?php

use App\Services\Foods\CatalogRowMapper;

$linha = fn (string $nome, string $categoria, $kcal = '100', $p = '1', $c = '1', $g = '1') =>
    ['name' => $nome, 'category' => $categoria, 'kcal' => (string) $kcal, 'protein' => (string) $p, 'carbs' => (string) $c, 'fat' => (string) $g, 'source' => 'TACO 4ª ed.'];

it('grupo pela categoria da fonte', function (string $categoria, string $grupo) use ($linha) {
    expect((new CatalogRowMapper)->map($linha('X, y', $categoria))['group'])->toBe($grupo);
})->with([
    ['Cereais e derivados', 'carboidrato'], ['Verduras hortaliças e derivados', 'vegetal'], ['Frutas e derivados', 'fruta'],
    ['Gorduras e óleos', 'gordura'], ['Pescados e frutos do mar', 'proteina'], ['Carnes e derivados', 'proteina'],
    ['Leite e derivados', 'laticinio'], ['Bebidas (alcoólicas e não alcoólicas)', 'bebida'], ['Ovos e derivados', 'proteina'],
    ['Produtos açucarados', 'outros'], ['Miscelâneas', 'outros'], ['Leguminosas e derivados', 'leguminosa'],
    ['Nozes e sementes', 'gordura'], ['Alimentos preparados', 'outros'], ['Categoria nova', 'outros'],
]);

it('restrições conservadoras (Review Focus 2)', function (string $nome, string $categoria, array $esperadas) use ($linha) {
    $restricoes = explode('|', (new CatalogRowMapper)->map($linha($nome, $categoria))['restrictions']);
    expect(array_filter($restricoes))->toEqualCanonicalizing($esperadas);
})->with([
    ['Pão, trigo, francês', 'Cereais e derivados', ['gluten']],
    ['Bolo, pronto, chocolate', 'Cereais e derivados', ['gluten', 'lactose', 'sem-animal']],
    ['Paçoca, amendoim', 'Produtos açucarados', ['castanhas']],
    ['Castanha-do-Brasil, crua', 'Nozes e sementes', ['castanhas']],
    ['Camarão, cozido', 'Pescados e frutos do mar', ['frutos-do-mar', 'sem-carne', 'sem-animal']],
    ['Frango, peito, grelhado', 'Carnes e derivados', ['sem-carne', 'sem-animal']],
    ['Leite, de vaca, integral', 'Leite e derivados', ['lactose', 'sem-animal']],
    ['Ovo, de galinha, cozido', 'Ovos e derivados', ['sem-animal']],
    ['Banana, prata, crua', 'Frutas e derivados', []],
]);

it('medida em ml para bebidas e leite fluido; pó continua em g (Review Focus 3)', function (string $nome, string $categoria, string $medida) use ($linha) {
    expect((new CatalogRowMapper)->map($linha($nome, $categoria))['measure'])->toBe($medida);
})->with([
    ['Suco de laranja, pera', 'Bebidas (alcoólicas e não alcoólicas)', 'ml'],
    ['Leite, de vaca, desnatado, UHT', 'Leite e derivados', 'ml'],
    ['Leite, de vaca, integral, pó', 'Leite e derivados', 'g'],
    ['Iogurte, natural', 'Leite e derivados', 'g'],
    ['Café, infusão 10%', 'Bebidas (alcoólicas e não alcoólicas)', 'ml'],
]);

it('traço vira zero; sem kcal é descartado', function () use ($linha) {
    expect((new CatalogRowMapper)->map($linha('Leite, x', 'Leite e derivados', '34', '3.4', '4.5', 'Tr'))['fat'])->toBe('0')
        ->and((new CatalogRowMapper)->map($linha('Sal, dietético', 'Miscelâneas', 'NA', 'NA', 'NA', 'NA')))->toBeNull();
});

it('slug, sinônimo curto e porção por grupo (Review Focus 5)', function () use ($linha) {
    $r = (new CatalogRowMapper)->map($linha('Arroz, tipo 1, cozido', 'Cereais e derivados', '128', '2.5', '28.1', '0.2'));

    expect($r['slug'])->toBe('arroz-tipo-1-cozido')
        ->and($r['aliases'])->toBe('arroz')
        ->and($r['portion_g'])->toBe('100')
        ->and($r['in_plans'])->toBe('0')
        ->and($r['source'])->toBe('TACO 4ª ed.');
});
```

`tests/Feature/Catalog/ImportCatalogTest.php`:

```php
<?php

use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->original = File::get(database_path('data/foods.csv'));
});

afterEach(function () {
    File::put(database_path('data/foods.csv'), $this->original);
});

it('acrescenta só o que é novo e não mexe nos 62 (Review Focus 1)', function () {
    $antes = count(file(database_path('data/foods.csv')));

    $this->artisan('catalog:import', ['arquivo' => base_path('tests/Fixtures/catalogo/taco-amostra.csv')])
        ->expectsOutputToContain('novos')->assertSuccessful();
    $this->artisan('catalog:import', ['arquivo' => base_path('tests/Fixtures/catalogo/ibge-amostra.csv')])->assertSuccessful();

    $linhas = array_map('str_getcsv', file(database_path('data/foods.csv'), FILE_IGNORE_NEW_LINES));
    $slugs = array_column(array_slice($linhas, 1), 0);
    expect(count($slugs))->toBe(count(array_unique($slugs)))
        ->and(count($linhas))->toBe($antes + 14 + 2) // 15 da TACO − 1 sem kcal (o repetido cai pelo slug); 2 novos do IBGE
        ->and(str_starts_with(File::get(database_path('data/foods.csv')), explode("\n", $this->original)[0]))->toBeTrue();
});

it('--dry-run não grava', function () {
    $this->artisan('catalog:import', ['arquivo' => base_path('tests/Fixtures/catalogo/taco-amostra.csv'), '--dry-run' => true])->assertSuccessful();
    expect(File::get(database_path('data/foods.csv')))->toBe($this->original);
});
```

Confira a conta do `toBe($antes + 14 + 2)` com as amostras: a TACO tem 16 linhas, 1 sem kcal (sal) e 1 repetida (arroz) ⇒ 14 novas; das 3 do IBGE, o arroz já entrou pela TACO ⇒ 2 novas. Se algum nome da amostra coincidir com um dos 62 atuais (ex.: "Banana, prata, crua" × `banana`), a regra de duplicado compara **slug** e **nome normalizado**; "banana" ≠ "banana prata crua", então entra. Ajuste o número se a regra de duplicado da Step 3 decidir diferente — e registre o motivo.

- [ ] **Step 3: Ver falhar**

Run: `docker compose run --rm api php artisan test --compact tests/Unit/Foods/CatalogRowMapperTest.php tests/Feature/Catalog/ImportCatalogTest.php`
Expected: FAIL — classe e comando inexistentes.

- [ ] **Step 4: `CatalogRowMapper`**

```php
<?php

namespace App\Services\Foods;

use Illuminate\Support\Str;

/** Linha da fonte (TACO/IBGE, já no CSV intermediário) → linha de foods.csv (RN47, RN52). Puro e determinístico. */
final class CatalogRowMapper
{
    private const GROUPS = [
        'cereais' => 'carboidrato', 'verduras' => 'vegetal', 'hortali' => 'vegetal', 'frutas' => 'fruta',
        'gorduras' => 'gordura', 'pescados' => 'proteina', 'carnes' => 'proteina', 'leite' => 'laticinio',
        'bebidas' => 'bebida', 'ovos' => 'proteina', 'leguminosas' => 'leguminosa', 'nozes' => 'gordura',
    ];

    private const PORTION = [
        'carboidrato' => 100, 'proteina' => 100, 'leguminosa' => 80, 'laticinio' => 150, 'fruta' => 120,
        'vegetal' => 80, 'gordura' => 15, 'bebida' => 200, 'outros' => 50,
    ];

    private const GLUTEN = ['trigo', 'pao', 'macarrao', 'biscoito', 'bolo', 'torrada', 'pizza', 'lasanha', 'cevada', 'centeio', 'aveia', 'empada', 'pastel', 'coxinha', 'esfiha', 'farinha lactea', 'cerveja', 'nhoque', 'panqueca', 'croissant', 'bisnaguinha', 'rosca', 'sonho'];
    private const LACTOSE = ['leite', 'queijo', 'iogurte', 'requeijao', 'manteiga', 'creme de leite', 'nata', 'chantilly', 'sorvete', 'pudim', 'bolo', 'chocolate', 'achocolatado', 'pao de queijo', 'lasanha', 'pizza', 'coalhada', 'ricota', 'mussarela', 'muçarela', 'parmesao', 'catupiry', 'brigadeiro', 'doce de leite'];
    private const NUTS = ['amendoim', 'castanha', 'noz', 'nozes', 'amendoa', 'avela', 'pistache', 'macadamia', 'pacoca', 'pe de moleque', 'pinhao'];
    private const SEAFOOD = ['camarao', 'caranguejo', 'lagosta', 'marisco', 'mexilhao', 'ostra', 'lula', 'polvo', 'siri', 'sururu', 'vieira'];
    private const MEAT = ['carne', 'frango', 'boi', 'porco', 'peru', 'pato', 'linguica', 'salsicha', 'presunto', 'mortadela', 'salame', 'bacon', 'toucinho', 'peixe', 'atum', 'sardinha', 'bacalhau', 'tilapia', 'merluza', 'pescada', 'salmao', 'figado', 'coracao', 'moela', 'charque', 'hamburguer', 'almondega', 'kibe', 'coxinha', 'feijoada', 'estrogonofe'];
    private const ANIMAL_EXTRA = ['ovo', 'mel', 'gelatina', 'banha', 'manteiga'];
    private const LIQUID = ['suco', 'refrigerante', 'cafe', 'cha,', 'cha ', 'agua', 'bebida', 'caldo', 'vitamina', 'leite de coco', 'cerveja', 'vinho', 'cachaca', 'isotonico', 'energetico'];

    /** @param array{name: string, category: string, kcal: string, protein: string, carbs: string, fat: string, source: string} $row */
    public function map(array $row): ?array
    {
        $kcal = $this->number($row['kcal']);
        if ($kcal === null) {
            return null;
        }
        $name = trim($row['name']);
        $n = ' '.FoodFilter::normalize($name).' ';
        $category = FoodFilter::normalize($row['category']);
        $group = $this->group($category);
        $measure = $this->measure($n, $category);

        return [
            'slug' => Str::limit(Str::slug(FoodFilter::normalize($name)), 80, ''),
            'name' => $name,
            'aliases' => trim(Str::before(FoodFilter::normalize($name), ',')),
            'group' => $group,
            'kcal' => $this->format($kcal),
            'protein' => $this->format($this->number($row['protein']) ?? 0.0),
            'carbs' => $this->format($this->number($row['carbs']) ?? 0.0),
            'fat' => $this->format($this->number($row['fat']) ?? 0.0),
            'portion_g' => (string) ($measure === 'ml' ? 200 : self::PORTION[$group]),
            'unit' => '', 'unit_plural' => '', 'unit_g' => '', 'note' => '',
            'dislike' => '0', 'staple' => '0',
            'source' => trim($row['source']),
            'restrictions' => implode('|', $this->restrictions($n, $category)),
            'pantry' => '',
            'measure' => $measure,
            'in_plans' => '0',
        ];
    }

    private function group(string $category): string
    {
        foreach (self::GROUPS as $needle => $group) {
            if (str_contains($category, $needle)) {
                return $group;
            }
        }

        return 'outros';
    }

    private function measure(string $n, string $category): string
    {
        if (str_contains($n, ' po ') || str_contains($n, ' po,') || str_contains($n, 'em po')) {
            return 'g';
        }
        if (str_contains($category, 'bebidas')) {
            return 'ml';
        }
        if (preg_match('/^ leite, de (vaca|cabra)/', $n) === 1) {
            return 'ml';
        }
        foreach (self::LIQUID as $needle) {
            if (str_starts_with(ltrim($n), $needle)) {
                return 'ml';
            }
        }

        return 'g';
    }

    /** @return list<string> slugs de restrições (na dúvida, liga — RN17) */
    private function restrictions(string $n, string $category): array
    {
        $has = fn (array $words) => collect($words)->contains(fn (string $w) => str_contains($n, ' '.$w) || str_contains($n, $w.' ') || str_contains($n, $w.','));
        $r = [];
        if ($has(self::GLUTEN)) {
            $r[] = 'gluten';
        }
        $dairy = str_contains($category, 'leite') || $has(self::LACTOSE);
        if ($dairy && ! str_contains($n, 'sem lactose') && ! str_contains($n, 'leite de coco')) {
            $r[] = 'lactose';
        }
        if (str_contains($category, 'nozes') || $has(self::NUTS)) {
            $r[] = 'castanhas';
        }
        $seafood = $has(self::SEAFOOD);
        if ($seafood) {
            $r[] = 'frutos-do-mar';
        }
        $meat = $seafood || str_contains($category, 'carnes') || str_contains($category, 'pescados') || $has(self::MEAT);
        if ($meat) {
            $r[] = 'sem-carne';
        }
        if ($meat || $dairy || str_contains($category, 'ovos') || $has(self::ANIMAL_EXTRA)) {
            $r[] = 'sem-animal';
        }

        return $r;
    }

    private function number(string $value): ?float
    {
        $v = trim($value);
        if ($v === 'Tr' || $v === 'tr') {
            return 0.0;
        }

        return is_numeric(str_replace(',', '.', $v)) ? (float) str_replace(',', '.', $v) : null;
    }

    private function format(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.');
    }
}
```

Observação: "Bolo, pronto, chocolate" casa `bolo` (glúten), `bolo`/`chocolate` (lactose) e, por ter lactose, `sem-animal` — exatamente o esperado no teste. "Castanha-do-Brasil" casa `castanha` dentro de " castanha-do-brasil," porque o normalizado é "castanha-do-brasil, crua" (o hífen continua) — a categoria "nozes" garante de qualquer forma.

- [ ] **Step 5: Comando**

```php
<?php

namespace App\Console\Commands;

use App\Services\Foods\CatalogRowMapper;
use App\Services\Foods\FoodFilter;
use Illuminate\Console\Command;
use SplFileObject;

/** Acrescenta ao foods.csv os alimentos novos de uma fonte já normalizada (Plano 11B). */
class ImportCatalog extends Command
{
    protected $signature = 'catalog:import {arquivo : CSV intermediário (name,category,kcal,protein,carbs,fat,source)} {--dry-run : só conta}';

    protected $description = 'Acrescenta ao database/data/foods.csv os alimentos novos de uma fonte pública (TACO, IBGE).';

    public function handle(CatalogRowMapper $mapper): int
    {
        $destino = database_path('data/foods.csv');
        $existentes = $this->read($destino);
        $header = array_keys($existentes[0]);
        $slugs = array_flip(array_column($existentes, 'slug'));
        $nomes = array_flip(array_map(fn ($r) => FoodFilter::normalize($r['name']), $existentes));

        [$novos, $repetidos, $descartados] = [[], 0, 0];
        foreach ($this->read((string) $this->argument('arquivo')) as $row) {
            $linha = $mapper->map($row);
            if ($linha === null) {
                $descartados++;

                continue;
            }
            $nome = FoodFilter::normalize($linha['name']);
            if (isset($slugs[$linha['slug']]) || isset($nomes[$nome])) {
                $repetidos++;

                continue;
            }
            $slugs[$linha['slug']] = true;
            $nomes[$nome] = true;
            $novos[] = array_map(fn ($col) => $linha[$col] ?? '', $header);
        }

        if (! $this->option('dry-run') && $novos !== []) {
            $file = new SplFileObject($destino, 'a');
            foreach ($novos as $linha) {
                $file->fputcsv($linha);
            }
        }

        $this->info(count($novos).' novos, '.$repetidos.' já existiam, '.$descartados.' descartados.');

        return self::SUCCESS;
    }

    /** @return list<array<string, string>> */
    private function read(string $path): array
    {
        $file = new SplFileObject($path);
        $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::READ_AHEAD | SplFileObject::DROP_NEW_LINE);
        [$header, $rows] = [null, []];
        foreach ($file as $line) {
            if ($header === null) {
                $header = $line;

                continue;
            }
            $rows[] = array_combine($header, $line);
        }

        return $rows;
    }
}
```

Confira se o `foods.csv` termina com quebra de linha antes de anexar (`tail -c1 database/data/foods.csv | xxd`); se não terminar, o comando deve escrever `"\n"` antes da primeira linha nova.

- [ ] **Step 6: Ver passar**

Run: `docker compose run --rm api php artisan test --compact tests/Unit/Foods/CatalogRowMapperTest.php tests/Feature/Catalog/ImportCatalogTest.php`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Console/Commands/ImportCatalog.php app/Services/Foods/CatalogRowMapper.php tests/Fixtures/catalogo tests/Unit/Foods tests/Feature/Catalog/ImportCatalogTest.php
git commit -m "feat(catalogo): comando catalog:import com grupo, medida e restrições por regra (Plano 11B)"
```

---

### Task 2: Gerar os CSV intermediários a partir das tabelas oficiais

**Files:**
- Create: `database/data/fontes/README.md`, `database/data/fontes/taco.csv`, `database/data/fontes/ibge-pof.csv`
- Modify: `.gitignore` (ignora `database/data/fontes/originais/`)

- [ ] **Step 1: Baixar os arquivos oficiais (à mão) para `database/data/fontes/originais/`**

- TACO 4ª ed. revisada e ampliada (NEPA/UNICAMP, 2011): planilha `taco_4_edicao_ampliada_e_revisada.xls` em https://www.nepa.unicamp.br/publicacoes/tabela-taco-pdf/ (ou o espelho da UNICAMP).
- Tabelas de Composição Nutricional dos Alimentos Consumidos no Brasil (IBGE, POF 2008–2009): planilha em https://biblioteca.ibge.gov.br/ (publicação "Pesquisa de Orçamentos Familiares 2008-2009 — Tabelas de Composição Nutricional dos Alimentos Consumidos no Brasil").

Se o ambiente não tiver acesso à internet, **pare e peça** os arquivos aos autores — não preencha valores de memória (Global Constraints).

- [ ] **Step 2: Converter para o formato intermediário**

Abra a planilha (LibreOffice ou `ssconvert`/`in2csv`) e gere `taco.csv` com as colunas `name,category,kcal,protein,carbs,fat,source`:
- TACO: `name` = "Descrição dos alimentos"; `category` = o título de seção acima de cada bloco (ex.: "Cereais e derivados"); `kcal` = "Energia (kcal)"; `protein` = "Proteína (g)"; `carbs` = "Carboidrato (g)"; `fat` = "Lipídeos (g)"; `source` = `TACO 4ª ed.`. Mantenha "Tr", "NA" e "*" como estão (o mapper trata).
- IBGE: `name` = descrição do alimento + ", " + preparação quando a preparação não for "Não se aplica"; `category` = grupo/subgrupo da tabela; `kcal`, `protein`, `carbs` (carboidrato total), `fat` (lipídios totais); `source` = `IBGE POF 2008-2009`.

Registre em `database/data/fontes/README.md`: de onde veio cada arquivo (URL, data do download), as colunas usadas, e o comando de conversão. Commite os dois CSV intermediários (são dados públicos, citáveis no relatório); **não** commite as planilhas originais.

- [ ] **Step 3: Conferir uma amostra à mão**

Escolha 10 linhas de cada CSV (inclua arroz, feijão, frango, leite, banana) e compare com a planilha original. Anote no README "Conferido: 10 linhas TACO, 10 IBGE, em AAAA-MM-DD".

- [ ] **Step 4: Commit**

```bash
git add database/data/fontes .gitignore
git commit -m "chore(catalogo): TACO e IBGE/POF no formato intermediário, com origem documentada"
```

---

### Task 3: Importar, semear e verificar ≥ 700 (CA43)

**Files:**
- Modify: `database/data/foods.csv` (gerado pelo comando), `specs/99-inconsistencias.md` (P5), `specs/09-registro-alimentar/spec.md` (DoD)
- Test: `tests/Feature/Seeders/CatalogSizeTest.php`

- [ ] **Step 1: Teste que falha**

```php
<?php

use App\Models\Food;

it('catálogo tem ≥ 700 ativos, todos com medida, porção e fonte; os 62 de antes ficam no plano (CA43)', function () {
    seedCatalog();

    expect(Food::where('is_active', true)->count())->toBeGreaterThanOrEqual(700)
        ->and(Food::whereNotIn('measure', ['g', 'ml'])->count())->toBe(0)
        ->and(Food::where('typical_portion_g', '<=', 0)->count())->toBe(0)
        ->and(Food::where('source', '')->count())->toBe(0)
        ->and(Food::where('in_plans', true)->count())->toBe(62)
        ->and(Food::where('slug', 'arroz-branco-cozido')->value('in_plans'))->toBeTrue();
});

it('semear duas vezes não duplica (Review Focus 4)', function () {
    seedCatalog();
    $n = Food::count();
    seedCatalog();
    expect(Food::count())->toBe($n);
});
```

- [ ] **Step 2: Ver falhar**

Run: `docker compose run --rm api php artisan test --compact tests/Feature/Seeders/CatalogSizeTest.php`
Expected: FAIL — 62 < 700.

- [ ] **Step 3: Importar**

```bash
docker compose run --rm api php artisan catalog:import database/data/fontes/taco.csv --dry-run
docker compose run --rm api php artisan catalog:import database/data/fontes/taco.csv
docker compose run --rm api php artisan catalog:import database/data/fontes/ibge-pof.csv --dry-run
docker compose run --rm api php artisan catalog:import database/data/fontes/ibge-pof.csv
wc -l database/data/foods.csv   # ≥ 701 (cabeçalho + 700)
```

Se a soma ficar abaixo de 700, **não** invente linhas: amplie a conversão do IBGE (Task 2) para incluir mais preparações (ex.: "cozido(a)", "frito(a)", "assado(a)") e rode de novo. Se, mesmo assim, faltar, pare e informe os autores com o número alcançado.

- [ ] **Step 4: Tempo dos testes**

Run: `time docker compose run --rm api php artisan test --compact tests/Feature/Days`
Se o tempo subir mais de 50% por causa do `seedCatalog()`, troque em `FoodSeeder` o laço de `updateOrCreate` por `Food::upsert()` em lotes de 200 (chave `slug`) e sincronize `food_restriction`/`food_pantry_item` com `insertOrIgnore` em lote, apagando antes as ligações dos slugs do lote. Registre o tempo antes/depois no ledger.

- [ ] **Step 5: Ver passar e suíte**

Run: `docker compose run --rm api php artisan test --compact`
Expected: PASS (inclusive `FoodSearchTest` do 11A: a busca por "feijao" agora traz vários feijões; o primeiro começa com "Feijão").

- [ ] **Step 6: P5 e DoD**

Em `specs/99-inconsistencias.md`, P5, acrescente: "**Situação (Plano 11B):** catálogo com {N} itens (62 originais + TACO + IBGE/POF). Restrições dos novos ligadas por regra conservadora (`CatalogRowMapper`); conferir antes de promover algum para `in_plans`." Em `specs/09-registro-alimentar/spec.md`, marque `[x]` no item do catálogo.

- [ ] **Step 7: Commit**

```bash
git add database/data/foods.csv database/seeders/FoodSeeder.php specs tests/Feature/Seeders/CatalogSizeTest.php
git commit -m "feat(catalogo): {N} alimentos de TACO e IBGE/POF para o registro (CA43, RN52)"
```
