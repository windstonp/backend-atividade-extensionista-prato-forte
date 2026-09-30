<?php

return [
    // Rodada da validação com a comunidade (RN41): uma resposta por pessoa por rodada.
    'rodada' => env('VALIDACAO_RODADA', '2026-1'),
    // Abertura da rodada: "Agora não" dado antes disso não vale para esta rodada.
    'inicio' => env('VALIDACAO_INICIO', '2026-09-01'),
];
