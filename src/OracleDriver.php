<?php

namespace XLaravel\Embedding\Driver\Oracle;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use XLaravel\Embedding\Contracts\SimilarityDriver;

class OracleDriver implements SimilarityDriver
{
    public function search(Model $prototype, array $queryVector, int $limit, float $threshold = 0.0, ?array $ids = null, string $slot = 'default'): Collection
    {
        $morphClass = $prototype->getMorphClass();
        $embeddingClass = config('embedding.model');
        $dimensions = config('embedding.dimensions', 1536);
        $vectorString = '[' . implode(',', $queryVector) . ']';

        $distanceExpr = "VECTOR_DISTANCE(vector, TO_VECTOR(?, {$dimensions}, FLOAT32), COSINE)";
        $maxDistance = 1.0 - $threshold;

        $query = $embeddingClass::query()
            ->select(['embeddable_id'])
            ->selectRaw("1 - {$distanceExpr} AS similarity_score", [$vectorString])
            ->where('embeddable_type', $morphClass)
            ->where('slot', $slot)
            ->whereRaw("{$distanceExpr} <= {$maxDistance}", [$vectorString])
            ->orderByRaw("{$distanceExpr} ASC", [$vectorString])
            ->limit($limit);

        if ($ids !== null) {
            $query->whereIn('embeddable_id', $ids);
        }

        $results = $query->get();

        $matchedIds = $results->pluck('embeddable_id')->all();
        $scores = $results->pluck('similarity_score', 'embeddable_id')->all();

        return $prototype::findMany($matchedIds)
            ->each(fn ($m) => $m->setAttribute('similarity_score', (float) ($scores[$m->getKey()] ?? 0.0)))
            ->sortByDesc(fn ($m) => $m->getAttribute('similarity_score'))
            ->values();
    }
}
