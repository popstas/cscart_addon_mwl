<?php

use Tygh\Addons\MwlXlsx\Import\MwlImportProfiler;

if (!defined('BOOTSTRAP')) { die('Access denied'); }

/**
 * Compare two values with type coercion for import vs DB comparison.
 *
 * @param mixed $import_val Value from import data
 * @param mixed $db_val Value from database
 * @return bool
 */
function fn_mwl_exim_values_equal($import_val, $db_val)
{
    // Both empty
    if (($import_val === null || $import_val === '') && ($db_val === null || $db_val === '')) {
        return true;
    }

    // One empty, other not
    if (($import_val === null || $import_val === '') !== ($db_val === null || $db_val === '')) {
        return false;
    }

    // String comparison first
    if ((string) $import_val === (string) $db_val) {
        return true;
    }

    // Numeric comparison with 2-decimal precision
    if (is_numeric($import_val) && is_numeric($db_val)) {
        return abs((float) $import_val - (float) $db_val) < 0.005;
    }

    return false;
}

/**
 * Fetch current product data from DB for comparison with import data.
 *
 * @param int $product_id
 * @param string $lang_code
 * @return array|false
 */
function fn_mwl_exim_get_product_current_data($product_id, $lang_code)
{
    return db_get_row(
        'SELECT p.product_code, p.status, p.list_price, p.amount, p.min_qty, p.max_qty, '
        . 'p.weight, p.qty_step, p.list_qty_count, p.shipping_freight, p.tracking, '
        . 'p.free_shipping, p.zero_price_action, p.is_edp, p.edp_shipping, '
        . 'p.out_of_stock_actions, p.usergroup_ids, p.options_type, p.exceptions_type, '
        . 'd.product, '
        . 'pp.price '
        . 'FROM ?:products p '
        . 'LEFT JOIN ?:product_descriptions d ON d.product_id = p.product_id AND d.lang_code = ?s '
        . 'LEFT JOIN ?:product_prices pp ON pp.product_id = p.product_id AND pp.lower_limit = 1 AND pp.usergroup_id = 0 '
        . 'WHERE p.product_id = ?i',
        $lang_code,
        $product_id
    );
}

/**
 * import_process_data handler: skip unchanged products, only update updated_timestamp.
 *
 * @param array $primary_object_id
 * @param array $object Import row data
 * @param array $pattern
 * @param array $options
 * @param array $processed_data
 * @param bool $skip_record
 */
function fn_mwl_exim_skip_unchanged_products($primary_object_id, $object, $pattern, $options, &$processed_data, &$skip_record)
{
    // Already skipped by another handler
    if ($skip_record) {
        return;
    }

    // New product — must go through full import
    if (empty($primary_object_id)) {
        return;
    }

    // Opt-in via CLI flag
    if (empty($_REQUEST['skip_unchanged'])) {
        return;
    }

    $product_id = $primary_object_id['product_id'];
    $profiler = MwlImportProfiler::instance();
    $profiler->stepStart('early_check');

    // Fields to always ignore in comparison
    $ignore_fields = [
        'updated_timestamp' => true,
        'timestamp' => true,
        'product_id' => true,
        'lang_code' => true,
        'company_id' => true,
        // process_put-only fields (handled by processing groups, not direct DB mapping)
        'Category' => true,
        'Secondary categories' => true,
        'Features' => true,
        'Options' => true,
        'Thumbnail' => true,
        'Detailed image' => true,
        'Images' => true,
        'Files' => true,
        'Taxes' => true,
        'Items in box' => true,
        'Box size' => true,
        'SEO name' => true,
        'Variation group code' => true,
        'Variation group' => true,
        'Variation set as default' => true,
    ];

    $lang_code = !empty($options['lang_code']) ? (is_array($options['lang_code']) ? reset($options['lang_code']) : $options['lang_code']) : CART_LANGUAGE;

    $current_data = fn_mwl_exim_get_product_current_data($product_id, $lang_code);
    if (empty($current_data)) {
        $profiler->stepEnd('early_check');
        $profiler->increment('early_check_changed');
        return;
    }

    $changed_fields = [];

    foreach ($object as $field => $import_value) {
        if (isset($ignore_fields[$field])) {
            continue;
        }

        // Only compare fields that exist in our DB result
        if (!array_key_exists($field, $current_data)) {
            continue;
        }

        if (!fn_mwl_exim_values_equal($import_value, $current_data[$field])) {
            $changed_fields[] = $field;
        }
    }

    if (!empty($changed_fields)) {
        $profiler->stepEnd('early_check');
        $profiler->increment('early_check_changed');
        return;
    }

    // No changes — only update timestamp
    db_query('UPDATE ?:products SET updated_timestamp = ?i WHERE product_id = ?i', TIME, $product_id);

    $skip_record = true;
    $processed_data['S']++;

    $profiler->stepEnd('early_check');
    $profiler->increment('early_check_skipped');
}
