# x-laravel/embedding — Oracle 26ai Driver

[![Tests](https://github.com/x-laravel/embedding-oracle-driver/actions/workflows/tests.yml/badge.svg)](https://github.com/x-laravel/embedding-oracle-driver/actions/workflows/tests.yml)
[![PHP](https://img.shields.io/badge/PHP-8.3%2B-blue)](https://www.php.net)
[![Laravel](https://img.shields.io/badge/Laravel-12%20|%2013-red)](https://laravel.com)
[![License](https://img.shields.io/badge/license-MIT-green)](LICENSE.md)

Oracle 26ai native vector similarity driver for [x-laravel/embedding](https://github.com/x-laravel/embedding).

## How It Works

- Implements `SimilarityDriver` — registers as the `oracle` driver, similarity search runs entirely in Oracle using `VECTOR_DISTANCE`
- Implements `VectorStore` — writes embeddings via `MERGE INTO ... USING DUAL` with `TO_VECTOR()`, no PHP-side JSON workaround needed

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
    'table'      => env('EMBEDDINGS_DB_TABLE', 'embeddings'),
],

'similarity' => [
    'driver' => env('EMBEDDING_SIMILARITY_DRIVER', 'oracle'),
],
```

### 2. Create the embeddings table

This driver ships its own Oracle-native migration that **replaces** the default one from `x-laravel/embedding`. It creates a `VECTOR(1536, FLOAT32)` column.

Run the migration:

```bash
php artisan migrate
```

If you need to customise the DDL (e.g. tablespace, index parameters), publish the migration first:

```bash
php artisan vendor:publish --tag=embedding-oracle-migrations
php artisan migrate
```

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

    public function toEmbeddingText(): string
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
