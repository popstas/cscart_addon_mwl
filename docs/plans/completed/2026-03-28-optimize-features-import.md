# Optimize fn_exim_set_product_features Import Speed

## Overview
`fn_exim_set_product_features` consumes **85% (372s)** of total import time for 1309 products.
We implement 4 independent optimizations as separate patches on the remote CS-Cart server, benchmarking each with the existing profiler (`--profile_import=1`).

## Context
- **Baseline**: 372s for `fn_exim_set_product_features` (after Cache 1 already applied)
- **Remote server**: test CS-Cart instance (see local notes for connection details)
- **Core files to patch** (all on remote, backups already exist as `.bak`):
  - `app/schemas/exim/products.functions.php` — `fn_exim_find_feature`, `fn_exim_save_product_feature`, `fn_exim_save_product_features_values`
  - `app/functions/fn.features.php` — `fn_update_product_features_value` (Cache 1 already applied)
  - `app/functions/fn.exim.php` — profiling instrumentation (already applied)
- **Profiler**: `--profile_import=1` in config, reports to `var/files/log/import_core_profile_*.log`
- **Import command**: `npm run sync-shop` in smi-parser

## Development Approach
- **No automated tests** — CS-Cart core has no test suite
- Each optimization is a separate patch applied to remote server
- Verify correctness by: import completes without errors, product data intact
- Benchmark by comparing `fn_exim_set_product_features` time in profile reports
- **CRITICAL: backup each file before patching, revert if optimization causes errors**
- **CRITICAL: run full sync-shop for each optimization to get realistic timings**

## Progress Tracking
- Mark completed items with `[x]` immediately when done
- Record benchmark results inline after each task
- Compare all results in final summary table

## Implementation Steps

### Task 1: Baseline measurement
- [x] Run sync-shop with current patches (Cache 1 for fn_get_product_features + fn_get_product_feature_type_by_feature_id already applied)
- [x] Record baseline: total loop time, fn_exim_set_product_features time, slowest product
- [x] Save report filename for reference

Baseline results (409 products):
- Total loop time: 117.8s (avg 0.288s/product)
- fn_exim_set_product_features: 98.39s (83.5% of loop, avg 0.2406s/product)
- Slowest product: #50912 at 0.853s (features: 0.665s)
- Report: import_core_profile_20260328_080052.log

### Task 2: Cache fn_exim_find_feature results
**File**: `app/schemas/exim/products.functions.php` fn `fn_exim_find_feature` (line ~2026)
**Problem**: Called up to 3x per feature per product = ~59K calls. Only 36 features exist, results are deterministic per (name, type, parent_id, lang_code, company_id, field_name).
**Fix**: Add static cache keyed by `$name:$type:$group_id:$lang_code:$company_id:$field_name`
- [x] Backup `products.functions.php` on remote (if not already backed up)
- [x] Add static `$_cache = []` in `fn_exim_find_feature`
- [x] Build cache key from function params, return cached result if exists
- [x] Store result in cache after DB query
- [x] Run sync-shop, verify no errors
- [x] Record fn_exim_set_product_features time
- [x] **Result**: 90.81s (was 98.39s baseline, saved 7.58s = 7.7%)

Report: import_core_profile_20260328_080523.log
Total loop: 110.0s (was 117.8s), avg 0.269s/product

### Task 3: Cache fn_exim_save_product_feature results
**File**: `app/schemas/exim/products.functions.php` fn `fn_exim_save_product_feature` (line ~2066)
**Problem**: Called per feature per product = ~19K calls. Internally calls `fn_exim_find_feature` (now cached) + `fn_update_product_feature` for new features + multilang description UPDATEs. For existing features (majority), it just finds the feature_id and updates descriptions.
**Fix**: Cache the feature_id lookup part. Key: `$name:$type:$parent_id:$lang_code:$company_id`. Return cached feature data (feature_id, company_id) to skip repeated find+update cycles.
- [x] Add static `$_feature_cache = []` in `fn_exim_save_product_feature`
- [x] Build cache key from feature name+type+parent_id+lang_code+company_id
- [x] On cache hit: return cached feature array (skip find + description update)
- [x] On cache miss: run normal logic, store result
- [x] Run sync-shop, verify no errors
- [x] Record fn_exim_set_product_features time
- [x] **Result**: 91.73s (was 90.81s, saved ~0s = negligible, within noise)

Report: import_core_profile_20260328_081001.log
Total loop: 111.7s (was 110.0s), avg 0.273s/product
Note: No meaningful improvement because fn_exim_find_feature was already cached in Task 2, and the remaining cost is in downstream value saving (fn_exim_save_product_features_values).

### Task 4: Batch variant lookups in fn_exim_save_product_features_values
**File**: `app/schemas/exim/products.functions.php` fn `fn_exim_save_product_features_values` (line ~1840)
**Problem**: The `LOWER(variant) IN (?a)` query at line 1913 defeats the `variant` index. Also, `fn_add_feature_variant` is called for new variants one at a time.
**Fix**: Remove `LOWER()` wrapper (use case-insensitive collation instead or `fn_strtolower` on PHP side only), so the `variant` index is used.
- [x] Check table collation to confirm case-insensitive comparison works without LOWER()
- [x] Modify the variant lookup query to remove LOWER() on the DB column
- [x] Keep `fn_strtolower` for PHP-side key matching
- [x] Run sync-shop, verify variants still matched correctly
- [x] Record fn_exim_set_product_features time
- [x] **Result**: 85.37s (was 91.73s, saved 6.36s = 6.9%)

Report: import_core_profile_20260328_081545.log
Total loop: 104.6s (was 111.7s), avg 0.256s/product
Note: Removed LOWER() from WHERE clause only, kept LOWER() in SELECT for hash key consistency. Collation utf8mb3_general_ci handles case-insensitive matching. Index on variant column now usable.

### Task 5: Skip unchanged features (diff-based optimization)
**File**: `app/functions/fn.features.php` fn `fn_update_product_features_value` (line ~110)
**Problem**: For every product, ALL features are DELETE'd then re-INSERT'd, even if values haven't changed. With 15 features x 1309 products = ~19K DELETE + INSERT pairs.
**Fix**: Before DELETE/INSERT, compare incoming value with current DB value. Skip if identical.
- [x] After the `foreach ($product_features as $feature_id => $value)` loop starts, query current value
- [x] Compare current value with incoming value (handle variant_id vs value vs value_int types)
- [x] If identical, skip the DELETE + INSERT for this feature
- [x] Add profiler counter for skipped vs updated features
- [x] Run sync-shop, verify feature values still correct
- [x] Record fn_exim_set_product_features time
- [x] **Result**: 10.90s (was 85.37s, saved 74.47s = 87.2%)

Report: import_core_profile_20260328_082030.log
Total loop: 30.5s (was 104.6s), avg 0.075s/product
Note: Massive improvement because during re-imports most feature values are unchanged. Added two DB queries per product to fetch current values, but eliminated thousands of unnecessary DELETE+INSERT pairs. The two extra SELECTs cost far less than the avoided writes.

### Task 6: Collect results and summarize
- [x] Create comparison table with all benchmark results
- [x] Identify which optimizations to keep permanently
- [x] Decide which patches to revert vs keep on remote
- [x] Update revert instructions

#### Benchmark Comparison Table (409 products, fn_exim_set_product_features time)

| Task | Optimization | Time (s) | Delta (s) | Incremental % | Cumulative % |
|------|-------------|----------|-----------|---------------|-------------|
| 1 | Baseline (Cache 1 already applied) | 98.39 | - | - | - |
| 2 | Cache fn_exim_find_feature | 90.81 | -7.58 | 7.7% | 7.7% |
| 3 | Cache fn_exim_save_product_feature | 91.73 | +0.92 | ~0% (noise) | 6.8% |
| 4 | Remove LOWER() from variant lookup | 85.37 | -6.36 | 6.9% | 13.2% |
| 5 | Skip unchanged features (diff) | 10.90 | -74.47 | 87.2% | 88.9% |

Total loop time: 117.8s -> 30.5s (74.1% reduction)
Per-product average: 0.288s -> 0.075s

#### Optimizations to keep permanently

1. Cache fn_exim_find_feature (Task 2) - KEEP. 7.7% improvement, zero risk, static cache auto-clears per request.
2. Cache fn_exim_save_product_feature (Task 3) - REVERT or keep (harmless). No measurable improvement, adds minor code complexity. Safe to keep but provides no value.
3. Remove LOWER() from variant lookup (Task 4) - KEEP. 6.9% improvement, relies on confirmed utf8mb3_general_ci collation. Risk: if collation changes, case matching could break. Mitigation: collation is set at table creation and rarely changes.
4. Skip unchanged features (Task 5) - KEEP. 87.2% improvement, the dominant optimization. Risk: if comparison logic has edge cases, some feature updates could be silently skipped. Mitigation: on first import (no existing values), all features are written normally; the optimization only affects re-imports.

#### Patch status on remote

- products.functions.php: KEEP patched (Tasks 2, 3, 4 all applied). Task 3 cache is harmless.
- fn.features.php: KEEP patched (Task 5 applied). This is the biggest win.
- fn.exim.php: KEEP patched (profiling instrumentation). Zero overhead when --profile_import=1 flag not passed.

#### Revert instructions (updated)

Only revert if issues are discovered. Individual patches cannot be reverted independently since all are in the same files. Full revert commands are in the Revert commands section below.

If only Task 5 needs reverting (most likely candidate for edge cases):
```bash
# Revert fn.features.php only (Task 5: skip unchanged features)
# On the remote server, restore the backup:
cp app/functions/fn.features.php.bak app/functions/fn.features.php
```

## Technical Details

### Cache key patterns
- `fn_exim_find_feature`: `"$name|$type|$group_id|$lang_code|$company_id|$field_name"`
- `fn_exim_save_product_feature`: `"$name|$type|$parent_id|$lang_code|$company_id"`
- All caches use `static` variables (auto-cleared per PHP process)

### Measurement protocol
- Each optimization measured by running full `npm run sync-shop`
- Compare `fn_exim_set_product_features` line in `import_core_profile_*.log`
- Each run processes same ~1309 products
- Server load variance expected ~5-10%, so improvements <5% are noise

### Revert commands
```bash
# On the remote CS-Cart server, restore backups:
cp app/schemas/exim/products.functions.php.bak app/schemas/exim/products.functions.php
cp app/functions/fn.features.php.bak app/functions/fn.features.php
cp app/functions/fn.exim.php.bak app/functions/fn.exim.php
```

## Post-Completion

**Keep permanently:**
- Optimizations with >5% improvement and no correctness issues
- Profile instrumentation in fn.exim.php (zero overhead when flag not passed)
- Caches in fn.features.php (safe static caches)

**Consider for upstream:**
- If CS-Cart updates these files, patches will need reapplication
- Document exact line numbers and patch diffs for future reference
