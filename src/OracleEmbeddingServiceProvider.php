<?php

namespace XLaravel\Embedding\Driver\Oracle;

use Illuminate\Support\ServiceProvider;
use XLaravel\Embedding\Contracts\PayloadStoreMetrics;
use XLaravel\Embedding\Contracts\VectorStore;
use XLaravel\Embedding\Contracts\VectorStoreMetrics;
use XLaravel\Embedding\IdSetManager;
use XLaravel\Embedding\Models\Embedding;
use XLaravel\Embedding\SimilarityManager;

class OracleEmbeddingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(VectorStore::class, OracleVectorStore::class);
        $this->app->bind(VectorStoreMetrics::class, OracleVectorStoreMetrics::class);
        $this->app->bind(PayloadStoreMetrics::class, OraclePayloadStoreMetrics::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'embedding-oracle-migrations');
        }

        $this->app->resolving(SimilarityManager::class, function (SimilarityManager $manager) {
            $manager->extend('oracle', fn () => new OracleDriver());
        });

        $this->app->resolving(IdSetManager::class, function (IdSetManager $manager) {
            $manager->extend('oracle', fn () => new OracleJsonIdSetBinder());
        });

        // Oracle read: oci8 cannot fetch the native VECTOR column once the
        // value spills into an out-of-line LOB (dimensions × 4 bytes past the
        // block size — e.g. 2560 × FLOAT32 ≈ 10 KB); the raw attribute comes
        // back as false and the array cast silently reads null. Reading the
        // serialized vector as a single CLOB is no better: oci8's
        // OCILob::load() drops chunks from temporary LOBs mid-stream and
        // returns a silently truncated string. And serializing it inside a
        // global scope makes every unrestricted query (coverage plucks,
        // status scans) pay the cost — plus yajra's rownum limit wrapper
        // re-quotes comma-separated raw selects into invalid SQL. So the
        // vector is hydrated lazily instead: when a retrieved model carries
        // an unreadable raw value, one PK-scoped query fetches the
        // serialized vector in 4000-char DBMS_LOB.SUBSTR slices — plain
        // VARCHAR2, never a LOB descriptor — stitched together in PHP.
        // Queries that never hydrate models (pluck, count) pay nothing, and
        // inline-stored vectors that oci8 can read directly skip the extra
        // query entirely.
        $model = config('embedding.model', Embedding::class);

        $model::retrieved(function ($embedding) {
            $attributes = $embedding->getAttributes();

            if (! array_key_exists('vector', $attributes)) {
                return;
            }

            $raw = $attributes['vector'];

            if ($raw === null || is_string($raw)) {
                return;
            }

            $dimensions = (int) config('embedding.dimensions', 1536);

            // Worst-case element width is ~20 chars ("-1.23456789E-002,");
            // over-allocating slices is harmless (empty pieces concat to '').
            $slices = (int) ceil(($dimensions * 20 + 2) / 4000);

            $columns = [];

            for ($i = 1; $i <= $slices; $i++) {
                $offset = ($i - 1) * 4000 + 1;
                $columns[] = "DBMS_LOB.SUBSTR(FROM_VECTOR(vector RETURNING CLOB), 4000, {$offset}) AS vector_p{$i}";
            }

            $row = $embedding->getConnection()->selectOne(
                'SELECT '.implode(', ', $columns)." FROM {$embedding->getTable()} WHERE {$embedding->getKeyName()} = ?",
                [$embedding->getKey()]
            );

            $vector = '';

            foreach ((array) ($row ?? []) as $piece) {
                $vector .= (string) $piece;
            }

            $attributes['vector'] = $vector === '' ? null : $vector;

            $embedding->setRawAttributes($attributes, true);
        });
    }
}
