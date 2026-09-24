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
            // Index segments use their own segment_name (PK / unique index
            // names — e.g. SYS_C00xxxx or "embeddings_..._unique"), not the
            // table name, so user_segments.segment_name = '<table>' would
            // miss them and report index_bytes = 0. Resolve the index names
            // through user_indexes first, then aggregate user_segments for
            // TABLE rows whose name matches the table and INDEX% rows whose
            // name appears in that index list. VECTOR(n, FLOAT32) is stored
            // inline only while the value fits the block; larger dimensions
            // (e.g. 2560 × 4 bytes ≈ 10 KB) spill into an out-of-line LOB
            // segment holding virtually all vector data, so LOB segments
            // (resolved via user_lobs) count as data and LOB index segments
            // (SYS_IL*, surfaced through user_indexes) count as index bytes.
            $table = config('embedding.database.embeddings_table', 'embeddings');
            $row = DB::connection(config('embedding.database.connection'))
                ->selectOne(
                    "SELECT
                        SUM(CASE WHEN segment_type = 'TABLE' OR segment_type LIKE 'LOBSEGMENT%' THEN bytes ELSE 0 END) AS data_bytes,
                        SUM(CASE WHEN segment_type LIKE 'INDEX%' OR segment_type = 'LOBINDEX' THEN bytes ELSE 0 END) AS index_bytes,
                        SUM(bytes) AS total_bytes
                     FROM user_segments
                     WHERE (segment_name = UPPER(?) AND segment_type = 'TABLE')
                        OR ((segment_type LIKE 'INDEX%' OR segment_type = 'LOBINDEX')
                            AND segment_name IN (
                                SELECT index_name FROM user_indexes WHERE table_name = UPPER(?)
                            ))
                        OR (segment_type LIKE 'LOBSEGMENT%'
                            AND segment_name IN (
                                SELECT segment_name FROM user_lobs WHERE table_name = UPPER(?)
                            ))",
                    [$table, $table, $table]
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