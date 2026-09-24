<?php

namespace XLaravel\Embedding\Driver\Oracle;

use Illuminate\Support\Facades\DB;
use Throwable;
use XLaravel\Embedding\Contracts\PayloadStoreMetrics;
use XLaravel\Embedding\Models\Embeddable;

class OraclePayloadStoreMetrics implements PayloadStoreMetrics
{
    public function snapshot(): array
    {
        $rows = Embeddable::query()->count();
        $bytes = null;
        $dataBytes = null;
        $indexBytes = null;

        try {
            // Like the embeddings table's VECTOR column, the OSON-encoded
            // payload JSON column can spill into LOB segments once values
            // grow past the inline limit. user_lobs maps the table to its LOB
            // segment names, so those bytes are counted as data; LOB index
            // segments (SYS_IL*) surface through user_indexes like any other
            // index and are counted as index bytes. Index segments use their
            // own segment_name (PK / unique / SYS_IL* names), never the
            // table name — they must be resolved through user_indexes.
            $table = config('embedding.database.embeddables_table', 'embeddables');
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
            // fields null so embedding:payload:status renders them as "n/a".
        }

        return [
            'rows' => $rows,
            'bytes' => $bytes,
            'data_bytes' => $dataBytes,
            'index_bytes' => $indexBytes,
        ];
    }
}
