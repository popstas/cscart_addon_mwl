# Add products_validate step to mwl_xlsx

## Overview
- Add a new `products_validate` cron mode to the mwl_xlsx addon that checks for duplicate products after import
- Detects same-name products where multiple are default variations (parent_product_id = product_id, parent_product_id != 0)
- Logs warnings only, does not modify data
- Runs as the last step (step 7) in the sync-shop import pipeline

## Context (from discovery)
- Read about projects at `/home/popstas/projects/expertizeme/pressfinity-workspace/CLAUDE.md`
- Import pipeline defined in `smi-parser/config.yml` under `cscart.importSteps` (currently 6 steps)
- Each step is a dispatch mode in `cscart_mwl_addon/app/addons/mwl_xlsx/controllers/backend/mwl_xlsx.php`
- Cron runners follow pattern: `Tygh/Addons/MwlXlsx/Cron/<Name>Runner.php` with a `run()` method
- Controller instantiates runner and calls `run()`, passing `$mode` for metrics output
- Existing duplicate detection in `func.php:variation_group_save_group` checks feature combinations within groups during import
- Helper functions: `fn_mwl_xlsx_output_metrics()`, `fn_mwl_xlsx_append_log()`, `fn_mwl_xlsx_log_info()`
- Default variation = product where `product_id = parent_product_id` in `cscart_product_variation_group_products` (and `parent_product_id != 0`)

## Development Approach
- **Testing approach**: Regular (code first)
- Complete each task fully before moving to the next
- Make small, focused changes
- No .po files for cron tasks (use hardcoded English messages per CLAUDE.md rules)

## Progress Tracking
- Mark completed items with `[x]` immediately when done
- Add newly discovered tasks with + prefix
- Document issues/blockers with warning prefix

## Implementation Steps

### Task 1: Create ProductsValidateRunner.php
- [ ] Create `cscart_mwl_addon/app/addons/mwl_xlsx/Tygh/Addons/MwlXlsx/Cron/ProductsValidateRunner.php`
- [ ] Add `run(string $mode): void` method following existing runner pattern
- [ ] Query: find all product names (from `cscart_product_descriptions`, lang_code='en') that appear as default variations in multiple variation groups (product_id = parent_product_id in `cscart_product_variation_group_products`, parent_product_id != 0, product status != 'D')
- [ ] For each duplicate set: log warning with product IDs, product codes, and group codes
- [ ] Output metrics via `fn_mwl_xlsx_output_metrics('products_validate', $metrics)` with counts: `total_checked`, `duplicates_found`, `duplicate_names`

### Task 2: Register products_validate mode in controller
- [ ] Add `use Tygh\Addons\MwlXlsx\Cron\ProductsValidateRunner;` to `controllers/backend/mwl_xlsx.php`
- [ ] Add `if ($mode === 'products_validate')` block (before the POST settings block, after delete_unused_products)
- [ ] Instantiate `ProductsValidateRunner` and call `$runner->run($mode)`

### Task 3: Add step to smi-parser config
- [ ] Add step 7 to `smi-parser/config.yml` importSteps:
  ```yaml
  - name: 7-products_validate
    command: --dispatch=mwl_xlsx.products_validate
  ```

### Task 4: Verify
- [ ] Run `npm run sync-shop` from smi-parser and check `data/cscart/logs/last.log` for products_validate output
- [ ] Verify the step runs without errors
- [ ] Verify metrics are output correctly

## Technical Details

### SQL Query for duplicate detection
```sql
SELECT pd.product AS name,
       COUNT(DISTINCT vgp.product_id) AS default_count,
       GROUP_CONCAT(DISTINCT vgp.product_id) AS product_ids,
       GROUP_CONCAT(DISTINCT p.product_code) AS product_codes,
       GROUP_CONCAT(DISTINCT vg.code) AS group_codes
FROM cscart_product_variation_group_products vgp
JOIN cscart_products p ON p.product_id = vgp.product_id AND p.status != 'D'
JOIN cscart_product_descriptions pd ON pd.product_id = vgp.product_id AND pd.lang_code = 'en'
JOIN cscart_product_variation_groups vg ON vg.id = vgp.group_id
WHERE vgp.parent_product_id = vgp.product_id
  AND vgp.parent_product_id != 0
GROUP BY pd.product
HAVING default_count > 1
ORDER BY default_count DESC
```

### Runner output format
```
products_validate: checking for duplicate default variations...
products_validate: checked N products, found M duplicates
[products_validate] json: {"total_checked": N, "duplicates_found": M, "duplicate_names": [...]}
```

### Warning format per duplicate
```
products_validate: WARNING: "Media Name" has N default variations: #123 (code1, group: grp1), #456 (code2, group: grp2)
```

## Post-Completion
- Monitor import logs for a few sync cycles to verify no false positives
- Consider adding duplicate resolution logic if duplicates are consistently found
