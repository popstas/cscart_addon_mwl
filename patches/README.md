# CS-Cart Core Patches for Import Optimization

These patches optimize `fn_exim_set_product_features` in CS-Cart product import, reducing feature processing time by ~87%.

## Patches

### skip-unchanged-features.patch

**Target file**: `app/functions/fn.features.php`
**Function**: `fn_update_product_features_value`

**Problem**: During import, ALL product features are DELETE'd then re-INSERT'd for every product, even when values haven't changed. With 15 features × 3227 products = ~48K unnecessary DELETE + INSERT pairs.

**Solution**: Before the DELETE/INSERT loop, fetch current feature values from DB. For each feature, compare the incoming value with the current one. If identical, skip the DELETE + INSERT entirely.

Handles all feature types:
- TEXT_FIELD: compares `value` column (string)
- NUMBER_FIELD: compares `value_int` column (string cast)
- DATE: compares `value_int` with `fn_parse_date()` result (int cast)
- TEXT_SELECTBOX / NUMBER_SELECTBOX / EXTENDED: compares `variant_id` (int cast)
- MULTIPLE_CHECKBOX: compares sorted arrays of variant_ids

**Result**: ~87% reduction in `fn_exim_set_product_features` time (98.36s → ~12.6s for 3227 products). Most features are unchanged during re-imports.

### cache-exim-find-feature.patch

**Target file**: `app/schemas/exim/products.functions.php`
**Function**: `fn_exim_find_feature`

**Problem**: `fn_exim_find_feature` is called up to 3 times per feature per product (~59K calls for 1309 products), but only ~36 unique features exist. Each call executes a DB query with JOINs, even though results are deterministic for the same input parameters.

**Solution**: Add a static cache keyed by `$name|$type|$group_id|$lang_code|$company_id|$field_name`. On cache hit, return immediately without DB query. On cache miss, execute the query and store the result. The static variable auto-clears when the PHP process ends.

**Result**: ~8% reduction in `fn_exim_set_product_features` time (98.39s -> 90.81s for 409 products).

### remove-lower-variant-lookup.patch

**Target file**: `app/schemas/exim/products.functions.php`
**Function**: `fn_exim_save_product_features_values`

**Problem**: The variant lookup query uses `LOWER(variant) IN (?a)` in the WHERE clause. The `LOWER()` wrapper prevents MySQL from using the index on the `variant` column, forcing full table scans on every variant lookup during import.

**Solution**: Remove `LOWER()` from the WHERE clause only. The `variant` column uses `utf8mb3_general_ci` collation which is case-insensitive, so `LOWER()` is unnecessary for matching. Keep `LOWER()` in the SELECT clause so the returned hash keys remain lowercase for consistency with PHP-side `fn_strtolower()` lookups.

**Prerequisite**: The `csc_product_feature_variant_descriptions` table must use a case-insensitive collation (e.g., `utf8mb3_general_ci`). Verify with:
```sql
SHOW TABLE STATUS LIKE 'csc_product_feature_variant_descriptions';
```

**Result**: ~7% reduction in `fn_exim_set_product_features` time (90.81s -> 85.37s for 409 products).

## How to Apply

1. **Backup the original file** (if not already backed up):
   ```bash
   cp app/functions/fn.features.php app/functions/fn.features.php.bak
   ```

2. **Apply the patch** from the CS-Cart root directory:
   ```bash
   patch -p1 < /path/to/patches/skip-unchanged-features.patch
   ```

   Or manually: the patch adds two blocks to `fn_update_product_features_value`:
   - Before the `foreach` loop: two SELECT queries to fetch current values
   - Inside the loop after `fn_get_product_feature_type_by_feature_id`: comparison logic that `continue`s if value is unchanged

3. **Verify**: Run a full import and check that:
   - Import completes without errors
   - Product feature values are correct (spot-check a few products)
   - Features that DO change are still updated properly

## How to Revert

```bash
cp app/functions/fn.features.php.bak app/functions/fn.features.php
```

## Notes

- The optimization only affects re-imports (where products already have feature values). First-time imports write all features normally.
- If `add_new_variant` is set for a feature, the comparison is skipped and the feature is always written (safety measure).
- Two extra SELECT queries per product are added, but the cost is negligible compared to the avoided DELETE + INSERT pairs.
- Unknown feature types (not explicitly handled) are always written through for safety -- they are never skipped.
- The `continue` skips the rest of the loop body for unchanged features. If other add-ons hook into the feature update loop after the DELETE/INSERT, those hooks will not fire for skipped features. Verify no critical hooks exist in your installation.
- Tested on CS-Cart with utf8mb3_general_ci collation. The patch does not depend on collation settings.
