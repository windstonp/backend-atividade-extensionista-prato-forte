<?php

return [
    // Endereço do front: links de e-mail apontam para cá.
    'frontend_url' => env('FRONTEND_URL', 'http://localhost:3000'),

    // Versão vigente do termo de uso de dados (RN03). Mudou o texto do termo? Mude aqui e no front.
    'terms_version' => '2026-10',

    // Parceiro do projeto de extensão, mostrado no Perfil (constante do projeto, não do usuário).
    'parceiro' => [
        'gym' => 'Zfit',
        'city' => 'Capivari de Baixo',
    ],
];
