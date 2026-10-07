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
