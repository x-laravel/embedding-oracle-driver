<?php

namespace XLaravel\Embedding\Driver\Oracle\Tests\Feature;

use XLaravel\Embedding\Driver\Oracle\OracleJsonIdSetBinder;
use XLaravel\Embedding\Driver\Oracle\Tests\TestCase;
use XLaravel\Embedding\IdSetManager;
use XLaravel\Embedding\Models\Embedding;
use XLaravel\Embedding\Driver\Oracle\Tests\Fixtures\Models\Post;

class OracleJsonIdSetBinderTest extends TestCase
{
    private function seedPosts(int $count): array
    {
        $ids = [];

        for ($i = 1; $i <= $count; $i++) {
            $post = Post::create(['title' => "Post {$i}", 'body' => 'body']);
            $ids[] = $post->id;
        }

        return $ids;
    }

    public function test_the_provider_registers_it_for_the_oracle_driver(): void
    {
        $this->assertInstanceOf(
            OracleJsonIdSetBinder::class,
            app(IdSetManager::class)->forConnection(config('embedding.database.connection')),
        );
    }

    public function test_it_reports_no_limit_so_callers_do_not_split_the_set(): void
    {
        $this->assertSame(0, (new OracleJsonIdSetBinder)->maxIdsPerQuery());
    }

    public function test_a_small_set_binds_inline(): void
    {
        $query = (new OracleJsonIdSetBinder)->apply(Embedding::query(), 'embeddable_id', range(1, 50));

        $this->assertCount(50, $query->getBindings());
        $this->assertStringNotContainsString('JSON_TABLE', $query->toSql());
    }

    public function test_a_large_set_travels_as_one_json_bind(): void
    {
        $query = (new OracleJsonIdSetBinder)->apply(Embedding::query(), 'embeddable_id', range(1, 1001));

        $this->assertCount(1, $query->getBindings());
        $this->assertStringContainsString('JSON_TABLE', $query->toSql());
    }

    public function test_both_modes_select_the_same_rows(): void
    {
        $ids = $this->seedPosts(5);
        $wanted = array_slice($ids, 0, 3);

        $binder = new OracleJsonIdSetBinder;

        $inline = $binder->apply(Embedding::query(), 'embeddable_id', $wanted)
            ->pluck('embeddable_id')->map(fn ($id) => (int) $id)->sort()->values()->all();

        /** Same set, forced through the JSON path by padding with absent IDs. */
        $padded = array_merge($wanted, range(900000, 900000 + 1000));

        $json = $binder->apply(Embedding::query(), 'embeddable_id', $padded)
            ->pluck('embeddable_id')->map(fn ($id) => (int) $id)->sort()->values()->all();

        $this->assertSame($inline, $json);
        $this->assertSame(array_map('intval', $wanted), $inline);
    }

    public function test_negating_returns_the_complement(): void
    {
        $ids = $this->seedPosts(4);
        $wanted = array_merge(array_slice($ids, 0, 2), range(900000, 900000 + 1000));

        $binder = new OracleJsonIdSetBinder;

        $selected = $binder->apply(Embedding::query(), 'embeddable_id', $wanted)->count();
        $rejected = $binder->apply(Embedding::query(), 'embeddable_id', $wanted, negate: true)->count();

        $this->assertSame(2, $selected);
        $this->assertSame(Embedding::count(), $selected + $rejected);
    }

    public function test_it_wraps_the_column_name(): void
    {
        $query = (new OracleJsonIdSetBinder)->apply(Embedding::query(), 'embeddable_id', range(1, 1001));

        $this->assertStringContainsString('"EMBEDDABLE_ID"', $query->toSql());
    }
}
