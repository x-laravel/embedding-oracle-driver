<?php

namespace XLaravel\Embedding\Driver\Oracle;

use XLaravel\Embedding\Contracts\IdSetBinder;

/**
 * Carries an ID set as a single JSON bind and lets Oracle expand it with
 * JSON_TABLE. oci8 binds one placeholder per value, and that cost climbs
 * sharply with the list: a few thousand IDs already take seconds, tens of
 * thousands stop returning altogether. One CLOB parameter removes the limit —
 * the set only has to be serialized once.
 */
class OracleJsonIdSetBinder implements IdSetBinder
{
    /**
     * Past roughly 4KB of JSON, oci8 switches the parameter from VARCHAR2 to
     * CLOB and the query picks up a flat ~5ms. Below this many IDs plain binds
     * stay under that, above it they overtake it and keep climbing: measured at
     * 800 IDs 5.3ms against 6.7ms, at 1500 19.7ms against 7.3ms, at 5000
     * 158ms against 10.4ms.
     */
    private const INLINE_THRESHOLD = 1000;

    public function apply(mixed $query, string $column, array $ids, bool $negate = false): mixed
    {
        if (count($ids) <= self::INLINE_THRESHOLD) {
            return $negate
                ? $query->whereNotIn($column, $ids)
                : $query->whereIn($column, $ids);
        }

        $wrapped = $query->getGrammar()->wrap($column);
        $exists = $negate ? 'NOT EXISTS' : 'EXISTS';

        return $query->whereRaw(
            "{$exists} (SELECT 1 FROM JSON_TABLE(?, '$[*]' COLUMNS (id NUMBER PATH '$')) xl_ids WHERE xl_ids.id = {$wrapped})",
            [json_encode(array_values($ids))],
        );
    }

    public function maxIdsPerQuery(): int
    {
        return 0;
    }
}
