<?php

namespace XLaravel\Embedding\Driver\Oracle;

use Illuminate\Support\Facades\DB;
use Throwable;
use XLaravel\Embedding\Contracts\VectorStoreMetrics;
use XLaravel\Embedding\Models\Embedding;

class OracleVectorStoreMetrics implements VectorStoreMetrics
{
    public function snapshot(): array
    {
        $rows = Embedding::query()->count();
        $bytes = null;
        $dataBytes = null;
        $indexBytes = null;

        try {
            // Oracle stores LOB segments under synthetic SYS_LOB$ names that
            // do not match the table name, so this aggregate covers TABLE +
            // INDEX segments only — sufficient for VECTOR(n, FLOAT32) which
            // Oracle 26ai stores inline when the size fits the row.
            $row = DB::connection(config('embedding.database.connection'))
                ->selectOne(
                    "SELECT
                        SUM(CASE WHEN segment_type = 'TABLE' THEN bytes ELSE 0 END) AS data_bytes,
                        SUM(CASE WHEN segment_type LIKE 'INDEX%' THEN bytes ELSE 0 END) AS index_bytes,
                        SUM(bytes) AS total_bytes
                     FROM user_segments
                     WHERE segment_name = UPPER(?)",
                    [config('embedding.database.table')]
                );

            if ($row !== null) {
                $bytes = isset($row->total_bytes) ? (int) $row->total_bytes : null;
                $dataBytes = isset($row->data_bytes) ? (int) $row->data_bytes : null;
                $indexBytes = isset($row->index_bytes) ? (int) $row->index_bytes : null;
            }
        } catch (Throwable) {
            // user_segments is normally readable for the schema owner, but a
            // restricted user may lack the SELECT privilege. Leave the byte
            // fields null so embedding:status renders them as "n/a".
        }

        return [
            'rows' => $rows,
            'bytes' => $bytes,
            'data_bytes' => $dataBytes,
            'index_bytes' => $indexBytes,
        ];
    }
}