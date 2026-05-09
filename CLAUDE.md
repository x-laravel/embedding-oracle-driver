# CLAUDE.md — embedding-oracle-driver

This file provides guidance to Claude Code (claude.ai/code) when working with this repository.

## Overview

Oracle 26ai native vector driver for `x-laravel/embedding`. Handles both similarity search and vector storage using Oracle's native VECTOR type.

- **Package name:** `x-laravel/embedding-oracle-driver` — **Namespace:** `XLaravel\Embedding\Driver\Oracle`
- PHP `^8.3`, Laravel (illuminate) `^12.0|^13.0`, `x-laravel/embedding ^1.2`
- Oracle Database 26ai (Free or Enterprise)
- Dev: Orchestra Testbench `^10.0|^11.0`, PHPUnit `^11.0|^12.0`, yajra/laravel-oci8 `^12.0`

## Running Tests

```bash
# Build once per PHP version
DOCKER_BUILDKIT=0 docker compose --profile php83 build

# Run all tests
docker compose --profile php83 up   # PHP 8.3
docker compose --profile php84 up   # PHP 8.4
docker compose --profile php85 up   # PHP 8.5

# Run a single test class or method
docker compose --profile php83 run --rm php83 vendor/bin/phpunit --filter OracleDriverTest
docker compose --profile php83 run --rm php83 vendor/bin/phpunit --filter test_identical_vector_returns_score_of_one
```

Tests require a live Oracle 26ai instance — the `oracle` service in `docker-compose.yml` provides it. CI runs PHP 8.3–8.5 via `.github/workflows/tests.yml`.

## Source Files (`src/`)

| File | Responsibility |
|------|----------------|
| `OracleDriver.php` | Implements `SimilarityDriver`. Builds `VECTOR_DISTANCE(vector, TO_VECTOR(...), COSINE)` query, loads models via `findMany()`, sets `similarity_score` on each. |
| `OracleVectorStore.php` | Implements `VectorStore`. Writes embeddings via `MERGE INTO ... USING DUAL` with `TO_VECTOR(?, {dimensions}, FLOAT32)`. |
| `OracleVectorStoreMetrics.php` | Implements `VectorStoreMetrics`. Returns `Embedding::count()` for `rows`; aggregates `user_segments` for the byte fields, splitting `TABLE` vs. `INDEX%` segments into `data_bytes` / `index_bytes`. LOB segments are excluded (their `segment_name` is synthetic). Falls back to `null` byte fields if the user lacks `SELECT` on `user_segments`. |
| `OracleEmbeddingServiceProvider.php` | `register()` binds `VectorStore` → `OracleVectorStore` and `VectorStoreMetrics` → `OracleVectorStoreMetrics`. `boot()` registers `oracle` similarity driver, loads migration, publishes under `embedding-oracle-migrations` tag. |

## Test Structure (`tests/`)

| Path | Purpose |
|------|---------|
| `TestCase.php` | Base test case. Boots `EmbeddingServiceProvider` + `OracleEmbeddingServiceProvider`, sets up Oracle connection from env vars, calls `Embeddings::fake()`. |
| `Models/Post.php` | Fixture model using `#[EmbedOn]` and `Embeddable` trait. |
| `database/migrations/` | Creates `posts` table for tests. |
| `Feature/OracleDriverTest.php` | Tests similarity search: sort order, threshold, limit, `where` filter, empty results. Uses `setVector()` helper to write known vectors via `TO_VECTOR()`. |
| `Feature/OracleEmbeddingServiceProviderTest.php` | Tests driver registration, `VectorStore` binding, and default driver resolution. |

## Driver Lifecycle

```
register()
  ├─► app->bind(VectorStore::class, OracleVectorStore::class)
  └─► app->bind(VectorStoreMetrics::class, OracleVectorStoreMetrics::class)

boot()
  ├─► loadMigrationsFrom(...)
  ├─► publishes([...], 'embedding-oracle-migrations')
  └─► SimilarityManager::extend('oracle', fn() => new OracleDriver())
```

`VectorStore` must be bound in `register()` — before `EmbeddingGenerator` is first resolved by the container.

## Key Design Decisions

**Distance → similarity:** Oracle returns cosine distance (0 = identical). Driver converts: `similarity = 1 - distance`. Threshold translated to `maxDistance = 1.0 - threshold`, applied as `WHERE VECTOR_DISTANCE(...) <= maxDistance`.

**Upsert:** Oracle has no `ON DUPLICATE KEY UPDATE`. `OracleVectorStore` uses `MERGE INTO ... USING DUAL`.

**`TO_VECTOR` dimensions:** The `{$dimensions}` value is interpolated into SQL as an integer literal — `TO_VECTOR()` requires a literal, not a `?` binding. Value comes from `config('embedding.dimensions', 1536)` and is cast to `int` before interpolation.

**Migration:** Uses raw DDL (`DB::statement`) because Eloquent's Blueprint does not support Oracle's `VECTOR(n, FLOAT32)` syntax. Connection resolved from `config('embedding.database.connection')` via `getConnection()`.

## Migration

Publish and run the Oracle migration **instead of** the core `embedding-migrations`:

```bash
composer require x-laravel/embedding-oracle-driver
php artisan vendor:publish --tag=embedding-oracle-migrations
php artisan migrate
```

## Git Commits

Never create a commit unless the user explicitly requests it.
