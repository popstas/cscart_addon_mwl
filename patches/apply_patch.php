<?php
/**
 * Apply patches to CS-Cart core files.
 * Usage: curl "https://<domain>/_dev/addons/mwl_xlsx/patches/apply_patch.php?patch=skip-unchanged-features"
 * Add &dry-run=1 for dry-run mode.
 * Add &revert=1 to revert.
 */

header('Content-Type: text/plain; charset=utf-8');

$patch_name = isset($_GET['patch']) ? $_GET['patch'] : '';
$dry_run = !empty($_GET['dry-run']);
$revert = !empty($_GET['revert']);
$cscart_root = realpath(__DIR__ . '/../../../../');

if (!$cscart_root || !file_exists($cscart_root . '/config.php')) {
    die("ERROR: CS-Cart root not found\n");
}

$all_patches = define_patches();

if ($patch_name === 'all') {
    echo "Applying ALL patches" . ($dry_run ? ' (dry-run)' : '') . ($revert ? ' (revert)' : '') . ":\n\n";
    foreach ($all_patches as $name => $steps) {
        echo "=== $name ===\n";
        apply_patch($name, $steps, $cscart_root, $dry_run, $revert);
        echo "\n";
    }
    exit(0);
}

if (empty($patch_name) || !isset($all_patches[$patch_name])) {
    echo "ERROR: Invalid 'patch'. Available:\n";
    foreach (array_keys($all_patches) as $n) echo "  $n\n";
    echo "  all\n";
    exit(1);
}

apply_patch($patch_name, $all_patches[$patch_name], $cscart_root, $dry_run, $revert);

function apply_patch($name, $steps, $root, $dry_run, $revert) {
    foreach ($steps as $i => $step) {
        $file = $root . '/' . $step['file'];
        if (!file_exists($file)) {
            echo "ERROR: File not found: {$step['file']}\n";
            return;
        }
        $content = file_get_contents($file);

        echo "Step " . ($i+1) . ": {$step['desc']} ({$step['file']})\n";

        if ($revert) {
            // Swap old and new for revert
            $find = $step['new'];
            $replace = $step['old'];
        } else {
            $find = $step['old'];
            $replace = $step['new'];
        }

        // Use 'check' field if available, otherwise use first 60 chars of new text
        $applied_marker = !empty($step['check']) ? $step['check'] : substr($step['new'], 0, 60);
        $is_applied = strpos($content, $applied_marker) !== false;

        if (!$revert && $is_applied) {
            echo "  Already applied.\n";
            continue;
        }
        if ($revert && !$is_applied) {
            echo "  Already reverted.\n";
            continue;
        }
        if (strpos($content, $find) === false) {
            echo "  ERROR: Target text not found in file.\n";
            echo "  Looking for: " . substr($find, 0, 80) . "...\n";
            return;
        }

        $new_content = str_replace($find, $replace, $content);

        if (!$dry_run) {
            if (!file_exists($file . '.bak')) {
                copy($file, $file . '.bak');
                echo "  Backup: {$step['file']}.bak\n";
            }
            file_put_contents($file, $new_content);
            echo "  Applied.\n";
        } else {
            echo "  Dry-run OK.\n";
        }
    }
}

function define_patches() {
    $patches = [];

    // --- skip-unchanged-features ---
    $patches['skip-unchanged-features'] = [
        [
            'desc' => 'Add current values fetch before foreach',
            'file' => 'app/functions/fn.features.php',
            'check' => '// Optimization: fetch current feature values',
            'old' => '    foreach ($product_features as $feature_id => $value) {',
            'new' => '    // Optimization: fetch current feature values to skip unchanged ones
    $_current_values = db_get_hash_array(
        \'SELECT feature_id, variant_id, value, value_int FROM ?:product_features_values WHERE product_id = ?i AND lang_code = ?s\',
        \'feature_id\',
        $product_id,
        $lang_code
    );
    // For MULTIPLE_CHECKBOX: fetch all variant_ids grouped by feature_id
    $_current_checkbox_variants = db_get_hash_multi_array(
        \'SELECT feature_id, variant_id FROM ?:product_features_values WHERE product_id = ?i AND lang_code = ?s\',
        array(\'feature_id\', \'variant_id\'),
        $product_id,
        $lang_code
    );

    foreach ($product_features as $feature_id => $value) {',
        ],
        [
            'desc' => 'Add skip-unchanged logic before DELETE',
            'file' => 'app/functions/fn.features.php',
            'check' => '// Optimization: skip unchanged features',
            'old' => '        // Delete variants in current language',
            'new' => '        // Optimization: skip unchanged features
        $current = isset($_current_values[$feature_id]) ? $_current_values[$feature_id] : null;
        if ($current !== null && empty($add_new_variant[$feature_id])) {
            $skip = false;
            if ($feature_type === ProductFeatures::DATE) {
                if (!empty($value) && (int) $current[\'value_int\'] === (int) fn_parse_date($value)) {
                    $skip = true;
                } elseif (empty($value) && empty($current[\'value_int\'])) {
                    $skip = true;
                }
            } elseif ($feature_type === ProductFeatures::MULTIPLE_CHECKBOX) {
                $current_variant_ids = isset($_current_checkbox_variants[$feature_id])
                    ? array_map(\'intval\', array_keys($_current_checkbox_variants[$feature_id]))
                    : [];
                $incoming_variant_ids = is_array($value) ? array_map(\'intval\', array_values($value)) : [];
                sort($current_variant_ids);
                sort($incoming_variant_ids);
                if ($current_variant_ids === $incoming_variant_ids) {
                    $skip = true;
                }
            } elseif (in_array($feature_type, array(ProductFeatures::TEXT_SELECTBOX, ProductFeatures::NUMBER_SELECTBOX, ProductFeatures::EXTENDED))) {
                if (!empty($value) && $value !== \'disable_select\' && (int) $current[\'variant_id\'] === (int) $value) {
                    $skip = true;
                } elseif ((empty($value) || $value === \'disable_select\') && empty($current[\'variant_id\'])) {
                    $skip = true;
                }
            } elseif ($feature_type === ProductFeatures::NUMBER_FIELD) {
                if ((string) $current[\'value_int\'] === (string) $value) {
                    $skip = true;
                }
            } elseif ($feature_type === ProductFeatures::TEXT_FIELD) {
                if ((string) $current[\'value\'] === (string) $value) {
                    $skip = true;
                }
            } else {
                // Unknown feature type: always write to be safe
                $skip = false;
            }

            if ($skip) {
                continue;
            }
        }

        // Delete variants in current language',
        ],
    ];

    // --- cache-exim-find-feature ---
    $cache_old_start = "function fn_exim_find_feature(\$name, \$type, \$group_id, \$lang_code, \$company_id = null, \$field_name = 'internal_name')\n{\n    \$current_company_id";
    $cache_new_start = "function fn_exim_find_feature(\$name, \$type, \$group_id, \$lang_code, \$company_id = null, \$field_name = 'internal_name')\n{\n    static \$_cache = [];\n    \$cache_key = \"\$name|\$type|\$group_id|\$lang_code|\$company_id|\$field_name\";\n    if (isset(\$_cache[\$cache_key])) {\n        return \$_cache[\$cache_key];\n    }\n\n    \$current_company_id";

    $cache_old_end = "    return \$result;\n}";
    $cache_new_end = "    \$_cache[\$cache_key] = \$result;\n\n    return \$result;\n}";

    $patches['cache-exim-find-feature'] = [
        [
            'desc' => 'Add static cache at function start',
            'file' => 'app/schemas/exim/products.functions.php',
            'check' => 'static $_cache = [];',
            'old' => $cache_old_start,
            'new' => $cache_new_start,
        ],
        [
            'desc' => 'Store result in cache before return',
            'file' => 'app/schemas/exim/products.functions.php',
            'check' => '$_cache[$cache_key] = $result',
            'old' => $cache_old_end,
            'new' => $cache_new_end,
        ],
    ];

    // --- remove-lower-variant-lookup ---
    $patches['remove-lower-variant-lookup'] = [
        [
            'desc' => 'Remove LOWER() from WHERE clause',
            'file' => 'app/schemas/exim/products.functions.php',
            'old' => "'WHERE feature_id IN (?n) AND LOWER(variant) IN (?a) AND lang_code = ?s',",
            'new' => "'WHERE feature_id IN (?n) AND variant IN (?a) AND lang_code = ?s',",
        ],
    ];

    // --- profile-exim-import-loop ---
    $patches['profile-exim-import-loop'] = [
        [
            'desc' => 'Add MwlImportProfiler use statement',
            'file' => 'app/functions/fn.exim.php',
            'check' => 'use Tygh\\Addons\\MwlXlsx\\Import\\MwlImportProfiler;',
            'old' => "//\n// Export data using pattern",
            'new' => "//\nuse Tygh\\Addons\\MwlXlsx\\Import\\MwlImportProfiler;\n// Export data using pattern",
        ],
        [
            'desc' => 'Add profile flag before main loop',
            'file' => 'app/functions/fn.exim.php',
            'check' => '$_mwl_profile = !empty(',
            'old' => '    foreach ($import_data as $k => $v) {',
            'new' => '    $_mwl_profile = !empty($_REQUEST[\'profile_import\']);

    foreach ($import_data as $k => $v) {',
        ],
        [
            'desc' => 'Add find_product start profiling',
            'file' => 'app/functions/fn.exim.php',
            'check' => "stepStart('find_product')",
            'old' => '        $skip_get_primary_object_id = false;',
            'new' => '        if ($_mwl_profile) {
            MwlImportProfiler::instance()->stepStart(\'find_product\');
        }

        $skip_get_primary_object_id = false;',
        ],
        [
            'desc' => 'End find_product + start product tracking',
            'file' => 'app/functions/fn.exim.php',
            'check' => "stepEnd('find_product')",
            'old' => '        $skip_record = $stop_import = false;',
            'new' => '        if ($_mwl_profile) {
            $__profiler = MwlImportProfiler::instance();
            $__profiler->stepEnd(\'find_product\');
            if (!empty($primary_object_id[\'product_id\'])) {
                $__profiler->startProduct($primary_object_id[\'product_id\']);
            }
        }

        $skip_record = $stop_import = false;',
        ],
        [
            'desc' => 'Wrap pre_insert with profiling',
            'file' => 'app/functions/fn.exim.php',
            'check' => "stepStart('pre_insert')",
            'old' => '        fn_exim_import_prepare_groups($v[$main_lang], $pre_inserting_groups, $options, $skip_record, $stop_import);',
            'new' => '        if ($_mwl_profile) {
            MwlImportProfiler::instance()->stepStart(\'pre_insert\');
        }

        fn_exim_import_prepare_groups($v[$main_lang], $pre_inserting_groups, $options, $skip_record, $stop_import);

        if ($_mwl_profile) {
            MwlImportProfiler::instance()->stepEnd(\'pre_insert\');
        }',
        ],
        [
            'desc' => 'Add endProduct and progress echo on skip_record',
            'file' => 'app/functions/fn.exim.php',
            'check' => 'MwlImportProfiler::instance()->endProduct()',
            'old' => "        if (\$skip_record) {\n            if (\$_mwl_profile) {\n                MwlImportProfiler::instance()->endProduct();\n            }\n            continue;\n        }",
            'new' => "        if (\$skip_record) {\n            fn_set_progress('echo', 'Skipping ' . \$pattern['name'] . ' <b>' . implode(',', \$primary_object_id) . '</b>. ', false);\n            if (\$_mwl_profile) {\n                MwlImportProfiler::instance()->endProduct();\n            }\n            continue;\n        }",
        ],
        [
            'desc' => 'Add db_update profiling start',
            'file' => 'app/functions/fn.exim.php',
            'check' => "stepStart('db_update')",
            'old' => "            fn_set_progress('echo', __('importing_data'));",
            'new' => "            if (\$_mwl_profile) {\n                MwlImportProfiler::instance()->stepStart('db_update');\n            }\n\n            fn_set_progress('echo', __('importing_data'));",
        ],
    ];

    return $patches;
}
