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
