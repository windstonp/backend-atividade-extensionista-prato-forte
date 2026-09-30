<?php

namespace App\Services\Validation;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * RN42 — CSVs anonimizados para o relatório da atividade extensionista: sem nome, e-mail,
 * conteúdo de conversa, restrições nem data de nascimento. UTF-8 com BOM e ";" (abre no Excel pt-BR).
 */
final class ValidationExporter
{
    public function hash(int $userId): string
    {
        return substr(hash('sha256', $userId.config('app.key')), 0, 12);
    }

    /**
     * @return array{participantes: int, sus_medio: float|null, sus_desvio: float|null, up_nutri: float|null, up_plano: float|null, utilidade_media: float|null, dias_ativos_medios: float|null}
     */
    public function export(string $rodada, ?CarbonImmutable $de, ?CarbonImmutable $ate, string $dir): array
    {
        File::ensureDirectoryExists($dir);
        $noPeriodo = function ($query, string $coluna) use ($de, $ate) {
            return $query->when($de, fn ($q) => $q->where($coluna, '>=', $de->startOfDay()))
                ->when($ate, fn ($q) => $q->where($coluna, '<=', $ate->endOfDay()));
        };

        // usabilidade.csv
        $respostas = DB::table('usability_responses')->where('round', $rodada)->orderBy('id')->get();
        $this->write("{$dir}/usabilidade.csv", ['usuario_hash', 'rodada', 'q1', 'q2', 'q3', 'q4', 'q5', 'q6', 'q7', 'q8', 'q9', 'q10', 'sus', 'utilidade', 'ajudou', 'atrapalhou', 'respondido_em'],
            $respostas->map(fn ($r) => [$this->hash($r->user_id), $r->round, ...json_decode($r->sus_answers, true), $this->decimal((float) $r->sus_score), $r->usefulness, $r->liked, $r->disliked, $r->created_at]));

        // avaliacoes.csv
        $avaliacoes = $noPeriodo(DB::table('ratings'), 'created_at')->orderBy('id')->get();
        $this->write("{$dir}/avaliacoes.csv", ['usuario_hash', 'tipo', 'valor', 'comentario', 'criado_em'],
            $avaliacoes->map(fn ($a) => [$this->hash($a->user_id), $a->rateable_type === 'meal_plan' ? 'plano' : 'resposta_nutri', $a->value, $a->comment, $a->created_at]));

        // uso.csv — ativos no período: refeição marcada, pesagem ou pergunta ao Nutri
        $ativos = collect()
            ->merge($noPeriodo(DB::table('day_meals')->whereNotNull('done_at'), 'date')->pluck('user_id'))
            ->merge($noPeriodo(DB::table('weigh_ins'), 'date')->pluck('user_id'))
            ->merge($noPeriodo(DB::table('nutri_messages')->join('nutri_conversations', 'nutri_conversations.id', '=', 'nutri_messages.conversation_id')->where('nutri_messages.role', 'user'), 'nutri_messages.created_at')->pluck('nutri_conversations.user_id'))
            ->unique()->sort()->values();
        $diasAtivos = [];
        $linhasUso = $ativos->map(function (int $userId) use ($noPeriodo, &$diasAtivos) {
            $dias = $noPeriodo(DB::table('day_meals')->where('user_id', $userId), 'date')
                ->groupBy('date')->selectRaw('date, count(*) as total, sum(done_at is not null) as done')->get();
            $comMarcada = $dias->where('done', '>', 0)->count();
            $diasAtivos[] = $comMarcada;
            $itens = fn (string $fonte) => $noPeriodo(DB::table('day_meal_items')->join('day_meals', 'day_meals.id', '=', 'day_meal_items.day_meal_id')
                ->where('day_meals.user_id', $userId)->where('day_meal_items.source', $fonte), 'day_meals.date')->count();
            $pesos = $noPeriodo(DB::table('weigh_ins')->where('user_id', $userId), 'date')->orderBy('date')->pluck('weight_kg');
            $cadastro = DB::table('users')->where('id', $userId)->value('created_at');

            return [
                $this->hash($userId),
                DB::table('profiles')->where('user_id', $userId)->value('goal'),
                (int) CarbonImmutable::parse($cadastro)->diffInDays(now()),
                $comMarcada,
                (int) $dias->sum('done'),
                $dias->filter(fn ($d) => (int) $d->done === (int) $d->total)->count(),
                $itens('manual'),
                $itens('nutri'),
                $noPeriodo(DB::table('nutri_messages')->join('nutri_conversations', 'nutri_conversations.id', '=', 'nutri_messages.conversation_id')
                    ->where('nutri_conversations.user_id', $userId)->where('nutri_messages.role', 'user'), 'nutri_messages.created_at')->count(),
                DB::table('nutri_conversations')->where('user_id', $userId)->count(),
                DB::table('meal_plans')->where('user_id', $userId)->where('status', 'ready')->count(),
                DB::table('meal_plans')->where('user_id', $userId)->where('status', 'failed')->count(),
                $pesos->count(),
                $pesos->count() >= 2 ? $this->decimal(round((float) $pesos->last() - (float) $pesos->first(), 1)) : null,
            ];
        });
        $this->write("{$dir}/uso.csv", ['usuario_hash', 'objetivo', 'dias_desde_cadastro', 'dias_com_refeicao_marcada', 'refeicoes_feitas', 'dias_completos', 'trocas_manuais', 'trocas_nutri', 'perguntas_nutri', 'conversas', 'planos_gerados', 'planos_falhos', 'pesagens', 'variacao_peso_kg'], $linhasUso);

        // ia.csv — dia × propósito
        $ia = $noPeriodo(DB::table('ai_requests'), 'created_at')
            ->selectRaw("date(created_at) as dia, purpose, count(*) as chamadas, sum(status <> 'ok') as falhas, coalesce(sum(prompt_tokens), 0) as entrada, coalesce(sum(completion_tokens), 0) as saida, round(avg(duration_ms)) as latencia")
            ->groupByRaw('date(created_at), purpose')->orderBy('dia')->get();
        $this->write("{$dir}/ia.csv", ['dia', 'proposito', 'chamadas', 'falhas', 'tokens_entrada', 'tokens_saida', 'latencia_media_ms'],
            $ia->map(fn ($l) => [$l->dia, $l->purpose, $l->chamadas, $l->falhas, $l->entrada, $l->saida, $l->latencia]));

        $sus = $respostas->pluck('sus_score')->map(fn ($s) => (float) $s);
        $pct = fn ($lista) => $lista->isEmpty() ? null : round($lista->where('value', 'up')->count() / $lista->count() * 100, 1);

        return [
            'participantes' => $ativos->merge($respostas->pluck('user_id'))->unique()->count(),
            'sus_medio' => $sus->isEmpty() ? null : round($sus->avg(), 1),
            'sus_desvio' => $sus->count() < 2 ? null : round(sqrt($sus->map(fn ($s) => ($s - $sus->avg()) ** 2)->sum() / ($sus->count() - 1)), 1),
            'up_nutri' => $pct($avaliacoes->where('rateable_type', 'nutri_message')),
            'up_plano' => $pct($avaliacoes->where('rateable_type', 'meal_plan')),
            'utilidade_media' => $respostas->isEmpty() ? null : round($respostas->avg('usefulness'), 1),
            'dias_ativos_medios' => $diasAtivos === [] ? null : round(array_sum($diasAtivos) / count($diasAtivos), 1),
        ];
    }

    /**
     * @param  list<string>  $cabecalho
     * @param  iterable<array<int, mixed>>  $linhas
     */
    private function write(string $caminho, array $cabecalho, iterable $linhas): void
    {
        $h = fopen($caminho, 'w');
        fwrite($h, "\u{FEFF}");
        fputcsv($h, $cabecalho, ';', '"', '');
        foreach ($linhas as $linha) {
            fputcsv($h, array_map(fn ($v) => $v === null ? '' : (string) $v, $linha), ';', '"', '');
        }
        fclose($h);
    }

    private function decimal(float $n): string
    {
        return number_format($n, 1, ',', '');
    }
}
