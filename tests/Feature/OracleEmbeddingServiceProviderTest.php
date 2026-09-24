<?php

namespace XLaravel\Embedding\Driver\Oracle\Tests\Feature;

use XLaravel\Embedding\Contracts\PayloadStoreMetrics;
use XLaravel\Embedding\Contracts\VectorStoreMetrics;
use XLaravel\Embedding\Driver\Oracle\OracleDriver;
use XLaravel\Embedding\Driver\Oracle\OraclePayloadStoreMetrics;
use XLaravel\Embedding\Driver\Oracle\OracleVectorStoreMetrics;
use XLaravel\Embedding\Driver\Oracle\Tests\TestCase;
use XLaravel\Embedding\SimilarityManager;

class OracleEmbeddingServiceProviderTest extends TestCase
{
    public function test_it_registers_the_oracle_driver(): void
    {
        $manager = app(SimilarityManager::class);

        $this->assertInstanceOf(OracleDriver::class, $manager->driver('oracle'));
    }

    public function test_it_can_be_set_as_the_default_driver(): void
    {
        $manager = app(SimilarityManager::class);
        $manager->forgetDrivers();

        config(['embedding.similarity.driver' => 'oracle']);

        $this->assertInstanceOf(OracleDriver::class, $manager->driver());
    }

    public function test_driver_is_registered_lazily_on_manager_resolution(): void
    {
        $this->app->forgetInstance(SimilarityManager::class);

        $manager = app(SimilarityManager::class);

        $this->assertInstanceOf(OracleDriver::class, $manager->driver('oracle'));
    }

    public function test_each_resolution_returns_same_driver_instance(): void
    {
        $manager = app(SimilarityManager::class);

        $this->assertSame($manager->driver('oracle'), $manager->driver('oracle'));
    }

    public function test_it_binds_oracle_vector_store_metrics(): void
    {
        $this->assertInstanceOf(OracleVectorStoreMetrics::class, app(VectorStoreMetrics::class));
    }

    public function test_metrics_snapshot_reports_rows_and_byte_sizes(): void
    {
        \XLaravel\Embedding\Driver\Oracle\Tests\Fixtures\Models\Post::create([
            'title' => 'Laravel',
            'body' => 'PHP Framework',
        ]);

        $snapshot = app(VectorStoreMetrics::class)->snapshot();

        $this->assertSame(1, $snapshot['rows']);
        $this->assertIsInt($snapshot['bytes']);
        $this->assertIsInt($snapshot['data_bytes']);
        $this->assertIsInt($snapshot['index_bytes']);
        $this->assertGreaterThan(0, $snapshot['bytes']);
        // PRIMARY KEY + the (embeddable_type, embeddable_id, slot) unique
        // index resolve through user_indexes.table_name = 'EMBEDDINGS', so
        // index_bytes must be greater than zero on a freshly-migrated table.
        $this->assertGreaterThan(0, $snapshot['index_bytes']);
    }

    public function test_it_binds_oracle_payload_store_metrics(): void
    {
        $this->assertInstanceOf(OraclePayloadStoreMetrics::class, app(PayloadStoreMetrics::class));
    }

    public function test_payload_metrics_snapshot_reports_rows_and_byte_sizes(): void
    {
        \XLaravel\Embedding\Driver\Oracle\Tests\Fixtures\Models\VenueWithPayload::create([
            'name' => 'Kebapçı',
            'province_id' => 34,
            'category_id' => 3,
            'active' => true,
            'code' => 'V-1',
        ]);

        $snapshot = app(PayloadStoreMetrics::class)->snapshot();

        $this->assertSame(1, $snapshot['rows']);
        $this->assertIsInt($snapshot['bytes']);
        $this->assertIsInt($snapshot['data_bytes']);
        $this->assertIsInt($snapshot['index_bytes']);
        $this->assertGreaterThan(0, $snapshot['bytes']);
        $this->assertGreaterThan(0, $snapshot['data_bytes']);
        // PRIMARY KEY + the (embeddable_type, embeddable_id) unique index
        // resolve through user_indexes.table_name = 'EMBEDDABLES', so
        // index_bytes must be greater than zero on a freshly-migrated table.
        $this->assertGreaterThan(0, $snapshot['index_bytes']);
    }

    public function test_payload_metrics_rows_are_zero_on_empty_table(): void
    {
        $snapshot = app(PayloadStoreMetrics::class)->snapshot();

        $this->assertSame(0, $snapshot['rows']);
    }
}
