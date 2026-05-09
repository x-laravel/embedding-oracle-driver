<?php

namespace XLaravel\Embedding\Driver\Oracle\Tests\Feature;

use XLaravel\Embedding\SimilarityManager;
use XLaravel\Embedding\Driver\Oracle\OracleDriver;
use XLaravel\Embedding\Driver\Oracle\Tests\TestCase;

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
}
