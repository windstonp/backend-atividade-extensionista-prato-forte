<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /** Dados de referência (todos os ambientes). A demonstração ganha o DemoSeeder no Plano 09. */
    public function run(): void
    {
        $this->call(CatalogSeeder::class);
    }
}
