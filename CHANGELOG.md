# Changelog

All notable changes to `x-laravel/embedding-oracle-driver` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/). The package's major version follows `laravel/ai`.

## 1.0.0 - 2026-09-24

Initial release. Requires PHP ^8.3, Laravel ^12.0 | ^13.0, `x-laravel/embedding` ^1.0 and Oracle Database 26ai.

### Added

- `OracleDriver` — `oracle` similarity driver running cosine search in Oracle with `VECTOR_DISTANCE`. Distance is converted to `similarity_score = 1 - distance`, and the distance cutoff applies only when `threshold > 0.0`. Soft-deleting models are loaded with `withTrashed()`.
- Payload `filter` translation for `similarTo()` / `similarToText()` / `mostSimilar()`: a `whereExists` subquery against `embeddables` using `JSON_VALUE`. Numbers compare via `RETURNING NUMBER`, every condition carries a JSON type guard so `34` never matches `"34"`, booleans are inlined as literals, and filter keys are validated before being interpolated into the JSON path.
- `OracleVectorStore` — writes embeddings with `MERGE INTO ... USING DUAL` and `TO_VECTOR()`.
- Lazy vector hydration on the configured `embedding.model`'s `retrieved` event: vectors stored out of line are read back as 4000-char `DBMS_LOB.SUBSTR` slices, so large dimensions round-trip intact while pluck, count and aggregate queries pay nothing.
- `OracleJsonIdSetBinder` — carries an ID set in a single JSON bind for the core package's cross-connection lookups.
- `OracleVectorStoreMetrics` and `OraclePayloadStoreMetrics` — storage figures from `user_segments`, including LOB data and index segments.
- Oracle-native migrations with the core package's filenames: `embeddings` with a `VECTOR(n, FLOAT32)` column and `embeddables` with a native `JSON` payload column, published under the `embedding-oracle-migrations` tag.
