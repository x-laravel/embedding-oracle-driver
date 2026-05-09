<?php

namespace XLaravel\Embedding\Driver\Oracle;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use XLaravel\Embedding\Contracts\VectorStore;
use XLaravel\Embedding\Models\Embedding;

class OracleVectorStore implements VectorStore
{
    public function store(Model $model, array $vector, string $slot): Embedding
    {
        $connection = config('embedding.database.connection');
        $table = config('embedding.database.table');
        $morphClass = $model->getMorphClass();
        $key = $model->getKey();
        $dimensions = (int) config('embedding.dimensions', 1536);
        $vectorStr = '['.implode(',', $vector).']';
        $now = now()->toDateTimeString();

        DB::connection($connection)->statement(
            "MERGE INTO {$table} t
             USING DUAL ON (t.embeddable_type = ? AND t.embeddable_id = ? AND t.slot = ?)
             WHEN MATCHED THEN
               UPDATE SET t.vector = TO_VECTOR(?, {$dimensions}, FLOAT32), t.updated_at = ?
             WHEN NOT MATCHED THEN
               INSERT (embeddable_type, embeddable_id, slot, vector, created_at, updated_at)
               VALUES (?, ?, ?, TO_VECTOR(?, {$dimensions}, FLOAT32), ?, ?)",
            [$morphClass, $key, $slot, $vectorStr, $now,
             $morphClass, $key, $slot, $vectorStr, $now, $now]
        );

        $embeddingClass = config('embedding.model');

        return $embeddingClass::where('embeddable_type', $morphClass)
            ->where('embeddable_id', $key)
            ->where('slot', $slot)
            ->select(['id', 'embeddable_type', 'embeddable_id', 'slot', 'created_at', 'updated_at'])
            ->first();
    }
}
