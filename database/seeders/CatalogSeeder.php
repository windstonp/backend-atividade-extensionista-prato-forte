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
