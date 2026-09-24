<?php

namespace XLaravel\Embedding\Driver\Oracle;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use InvalidArgumentException;
use XLaravel\Embedding\Contracts\SearchRequest;
use XLaravel\Embedding\Contracts\SimilarityDriver;
use XLaravel\Embedding\Models\Embeddable as EmbeddableRecord;

class OracleDriver implements SimilarityDriver
{
    /**
     * Search for models similar to the request's query vector using Oracle VECTOR_DISTANCE.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, \Illuminate\Database\Eloquent\Model>
     */
    public function search(Model $prototype, SearchRequest $request): Collection
    {
        $morphClass = $prototype->getMorphClass();
        $embeddingClass = config('embedding.model');
        $dimensions = (int) config('embedding.dimensions', 1536);
        $vectorString = '[' . implode(',', $request->vector) . ']';

        $distanceExpr = "VECTOR_DISTANCE(vector, TO_VECTOR(?, {$dimensions}, FLOAT32), COSINE)";

        $query = $embeddingClass::query()
            ->select(['embeddable_id'])
            ->selectRaw("1 - {$distanceExpr} AS similarity_score", [$vectorString])
            ->where('embeddable_type', $morphClass)
            ->where('slot', $request->slot)
            ->orderByRaw("{$distanceExpr} ASC", [$vectorString])
            ->limit($request->limit);

        // A cosine distance can exceed 1.0 (anti-correlated vectors), so the
        // cutoff only applies for a positive threshold — 0.0 returns all
        // results, per the SearchRequest contract.
        if ($request->threshold > 0.0) {
            $maxDistance = 1.0 - $request->threshold;
            $query->whereRaw("{$distanceExpr} <= {$maxDistance}", [$vectorString]);
        }

        if ($request->ids !== null) {
            $query->whereIn('embeddable_id', $request->ids);
        }

        if (! empty($request->filter)) {
            $this->applyPayloadFilter($query, $request->filter);
        }

        // yajra/oci8 emits a harmless ORA-64201 LOB warning after a VECTOR
        // query; in PHP-FPM this becomes a QueryException via HandleExceptions.
        // Suppress E_WARNING for this call until the upstream driver is fixed.
        $previousLevel = error_reporting(error_reporting() & ~E_WARNING);
        try {
            $results = $query->get();
        } finally {
            error_reporting($previousLevel);
        }

        $matchedIds = $results->pluck('embeddable_id')->all();
        $scores = $results->pluck('similarity_score', 'embeddable_id')->all();

        // When the model uses SoftDeletes and embeddings are kept on soft
        // delete, trashed rows still own embedding records and can score
        // against the query — include them so the caller decides what to do.
        $modelQuery = in_array(SoftDeletes::class, class_uses_recursive($prototype), true)
            ? $prototype::query()->withTrashed()
            : $prototype::query();

        return $modelQuery->findMany($matchedIds)
            ->each(fn ($m) => $m->setAttribute('similarity_score', (float) ($scores[$m->getKey()] ?? 0.0)))
            ->sortByDesc(fn ($m) => $m->getAttribute('similarity_score'))
            ->values();
    }

    /**
     * Constrain the embeddings query to rows whose payload record matches
     * the filter: scalar values compare as equality, arrays as IN, entries
     * are ANDed. Runs as whereExists against the embeddables table on the
     * same connection — the model database is never touched. Records
     * without a payload row never match a filtered search.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @param  array<string, mixed>  $filter
     */
    protected function applyPayloadFilter($query, array $filter): void
    {
        $embeddingsTable = $query->getModel()->getTable();
        $embeddablesTable = (new EmbeddableRecord())->getTable();

        $query->whereExists(function ($exists) use ($filter, $embeddingsTable, $embeddablesTable) {
            $exists->from($embeddablesTable)
                ->whereColumn("{$embeddablesTable}.embeddable_type", "{$embeddingsTable}.embeddable_type")
                ->whereColumn("{$embeddablesTable}.embeddable_id", "{$embeddingsTable}.embeddable_id");

            foreach ($filter as $key => $value) {
                $this->applyPayloadCondition($exists, $key, $value);
            }
        });
    }

    /**
     * @param  \Illuminate\Database\Query\Builder  $query
     */
    protected function applyPayloadCondition($query, string $key, mixed $value): void
    {
        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key)) {
            throw new InvalidArgumentException("Invalid payload filter key [{$key}].");
        }

        if (! is_array($value)) {
            $this->wherePayloadEquals($query, $key, $value);

            return;
        }

        if ($value === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where(function ($group) use ($key, $value) {
            foreach ($value as $candidate) {
                $group->orWhere(fn ($branch) => $this->wherePayloadEquals($branch, $key, $candidate));
            }
        });
    }

    /**
     * Comparisons are type-strict, mirroring the core PhpDriver: JSON_VALUE
     * extracts numbers with an explicit RETURNING clause (the default
     * VARCHAR2 return would let Oracle implicitly convert numbers and
     * strings into each other) and a .type() guard rejects payload values
     * of a different JSON type, so 34 never matches "34" and vice versa.
     * Booleans are inlined as literals — oci8 binds every parameter as a
     * string, which would break a typed comparison.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     */
    protected function wherePayloadEquals($query, string $key, mixed $value): void
    {
        $typeExpr = "JSON_VALUE(payload, '$.{$key}.type()')";

        if (is_int($value) || is_float($value)) {
            $query->whereRaw("{$typeExpr} = 'number'")
                ->whereRaw("JSON_VALUE(payload, '$.{$key}' RETURNING NUMBER) = ?", [$value]);
        } elseif (is_bool($value)) {
            $literal = $value ? 'true' : 'false';
            $query->whereRaw("{$typeExpr} = 'boolean'")
                ->whereRaw("JSON_VALUE(payload, '$.{$key}') = '{$literal}'");
        } elseif (is_string($value)) {
            $query->whereRaw("{$typeExpr} = 'string'")
                ->whereRaw("JSON_VALUE(payload, '$.{$key}') = ?", [$value]);
        } elseif ($value === null) {
            $query->whereRaw("{$typeExpr} = 'null'");
        } else {
            throw new InvalidArgumentException("Payload filter values must be scalar or arrays of scalars [{$key}].");
        }
    }
}
