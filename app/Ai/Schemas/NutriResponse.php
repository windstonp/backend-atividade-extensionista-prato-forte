<?php

namespace App\Ai\Schemas;

/** Contrato da resposta do chat (integracao-ia.md §4.2). Nunca lança: degrada para texto. */
final readonly class NutriResponse
{
    public const FALLBACK = 'Não consegui montar uma resposta agora. Pode perguntar de novo?';

    /**
     * @param  list<string>  $suggestions
     * @param  array<string, mixed>|null  $action
     */
    private function __construct(public string $reply, public ?string $followUp, public array $suggestions, public ?array $action) {}

    public static function parse(string $content): self
    {
        $json = self::decode($content);
        if ($json === null) {
            return new self(self::salvage($content), null, [], null);
        }

        $reply = is_string($json['reply'] ?? null) ? trim($json['reply']) : '';
        $suggestions = is_array($json['suggestions'] ?? null)
            ? array_values(array_filter($json['suggestions'], 'is_string'))
            : [];

        return new self(
            $reply === '' ? self::FALLBACK : $reply,
            is_string($json['follow_up'] ?? null) && trim($json['follow_up']) !== '' ? trim($json['follow_up']) : null,
            $suggestions,
            is_array($json['action'] ?? null) ? $json['action'] : null,
        );
    }

    /** Texto puro fica; JSON cortado no meio (limite de tokens) nunca aparece cru: salva o "reply" ou usa o padrão. */
    private static function salvage(string $content): string
    {
        $text = trim($content);
        if ($text === '') {
            return self::FALLBACK;
        }
        if (! str_starts_with($text, '{') && ! str_contains($text, '"reply"')) {
            return $text;
        }
        if (preg_match('/"reply"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"/u', $text, $m) === 1) {
            $reply = json_decode('"'.$m[1].'"');

            return is_string($reply) && trim($reply) !== '' ? trim($reply) : self::FALLBACK;
        }

        return self::FALLBACK;
    }

    /** @return array<string, mixed>|null */
    private static function decode(string $content): ?array
    {
        $start = strpos($content, '{');
        $end = strrpos($content, '}');
        if ($start === false || $end === false || $end < $start) {
            return null;
        }
        $json = json_decode(substr($content, $start, $end - $start + 1), true);

        return is_array($json) ? $json : null;
    }
}
