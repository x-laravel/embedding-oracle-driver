<?php

namespace XLaravel\Embedding\Driver\Oracle;

use Illuminate\Support\ServiceProvider;
use XLaravel\Embedding\Contracts\VectorStore;
use XLaravel\Embedding\Contracts\VectorStoreMetrics;
use XLaravel\Embedding\SimilarityManager;

class OracleEmbeddingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(VectorStore::class, OracleVectorStore::class);
        $this->app->bind(VectorStoreMetrics::class, OracleVectorStoreMetrics::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'embedding-oracle-migrations');
        }

        $this->app->resolving(SimilarityManager::class, function (SimilarityManager $manager) {
            $manager->extend('oracle', fn () => new OracleDriver());
        });
    }
}
