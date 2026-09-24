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
        $table = config('embedding.database.embeddables_table', 'embeddables');

        DB::connection($this->getConnection())->statement("
            CREATE TABLE {$table} (
                id              NUMBER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                embeddable_type VARCHAR2(255)  NOT NULL,
                embeddable_id   NUMBER         NOT NULL,
                payload         JSON           NOT NULL,
                created_at      TIMESTAMP DEFAULT SYSTIMESTAMP,
                updated_at      TIMESTAMP DEFAULT SYSTIMESTAMP,
                CONSTRAINT embeddables_uq UNIQUE (embeddable_type, embeddable_id)
            )
        ");
    }

    public function down(): void
    {
        $table = config('embedding.database.embeddables_table', 'embeddables');

        DB::connection($this->getConnection())->statement("DROP TABLE {$table}");
    }
};
