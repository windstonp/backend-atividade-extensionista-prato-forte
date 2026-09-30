<?php

namespace App\Console\Commands;

use App\Services\Validation\ValidationExporter;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/** RF33/RN42 — `php artisan validacao:exportar --rodada=2026-1`. */
class ExportValidationData extends Command
{
    protected $signature = 'validacao:exportar {--rodada=} {--de=} {--ate=} {--dir=}';

    protected $description = 'Exporta os dados anonimizados da validação com a comunidade (CSVs)';

    public function handle(ValidationExporter $exporter): int
    {
        $rodada = (string) ($this->option('rodada') ?: config('validacao.rodada'));
        $dir = (string) ($this->option('dir') ?: storage_path("app/validacao/{$rodada}"));
        $data = fn (?string $valor) => $valor ? CarbonImmutable::parse($valor) : null;
        $de = $data($this->option('de'));
        // Padrão: a rodada toda. Só a vigente tem início conhecido (VALIDACAO_INICIO); para outra, peça o período.
        if ($de === null) {
            if ($rodada !== (string) config('validacao.rodada')) {
                $this->error("Para a rodada {$rodada}, informe o período com --de (e, se quiser, --ate).");

                return self::FAILURE;
            }
            $de = CarbonImmutable::parse((string) config('validacao.inicio'));
        }
        $r = $exporter->export($rodada, $de, $data($this->option('ate')), $dir);
        $n = fn (?float $v, string $sufixo = '') => $v === null ? '—' : number_format($v, 1, ',', '.').$sufixo;

        $this->info("Arquivos em {$dir}");
        $this->line("Participantes: {$r['participantes']}");
        $this->line('SUS médio: '.$n($r['sus_medio']).' (desvio '.$n($r['sus_desvio']).')');
        $this->line('👍 no Nutri: '.$n($r['up_nutri'], '%').'; no plano: '.$n($r['up_plano'], '%'));
        $this->line('Utilidade média: '.$n($r['utilidade_media']));
        $this->line('Dias ativos em média: '.$n($r['dias_ativos_medios']));

        return self::SUCCESS;
    }
}
