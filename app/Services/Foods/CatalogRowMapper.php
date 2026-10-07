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

    /**
     * @param  array{name: string, category: string, kcal: string, protein: string, carbs: string, fat: string, source: string}  $row
     * @return array<string, string>|null linha de foods.csv; null = descartada (sem kcal)
     */
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
