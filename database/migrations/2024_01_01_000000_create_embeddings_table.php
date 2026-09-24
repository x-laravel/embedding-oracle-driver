<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('embedding.database.connection');
    }

    public function up(): void
    {
        $dimensions = config('embedding.dimensions', 1536);

        DB::connection($this->getConnection())->statement("
            CREATE TABLE embeddings (
                id              NUMBER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                embeddable_type VARCHAR2(255)  NOT NULL,
                embeddable_id   NUMBER         NOT NULL,
                slot            VARCHAR2(64)   DEFAULT 'default' NOT NULL,
                vector          VECTOR({$dimensions}, FLOAT32),
                created_at      TIMESTAMP DEFAULT SYSTIMESTAMP,
                updated_at      TIMESTAMP DEFAULT SYSTIMESTAMP,
                CONSTRAINT embeddings_uq UNIQUE (embeddable_type, embeddable_id, slot)
            )
        ");
    }

    public function down(): void
    {
        DB::connection($this->getConnection())->statement('DROP TABLE embeddings');
    }
};
