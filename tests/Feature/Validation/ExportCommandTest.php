<?php

use App\Models\User;
use App\Services\Validation\ValidationExporter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    seedCatalog();
    config(['validacao.rodada' => '2026-1']);
    $this->travelTo(CarbonImmutable::parse('2026-10-04 10:00', 'America/Sao_Paulo'));
    $this->dir = storage_path('framework/testing/validacao-'.uniqid());
});

afterEach(fn () => File::deleteDirectory($this->dir));

/** Lê um CSV do exportador: tira o BOM e separa por ";" respeitando aspas. */
function lerCsv(string $caminho): array
{
    $conteudo = file_get_contents($caminho);
    expect(str_starts_with($conteudo, "\u{FEFF}"))->toBeTrue();
    $linhas = [];
    $h = fopen('php://memory', 'r+');
    fwrite($h, substr($conteudo, 3));
    rewind($h);
    while (($linha = fgetcsv($h, null, ';', '"', '')) !== false) {
        $linhas[] = $linha;
    }

    return $linhas;
}

it('gera os 4 CSVs anonimizados e o mesmo hash em todos (CA07)', function () {
    $camila = User::factory()->onboarded()->create(['name' => 'Camila Réus', 'email' => 'camila@exemplo.com']);
    $plano = planoPronto($camila);
    $conversa = $camila->conversations()->create(['title' => 'Segredo da Camila']);
    $conversa->messages()->create(['role' => 'user', 'content' => 'Texto privado da conversa']);
    $resposta = $conversa->messages()->create(['role' => 'assistant', 'content' => 'Resposta privada']);
    DB::table('ratings')->insert([
        ['user_id' => $camila->id, 'rateable_type' => 'nutri_message', 'rateable_id' => $resposta->id, 'value' => 'up', 'comment' => null, 'created_at' => now(), 'updated_at' => now()],
        ['user_id' => $camila->id, 'rateable_type' => 'meal_plan', 'rateable_id' => $plano->id, 'value' => 'down', 'comment' => "Faltou; \"arroz\"\nno jantar", 'created_at' => now(), 'updated_at' => now()],
    ]);
    DB::table('usability_responses')->insert(['user_id' => $camila->id, 'round' => '2026-1', 'sus_answers' => '[4,2,5,1,4,2,5,1,4,2]', 'sus_score' => 85, 'usefulness' => 4, 'liked' => 'Horários', 'disliked' => null, 'created_at' => now()]);
    $camila->weighIns()->create(['date' => '2026-09-20', 'weight_kg' => 58.0]);
    $camila->weighIns()->create(['date' => '2026-10-03', 'weight_kg' => 58.6]);

    $this->artisan('validacao:exportar', ['--dir' => $this->dir])
        ->expectsOutputToContain('Participantes: 1')
        ->expectsOutputToContain('SUS médio: 85,0')
        ->assertSuccessful();

    $hash = app(ValidationExporter::class)->hash($camila->id);
    $todos = '';
    foreach (['usabilidade', 'avaliacoes', 'uso', 'ia'] as $arquivo) {
        expect(file_exists("{$this->dir}/{$arquivo}.csv"))->toBeTrue();
        $todos .= file_get_contents("{$this->dir}/{$arquivo}.csv");
    }
    expect($todos)->not->toContain('Camila')->not->toContain('camila@exemplo.com')->not->toContain('Texto privado')->not->toContain('Resposta privada')->not->toContain('Segredo da');

    $usabilidade = lerCsv("{$this->dir}/usabilidade.csv");
    expect($usabilidade[0])->toBe(['usuario_hash', 'rodada', 'q1', 'q2', 'q3', 'q4', 'q5', 'q6', 'q7', 'q8', 'q9', 'q10', 'sus', 'utilidade', 'ajudou', 'atrapalhou', 'respondido_em'])
        ->and($usabilidade[1][0])->toBe($hash)->and($usabilidade[1][12])->toBe('85,0');

    $avaliacoes = lerCsv("{$this->dir}/avaliacoes.csv");
    expect($avaliacoes[0])->toBe(['usuario_hash', 'tipo', 'valor', 'comentario', 'criado_em'])
        ->and(collect($avaliacoes)->skip(1)->pluck(0)->unique()->all())->toBe([$hash])
        ->and(collect($avaliacoes)->firstWhere(1, 'plano'))->toBe([$hash, 'plano', 'down', "Faltou; \"arroz\"\nno jantar", now()->toDateTimeString()]);

    $uso = lerCsv("{$this->dir}/uso.csv");
    expect($uso[0])->toBe(['usuario_hash', 'objetivo', 'dias_desde_cadastro', 'dias_com_refeicao_marcada', 'refeicoes_feitas', 'dias_completos', 'trocas_manuais', 'trocas_nutri', 'perguntas_nutri', 'conversas', 'planos_gerados', 'planos_falhos', 'pesagens', 'variacao_peso_kg'])
        ->and($uso[1][0])->toBe($hash)->and($uso[1][8])->toBe('1')->and($uso[1][12])->toBe('2')->and($uso[1][13])->toBe('0,6');

    expect(lerCsv("{$this->dir}/ia.csv")[0])->toBe(['dia', 'proposito', 'chamadas', 'falhas', 'tokens_entrada', 'tokens_saida', 'latencia_media_ms']);
});

it('sem nenhum dado: cabeçalhos e "Participantes: 0", sem erro', function () {
    $this->artisan('validacao:exportar', ['--dir' => $this->dir])->expectsOutputToContain('Participantes: 0')->assertSuccessful();

    expect(lerCsv("{$this->dir}/usabilidade.csv"))->toHaveCount(1);
});

it('o hash é estável e não é o id', function () {
    $exporter = app(ValidationExporter::class);

    expect($exporter->hash(7))->toBe($exporter->hash(7))->toHaveLength(12)->not->toBe($exporter->hash(8));
});

it('filtra o uso por data (--de/--ate)', function () {
    $user = User::factory()->onboarded()->create();
    $user->weighIns()->create(['date' => '2026-08-01', 'weight_kg' => 60.0]);

    $this->artisan('validacao:exportar', ['--dir' => $this->dir, '--de' => '2026-09-01', '--ate' => '2026-10-04'])->assertSuccessful();

    expect(lerCsv("{$this->dir}/uso.csv"))->toHaveCount(1); // ninguém ativo no período
});

it('sem --de, a rodada vigente começa em validacao.inicio (não mistura rodadas antigas)', function () {
    config(['validacao.inicio' => '2026-10-01']);
    $user = User::factory()->onboarded()->create();
    $plano = planoPronto($user);
    DB::table('ratings')->insert([
        ['user_id' => $user->id, 'rateable_type' => 'meal_plan', 'rateable_id' => $plano->id, 'value' => 'up', 'comment' => null, 'created_at' => '2026-09-15 10:00:00', 'updated_at' => '2026-09-15 10:00:00'],
    ]);

    $this->artisan('validacao:exportar', ['--dir' => $this->dir])->assertSuccessful();

    expect(lerCsv("{$this->dir}/avaliacoes.csv"))->toHaveCount(1);
});

it('rodada antiga sem --de: pede o período em vez de exportar tudo', function () {
    $this->artisan('validacao:exportar', ['--dir' => $this->dir, '--rodada' => '2025-2'])->assertFailed();
});

it('texto livre que começa com =, +, - ou @ não vira fórmula no Excel', function () {
    $user = User::factory()->onboarded()->create();
    $plano = planoPronto($user);
    DB::table('ratings')->insert(['user_id' => $user->id, 'rateable_type' => 'meal_plan', 'rateable_id' => $plano->id, 'value' => 'down', 'comment' => '- faltou arroz', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('usability_responses')->insert(['user_id' => $user->id, 'round' => '2026-1', 'sus_answers' => '[3,3,3,3,3,3,3,3,3,3]', 'sus_score' => 50, 'usefulness' => 3, 'liked' => '=1+1', 'disliked' => '@cmd', 'created_at' => now()]);

    $this->artisan('validacao:exportar', ['--dir' => $this->dir])->assertSuccessful();

    expect(lerCsv("{$this->dir}/avaliacoes.csv")[1][3])->toBe("'- faltou arroz")
        ->and(array_slice(lerCsv("{$this->dir}/usabilidade.csv")[1], 14, 2))->toBe(["'=1+1", "'@cmd"]);
});

it('quem só avaliou também conta como participante', function () {
    $user = User::factory()->onboarded()->create();
    $plano = planoPronto($user);
    DB::table('ratings')->insert(['user_id' => $user->id, 'rateable_type' => 'meal_plan', 'rateable_id' => $plano->id, 'value' => 'up', 'comment' => null, 'created_at' => now(), 'updated_at' => now()]);

    $this->artisan('validacao:exportar', ['--dir' => $this->dir])->expectsOutputToContain('Participantes: 1')->assertSuccessful();
});
