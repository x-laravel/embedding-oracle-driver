# x-laravel/embedding — Oracle 26ai Driver

[![Tests](https://github.com/x-laravel/embedding-oracle-driver/actions/workflows/tests.yml/badge.svg)](https://github.com/x-laravel/embedding-oracle-driver/actions/workflows/tests.yml)
[![PHP](https://img.shields.io/badge/PHP-8.3%2B-blue)](https://www.php.net)
[![Laravel](https://img.shields.io/badge/Laravel-12%20|%2013-red)](https://laravel.com)
[![License](https://img.shields.io/badge/license-MIT-green)](LICENSE.md)

Oracle 26ai native vector similarity driver for [x-laravel/embedding](https://github.com/x-laravel/embedding).

## How It Works

- Implements `SimilarityDriver` — registers as the `oracle` driver, similarity search runs entirely in Oracle using `VECTOR_DISTANCE`
- Implements `VectorStore` — writes embeddings via `MERGE INTO ... USING DUAL` with `TO_VECTOR()`, no PHP-side JSON workaround needed
- Translates payload `filter:` constraints to type-strict `JSON_VALUE` SQL against the `embeddables` table

## Requirements

- PHP ^8.3
- Laravel ^12.0 | ^13.0
- `x-laravel/embedding ^1.0`
- Oracle Database 26ai (Free or Enterprise)

## Installation

```bash
composer require x-laravel/embedding-oracle-driver
```

The `OracleEmbeddingServiceProvider` is auto-discovered and registers the `oracle` driver automatically.

## Setup

### 1. Configure x-laravel/embedding

Publish the config if you haven't already:

```bash
php artisan vendor:publish --tag=embedding-config
```

Set the similarity driver and database connection in `config/embedding.php`:

```php
'database' => [
    'connection' => env('EMBEDDINGS_DATABASE_CONNECTION', 'oracle'),
    'embeddings_table' => env('EMBEDDINGS_DB_TABLE', 'embeddings'),
    'embeddables_table' => env('EMBEDDABLES_DB_TABLE', 'embeddables'),
],

'similarity' => [
    'driver' => env('EMBEDDING_SIMILARITY_DRIVER', 'oracle'),
],
```

### 2. Create the tables

This driver ships its own Oracle-native migrations that **replace** the default ones from `x-laravel/embedding`: `embeddings` with a `VECTOR(1536, FLOAT32)` column and `embeddables` with a native `JSON` payload column.

Publish and run the migrations (migrations are not loaded automatically — publish the driver migrations, **not** the core ones):

```bash
php artisan vendor:publish --tag=embedding-oracle-migrations
php artisan migrate
```

The published files are plain migrations in `database/migrations/` — customise the DDL there if needed (e.g. tablespace, index parameters) before running `migrate`.

> **Note:** `VECTOR_DISTANCE` works without an index (sequential scan). If you have the Oracle In-Memory option, add a `CREATE VECTOR INDEX ... ORGANIZATION INMEMORY NEIGHBOR GRAPH` statement after publishing the migration.

### 3. Model

Follow the standard `x-laravel/embedding` setup. No Oracle-specific changes are needed on your models.

```php
use XLaravel\Embedding\Attributes\EmbedOn;
use XLaravel\Embedding\Concerns\Embeddable;
use XLaravel\Embedding\Contracts\HasEmbeddings;

#[EmbedOn('title', 'body')]
class Post extends Model implements HasEmbeddings
{
    use Embeddable;

    public function toEmbeddingText(string $slot = 'default'): string
    {
        return $this->title.' '.$this->body;
    }
}
```

## Usage

The driver is transparent — use the standard `x-laravel/embedding` API:

```php
Post::similarToText('web framework', limit: 10);
Post::similarTo($vector, limit: 10, threshold: 0.8);
Post::rankByRelevance($posts, 'web framework');

$post->mostSimilar(limit: 5);
$post->similarityTo($otherPost);
```

All methods set a `similarity_score` float attribute on each returned model.

### Payload filtering

Models using `#[EmbedPayload]` can filter similarity searches at the database level. The driver translates `filter:` to a `whereExists` subquery with `JSON_VALUE` — numbers via `RETURNING NUMBER`, plus a JSON type guard on every condition, so comparisons are type-strict (`34` never matches `"34"`):

```php
use XLaravel\Embedding\Attributes\EmbedPayload;

#[EmbedOn('name')]
#[EmbedPayload(['province_id', 'category_id', 'active'])]
class Venue extends Model implements HasEmbeddings { ... }

Venue::similarTo($vector, limit: 300, filter: ['province_id' => 34]);            // equality
Venue::similarToText('kebap', filter: ['category_id' => [3, 7]]);                // IN
$venue->mostSimilar(limit: 5, filter: ['province_id' => 34, 'active' => true]);  // AND
```

> **Note:** oci8 returns Oracle `NUMBER` columns to PHP as strings. Add an `integer` cast to numeric payload fields on your models — otherwise a queued payload sync writes `"34"` instead of `34` after the DB round-trip, and type-strict filters stop matching.

## Testing

```bash
# Build first (once per PHP version)
DOCKER_BUILDKIT=0 docker compose --profile php83 build

# Run tests
docker compose --profile php83 up
docker compose --profile php84 up
docker compose --profile php85 up
```

## License

This package is open-sourced software licensed under the [MIT license](https://opensource.org/license/MIT).
