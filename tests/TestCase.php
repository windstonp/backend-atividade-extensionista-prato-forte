<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Com a config em cache (`artisan optimize` ou `config:cache`), o phpunit.xml é ignorado e o
     * RefreshDatabase apagaria o banco do .env — no servidor, o de produção. Para antes disso.
     */
    public function createApplication()
    {
        $app = parent::createApplication();

        if (! $app->environment('testing') || file_exists($app->getCachedConfigPath())) {
            throw new RuntimeException('Os testes não rodam com a config em cache nem fora de APP_ENV=testing: '
                .'usariam o banco do .env. Rode `php artisan config:clear` antes.');
        }

        return $app;
    }
}
