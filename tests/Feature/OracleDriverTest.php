<?php

namespace XLaravel\Embedding\Driver\Oracle\Tests\Feature;

use Illuminate\Database\Eloquent\Collection;
use XLaravel\Embedding\Driver\Oracle\Tests\Fixtures\Models\Post;
use XLaravel\Embedding\Driver\Oracle\Tests\TestCase;

class OracleDriverTest extends TestCase
{
    public function test_it_returns_sorted_results_using_oracle_vector_distance(): void
    {
        $post1 = Post::create(['title' => 'Laravel', 'body' => 'PHP Framework']);
        $post2 = Post::create(['title' => 'Django', 'body' => 'Python Framework']);

        $v1 = $this->padVector([1.0, 0.0, 0.0]);
        $v2 = $this->padVector([0.0, 1.0, 0.0]);

        $this->setVector($post1, $v1);
        $this->setVector($post2, $v2);

        $results = Post::similarTo($v1, limit: 2);

        $this->assertCount(2, $results);
        $this->assertEquals($post1->id, $results->first()->id);
        $this->assertGreaterThan(0.9, $results->first()->similarity_score);
    }

    public function test_it_returns_model_instances_not_embedding_records(): void
    {
        $post = Post::create(['title' => 'Laravel', 'body' => 'PHP Framework']);
        $v = $this->padVector([1.0, 0.0, 0.0]);
        $this->setVector($post, $v);

        $results = Post::similarTo($v, limit: 1);

        $this->assertInstanceOf(Post::class, $results->first());
    }

    public function test_it_returns_collection(): void
    {
        $post = Post::create(['title' => 'Laravel', 'body' => 'PHP Framework']);
        $v = $this->padVector([1.0, 0.0, 0.0]);
        $this->setVector($post, $v);

        $results = Post::similarTo($v, limit: 10);

        $this->assertInstanceOf(Collection::class, $results);
    }

    public function test_similarity_score_is_returned_as_float(): void
    {
        $post = Post::create(['title' => 'Laravel', 'body' => 'PHP Framework']);
        $v = $this->padVector([1.0, 0.0, 0.0]);
        $this->setVector($post, $v);

        $results = Post::similarTo($v, limit: 1);

        $this->assertIsFloat($results->first()->similarity_score);
    }

    public function test_identical_vector_returns_score_of_one(): void
    {
        $post = Post::create(['title' => 'Laravel', 'body' => 'PHP Framework']);
        $v = $this->padVector([1.0, 0.0, 0.0]);
        $this->setVector($post, $v);

        $results = Post::similarTo($v, limit: 1);

        $this->assertEqualsWithDelta(1.0, $results->first()->similarity_score, 0.0001);
    }

    public function test_it_respects_threshold(): void
    {
        $post1 = Post::create(['title' => 'Laravel', 'body' => 'PHP Framework']);
        $post2 = Post::create(['title' => 'Django', 'body' => 'Python Framework']);

        $v1 = $this->padVector([1.0, 0.0, 0.0]);
        $v2 = $this->padVector([0.0, 1.0, 0.0]);

        $this->setVector($post1, $v1);
        $this->setVector($post2, $v2);

        $results = Post::similarTo($v1, limit: 10, threshold: 0.5);

        $this->assertCount(1, $results);
        $this->assertEquals($post1->id, $results->first()->id);
    }

    public function test_it_returns_empty_collection_when_threshold_is_impossible(): void
    {
        Post::create(['title' => 'Laravel', 'body' => 'PHP Framework']);
        $v = $this->padVector([1.0, 0.0, 0.0]);

        $results = Post::similarTo($v, limit: 10, threshold: 2.0);

        $this->assertCount(0, $results);
    }

    public function test_it_respects_limit(): void
    {
        $v = $this->padVector([1.0, 0.0, 0.0]);
        for ($i = 1; $i <= 5; $i++) {
            $post = Post::create(['title' => "Post {$i}", 'body' => 'Content']);
            $this->setVector($post, $v);
        }

        $results = Post::similarTo($v, limit: 3);

        $this->assertCount(3, $results);
    }

    public function test_it_filters_by_ids(): void
    {
        $post1 = Post::create(['title' => 'P1', 'body' => 'C1']);
        $post2 = Post::create(['title' => 'P2', 'body' => 'C2']);

        $v = $this->padVector([1.0, 0.0, 0.0]);
        $this->setVector($post1, $v);
        $this->setVector($post2, $v);

        $results = Post::similarTo($v, limit: 10, where: fn ($q) => $q->where('id', $post2->id));

        $this->assertCount(1, $results);
        $this->assertEquals($post2->id, $results->first()->id);
    }

    public function test_it_returns_empty_collection_when_no_embeddings_exist(): void
    {
        Post::withoutEmbedding(fn () => Post::create(['title' => 'Laravel', 'body' => 'PHP']));
        $v = $this->padVector([1.0, 0.0, 0.0]);

        $results = Post::similarTo($v, limit: 10);

        $this->assertCount(0, $results);
    }

    private function padVector(array $values): array
    {
        $dimensions = config('embedding.dimensions', 1536);

        return array_map('floatval', array_pad($values, $dimensions, 0.0));
    }

    private function setVector(Post $post, array $vector, string $slot = 'default'): void
    {
        $dimensions = config('embedding.dimensions', 1536);
        $vectorString = '[' . implode(',', $vector) . ']';

        \Illuminate\Support\Facades\DB::statement(
            "UPDATE embeddings SET vector = TO_VECTOR('{$vectorString}', {$dimensions}, FLOAT32) WHERE embeddable_type = ? AND embeddable_id = ? AND slot = ?",
            [$post->getMorphClass(), $post->id, $slot]
        );
    }
}
