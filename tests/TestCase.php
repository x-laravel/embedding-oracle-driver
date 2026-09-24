<?php

namespace XLaravel\Embedding\Driver\Oracle\Tests;

use Laravel\Ai\Embeddings;
use Orchestra\Testbench\TestCase as Orchestra;
use XLaravel\Embedding\EmbeddingServiceProvider;
use XLaravel\Embedding\Driver\Oracle\OracleEmbeddingServiceProvider;

class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        Embeddings::fake();

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');
    }

    protected function getPackageProviders($app): array
    {
        return [
            \Laravel\Ai\AiServiceProvider::class,
            \Yajra\Oci8\Oci8ServiceProvider::class,
            EmbeddingServiceProvider::class,
            OracleEmbeddingServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'oracle');
        $app['config']->set('database.connections.oracle', [
            'driver' => 'oracle',
            'host' => env('DB_HOST', 'oracle'),
            'port' => env('DB_PORT', '1521'),
            'database' => env('DB_DATABASE', 'FREEPDB1'),
            'service_name' => env('DB_SERVICE_NAME', 'FREEPDB1'),
            'username' => env('DB_USERNAME', 'test_user'),
            'password' => env('DB_PASSWORD', 'test_password'),
            'charset' => 'AL32UTF8',
            'prefix' => '',
            'prefix_schema' => env('DB_SCHEMA', ''),
            'edition' => env('DB_EDITION', 'ora$base'),
            'server_version' => env('DB_SERVER_VERSION', '26c'),
            'load_balancing' => env('DB_LOAD_BALANCING', 'no'),
            'dynamic_report' => env('DB_DYNAMIC_REPORT', 'yes'),
        ]);

        $app['config']->set('ai.default', 'openai');
        $app['config']->set('ai.providers.openai', [
            'driver' => 'openai',
            'api_key' => 'fake-api-key-for-testing',
        ]);
        $app['config']->set('ai.default_for_embeddings', 'openai');

        $app['config']->set('embedding.database.connection', 'oracle');
        $app['config']->set('embedding.queue.connection', 'sync');
        $app['config']->set('embedding.similarity.driver', 'oracle');
    }
}
