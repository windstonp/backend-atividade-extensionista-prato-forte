<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /** Dados de referência (todos os ambientes) e, fora de produção, a demonstração. */
    public function run(): void
    {
        $this->call(CatalogSeeder::class);
        if (app()->environment(['local', 'staging'])) {
            $this->call(DemoSeeder::class);
        }
    }
}
