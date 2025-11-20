<?php
use Tygh\Tygh;
use Tygh\Registry;
use Tygh\Enum\UserTypes;
use Tygh\Addons\MwlXlsx\Service\SettingsBackup;


if (!defined('BOOTSTRAP')) { die('Access denied'); }

if ($mode === 'filters_sync') {
    $csv_path = trim((string) Registry::get('addons.mwl_xlsx.filters_csv_path'));

    if ($csv_path === '') {
        $message = __('mwl_xlsx.filters_sync_missing_path');
        echo $message . PHP_EOL;
        fn_mwl_xlsx_append_log($message);

        return [CONTROLLER_STATUS_NO_CONTENT];
    }

    $result = fn_mwl_xlsx_read_filters_csv($csv_path);

    if (!empty($result['errors'])) {
        foreach ($result['errors'] as $error) {
            echo '[error] ' . $error . PHP_EOL;
            fn_mwl_xlsx_append_log('[error] ' . $error);
        }

        return [CONTROLLER_STATUS_NO_CONTENT];
    }

    $rows = $result['rows'];

    if (!$rows) {
        $message = __('mwl_xlsx.filters_sync_error_empty');
        echo $message . PHP_EOL;
        fn_mwl_xlsx_append_log($message);

        return [CONTROLLER_STATUS_NO_CONTENT];
    }

    if (count($rows) > 100) {
        $message = __('mwl_xlsx.filters_sync_limit_exceeded');
        echo '[error] ' . $message . PHP_EOL;
        fn_mwl_xlsx_append_log('[error] ' . $message);

        return [CONTROLLER_STATUS_NO_CONTENT];
    }

    $service = fn_mwl_xlsx_filter_sync_service();
    $report = $service->syncPriceFilters($rows);

    $summary = $report->getSummary();

    // Output metrics in new format
    $metrics = [
        'created' => $summary['created'],
        'updated' => $summary['updated'],
        'skipped' => $summary['skipped'],
        'errors' => $summary['errors'],
    ];
    fn_mwl_xlsx_output_metrics($mode, $metrics);

    // Log detailed errors and skips for debugging
    foreach ($report->getErrors() as $error) {
        echo '[error] ' . $error . PHP_EOL;
    }

    foreach ($report->getSkipped() as $skip) {
        echo '[skip] ' . $skip . PHP_EOL;
    }

    // Append to log file
    fn_mwl_xlsx_append_log(sprintf('[%s] Metrics: %s', $mode, json_encode($metrics, JSON_UNESCAPED_UNICODE)));

    return [CONTROLLER_STATUS_NO_CONTENT];
}

if ($mode === 'publish_down_missing_products_outdated') {
    $service = fn_mwl_xlsx_publish_down_service();

    $period_setting = (int) Registry::get('addons.mwl_xlsx.publish_down_period');
    $period_seconds = $period_setting >= 0 ? $period_setting : 3600;

    $limit_setting = (int) Registry::get('addons.mwl_xlsx.publish_down_limit');
    $limit = $limit_setting > 0 ? $limit_setting : 0;

    $publish_summary = $service->publishDownOutdated($period_seconds, $limit);

    if (!empty($publish_summary['aborted_by_limit'])) {
        $error_message = __('mwl_xlsx.publish_down_limit_exceeded', [
            '[count]' => $publish_summary['outdated_total'] ?? 0,
            '[limit]' => $limit,
        ]);

        echo '[error] ' . $error_message . PHP_EOL;

        // Output metrics even when aborted
        $metrics = [
            'candidates' => $publish_summary['candidates'],
            'outdated_total' => $publish_summary['outdated_total'],
            'disabled' => count($publish_summary['disabled']),
            'errors' => count($publish_summary['errors']),
            'aborted_by_limit' => 1,
        ];
        fn_mwl_xlsx_output_metrics($mode, $metrics);

        fn_mwl_xlsx_append_log('[error] ' . $error_message);

        exit(1);
    }

    // Output metrics in new format
    $metrics = [
        'candidates' => $publish_summary['candidates'],
        'outdated_total' => $publish_summary['outdated_total'],
        'disabled' => count($publish_summary['disabled']),
        'errors' => count($publish_summary['errors']),
        'aborted_by_limit' => $publish_summary['aborted_by_limit'] ? 1 : 0,
    ];
    fn_mwl_xlsx_output_metrics($mode, $metrics);

    // Log detailed errors for debugging
    foreach ($publish_summary['errors'] as $error) {
        echo '[error] ' . $error . PHP_EOL;
        fn_mwl_xlsx_append_log('[error] ' . $error);
    }

    if ($publish_summary['limit_reached'] && $limit > 0) {
        $limit_message = __('mwl_xlsx.publish_down_limit_reached', ['[limit]' => $limit]);
        echo '[info] ' . $limit_message . PHP_EOL;
        fn_mwl_xlsx_append_log('[info] ' . $limit_message);
    }

    // Append to log file
    $log_payload = array_merge($metrics, [
        'disabled_product_ids' => $publish_summary['disabled'],
        'error_messages' => $publish_summary['errors'],
        'period_seconds' => $period_seconds,
        'limit' => $limit,
    ]);
    fn_mwl_xlsx_append_log(sprintf('[%s] Metrics: %s', $mode, json_encode($log_payload, JSON_UNESCAPED_UNICODE)));

    return [CONTROLLER_STATUS_NO_CONTENT];
}

if ($mode === 'publish_down_missing_products_csv') {
    $csv_path = Registry::get('config.dir.root') . '/var/files/products.csv';

    if (!file_exists($csv_path)) {
        $message = "CSV file not found: {$csv_path}";
        echo '[error] ' . $message . PHP_EOL;
        fn_mwl_xlsx_append_log('[publish_down_csv] ' . $message);

        return [CONTROLLER_STATUS_NO_CONTENT];
    }

    echo 'publish_down_missing_products_csv: reading CSV...' . PHP_EOL;

    $result = fn_mwl_xlsx_read_products_csv($csv_path);

    if (!empty($result['errors'])) {
        foreach ($result['errors'] as $error) {
            echo '[error] ' . $error . PHP_EOL;
            fn_mwl_xlsx_append_log('[publish_down_csv] ' . $error);
        }

        return [CONTROLLER_STATUS_NO_CONTENT];
    }

    $rows = $result['rows'];

    if (!$rows) {
        $message = 'CSV file is empty or has no valid rows';
        echo '[error] ' . $message . PHP_EOL;
        fn_mwl_xlsx_append_log('[publish_down_csv] ' . $message);

        return [CONTROLLER_STATUS_NO_CONTENT];
    }

    echo 'publish_down_missing_products_csv: processing ' . count($rows) . ' CSV rows...' . PHP_EOL;

    // Build lookup: variation_group_code => [product_code1, product_code2, ...]
    $csv_groups = [];
    foreach ($rows as $row) {
        $group_code = $row['variation_group_code'];
        $product_code = $row['product_code'];

        if (!isset($csv_groups[$group_code])) {
            $csv_groups[$group_code] = [];
        }

        $csv_groups[$group_code][] = $product_code;
    }

    $groups_processed = 0;
    $products_checked = 0;
    $disabled_product_ids = [];
    $errors = [];

    echo 'publish_down_missing_products_csv: found ' . count($csv_groups) . ' unique variation groups...' . PHP_EOL;

    foreach ($csv_groups as $group_code => $csv_product_codes) {
        // Query variation group ID by code
        $group_id = db_get_field(
            'SELECT id FROM ?:product_variation_groups WHERE code = ?s',
            $group_code
        );

        if (!$group_id) {
            $warning = "Variation group not found in database: {$group_code}";
            echo '[info] ' . $warning . PHP_EOL;
            fn_mwl_xlsx_append_log('[publish_down_csv] ' . $warning);
            continue;
        }

        // Get all product IDs in this variation group
        $group_product_ids = db_get_fields(
            'SELECT product_id FROM ?:product_variation_group_products WHERE group_id = ?i',
            $group_id
        );

        if (!$group_product_ids) {
            continue;
        }

        // Get product_code for all products in this group
        $db_products = db_get_hash_array(
            'SELECT product_id, product_code FROM ?:products WHERE product_id IN (?n)',
            'product_id',
            $group_product_ids
        );

        $products_checked += count($db_products);

        // Identify products to disable: those in DB but NOT in CSV
        $csv_product_codes_map = array_flip($csv_product_codes);

        foreach ($db_products as $product_id => $product) {
            $product_code = $product['product_code'];

            // If product_code is NOT in CSV, disable it
            if (!isset($csv_product_codes_map[$product_code])) {
                // Check current status before disabling
                $current_status = db_get_field(
                    'SELECT status FROM ?:products WHERE product_id = ?i',
                    $product_id
                );

                // Only disable if not already disabled
                if ($current_status !== 'D') {
                    $updated = db_query(
                        'UPDATE ?:products SET status = ?s WHERE product_id = ?i',
                        'D',
                        $product_id
                    );

                    if ($updated) {
                        $disabled_product_ids[] = (int) $product_id;
                        echo "[disabled] Product ID: {$product_id}, Code: {$product_code}, Group: {$group_code}" . PHP_EOL;
                        fn_mwl_xlsx_append_log("[publish_down_csv] Disabled product {$product_id} (code: {$product_code}, group: {$group_code})");
                    } else {
                        $error_msg = "Failed to disable product {$product_id} (code: {$product_code})";
                        $errors[] = $error_msg;
                        echo '[error] ' . $error_msg . PHP_EOL;
                        fn_mwl_xlsx_append_log('[publish_down_csv] ' . $error_msg);
                    }
                }
            }
        }

        $groups_processed++;
    }

    // Output metrics
    $metrics = [
        'groups_in_csv' => count($csv_groups),
        'groups_processed' => $groups_processed,
        'products_checked' => $products_checked,
        'disabled' => count($disabled_product_ids),
        'errors' => count($errors),
    ];
    fn_mwl_xlsx_output_metrics($mode, $metrics);

    // Append summary to log
    $log_payload = array_merge($metrics, [
        'disabled_product_ids' => $disabled_product_ids,
        'error_messages' => $errors,
    ]);
    fn_mwl_xlsx_append_log(sprintf('[%s] Metrics: %s', $mode, json_encode($log_payload, JSON_UNESCAPED_UNICODE)));

    return [CONTROLLER_STATUS_NO_CONTENT];
}

if ($mode === 'delete_unused_products') {
    echo 'delete_unused_products: check tables...' . PHP_EOL;
    $dry_run = false;

    if (defined('MWL_XLSX_DELETE_UNUSED_PRODUCTS_DRY_RUN')) {
        $dry_run = (bool) MWL_XLSX_DELETE_UNUSED_PRODUCTS_DRY_RUN;
    }

    if (isset($_REQUEST['dry_run'])) {
        $dry_run_value = (string) $_REQUEST['dry_run'];
        $dry_run = in_array(strtolower($dry_run_value), ['1', 'y', 'yes', 'true'], true);
    }

    $critical_tables = [
        '?:discussion' => [
            'columns' => ['object_id'],
            'condition' => "object_type = 'P'",
        ],
        '?:mwl_xlsx_list_products' => [
            'columns' => ['product_id'],
        ],
        '?:order_details' => [
            'columns' => ['product_id'],
        ],
        '?:product_reviews' => [
            'columns' => ['product_id'],
        ],
        '?:product_sales' => [
            'columns' => ['product_id'],
        ],
        '?:rma_return_products' => [
            'columns' => ['product_id'],
        ],
        '?:user_session_products' => [
            'columns' => ['product_id'],
        ],
        '?:product_subscriptions' => [
            'columns' => ['product_id'],
        ],
    ];

    $existing_tables = [];
    $referenced_product_ids = [];
    $table_prefix = (string) Registry::get('config.table_prefix') ?? '';

    foreach ($critical_tables as $table => $columns) {
        $table_name = str_replace('?:', $table_prefix, $table);
        $table_exists = (bool) db_get_field('SHOW TABLES LIKE ?l', $table_name);

        if (!$table_exists) {
            $warning_message = __('mwl_xlsx.delete_unused_products_table_missing', ['[table]' => $table_name]);
            echo '[info] ' . $warning_message . PHP_EOL;
            fn_mwl_xlsx_append_log('[delete_unused] ' . $warning_message);

            continue;
        }

        $table_columns = (array) ($columns['columns'] ?? []);
        $table_condition = isset($columns['condition']) && $columns['condition'] !== ''
            ? ' AND ' . $columns['condition']
            : '';

        if (!$table_columns) {
            continue;
        }

        $existing_tables[$table] = [
            'columns' => $table_columns,
            'condition' => $table_condition,
        ];

        foreach ($table_columns as $column) {
            $rows = db_get_fields("SELECT DISTINCT $column FROM $table WHERE $column IS NOT NULL$table_condition");

            foreach ($rows as $value) {
                $product_id = (int) $value;

                if ($product_id > 0) {
                    $referenced_product_ids[$product_id] = true;
                }
            }
        }
    }

    $all_product_ids = array_map(
        'intval',
        db_get_fields(
            'SELECT product_id FROM ?:products WHERE product_type IN(?a) AND status = ?s',
            ['P', 'V'],
            'D'
        )
    );

    if (!$all_product_ids) {
        $message = __('mwl_xlsx.delete_unused_products_empty');
        echo $message . PHP_EOL;
        fn_mwl_xlsx_append_log('[delete_unused] ' . $message);

        return [CONTROLLER_STATUS_NO_CONTENT];
    }

    $in_use_lookup = $referenced_product_ids;
    $unused_product_ids = [];

    foreach ($all_product_ids as $product_id) {
        if ($product_id <= 0) {
            continue;
        }

        if (!isset($in_use_lookup[$product_id])) {
            $unused_product_ids[] = $product_id;
        }
    }

    sort($unused_product_ids);

    $disabled_lookup = array_fill_keys($all_product_ids, true);
    $referenced_disabled_count = 0;

    foreach ($referenced_product_ids as $product_id => $flag) {
        if (isset($disabled_lookup[$product_id])) {
            $referenced_disabled_count++;
        }
    }

    // Output initial summary for context
    $summary_message = __('mwl_xlsx.delete_unused_products_summary', [
        '[disabled_total]' => count($all_product_ids),
        '[referenced_disabled]' => $referenced_disabled_count,
        '[candidates]' => count($unused_product_ids),
    ]);

    echo $summary_message . PHP_EOL;

    if ($dry_run) {
        $dry_run_message = __('mwl_xlsx.delete_unused_products_dry_run_enabled');
        echo '[info] ' . $dry_run_message . PHP_EOL;
        fn_mwl_xlsx_append_log('[delete_unused] ' . $dry_run_message);
    }

    if (!$unused_product_ids) {
        $message = __('mwl_xlsx.delete_unused_products_none');
        echo '[info] ' . $message . PHP_EOL;

        // Output metrics even when there are no candidates
        $metrics = [
            'disabled' => count($all_product_ids),
            'referenced_disabled' => $referenced_disabled_count,
            'deleted' => 0,
            'skipped' => 0,
            'errors' => 0,
        ];
        fn_mwl_xlsx_output_metrics($mode, $metrics);

        fn_mwl_xlsx_append_log('[delete_unused] ' . $message);

        return [CONTROLLER_STATUS_NO_CONTENT];
    }

    $deleted_products = [];
    $skipped_products = [];
    $errors = [];
    $planned_products = [];

    $check_references = static function (int $product_id) use ($existing_tables): array {
        $references = [];

        foreach ($existing_tables as $table => $columns) {
            foreach ($columns['columns'] as $column) {
                $condition = $columns['condition'] ?? '';
                $is_linked = (bool) db_get_field(
                    "SELECT 1 FROM $table WHERE $column = ?i$condition LIMIT 1",
                    $product_id
                );

                if ($is_linked) {
                    $references[] = sprintf('%s.%s', str_replace('?:', '', $table), $column);
                    break;
                }
            }
        }

        return $references;
    };

    foreach ($unused_product_ids as $product_id) {
        $references = $check_references($product_id);

        if ($references) {
            $reference_message = __('mwl_xlsx.delete_unused_products_skip', [
                '[product_id]' => $product_id,
                '[sources]' => implode(', ', $references),
            ]);

            echo '[skip] ' . $reference_message . PHP_EOL;
            fn_mwl_xlsx_append_log('[delete_unused] ' . $reference_message);

            $skipped_products[] = $product_id;

            continue;
        }

        if ($dry_run) {
            $planned_products[] = $product_id;

            $planned_message = __('mwl_xlsx.delete_unused_products_dry_run_entry', ['[product_id]' => $product_id]);
            echo '[dry-run] ' . $planned_message . PHP_EOL;
            fn_mwl_xlsx_append_log('[delete_unused] ' . $planned_message);

            continue;
        }

        $deleted = fn_delete_product($product_id);

        if ($deleted) {
            // seo_names deletes automatically when product is deleted
            // db_query('DELETE FROM ?:seo_names WHERE type = ?s AND object_id = ?i', 'p', $product_id);

            $deleted_message = __('mwl_xlsx.delete_unused_products_deleted', ['[product_id]' => $product_id]);
            echo '[deleted] ' . $deleted_message . PHP_EOL;
            fn_mwl_xlsx_append_log('[delete_unused] ' . $deleted_message);

            $deleted_products[] = $product_id;

            continue;
        }

        $error_message = __('mwl_xlsx.delete_unused_products_error', ['[product_id]' => $product_id]);
        echo '[error] ' . $error_message . PHP_EOL;
        fn_mwl_xlsx_append_log('[delete_unused] ' . $error_message);

        $errors[] = $product_id;
    }

    // Output metrics in new format
    $metrics = [
        'disabled' => count($all_product_ids),
        'referenced_disabled' => $referenced_disabled_count,
        'deleted' => count($deleted_products),
        'skipped' => count($skipped_products),
        'errors' => count($errors),
    ];
    fn_mwl_xlsx_output_metrics($mode, $metrics);

    // Log detailed info for debugging
    if ($dry_run && $planned_products) {
        $planned_message = __('mwl_xlsx.delete_unused_products_dry_run_list', ['[ids]' => implode(', ', $planned_products)]);
        echo '[info] ' . $planned_message . PHP_EOL;
    }

    if ($errors) {
        $errors_message = __('mwl_xlsx.delete_unused_products_errors_list', ['[ids]' => implode(', ', $errors)]);
        echo '[error] ' . $errors_message . PHP_EOL;
    }

    // Append to log file
    $log_payload = [
        'disabled' => count($all_product_ids),
        'referenced_disabled' => $referenced_disabled_count,
        'deleted' => count($deleted_products),
        'skipped' => count($skipped_products),
        'errors' => count($errors),
        'dry_run' => $dry_run,
        'deleted_product_ids' => $deleted_products,
        'skipped_product_ids' => $skipped_products,
        'error_product_ids' => $errors,
    ];
    fn_mwl_xlsx_append_log(sprintf('[%s] Metrics: %s', $mode, json_encode($log_payload, JSON_UNESCAPED_UNICODE)));

    return [CONTROLLER_STATUS_NO_CONTENT];
}

if ($mode === 'dev_reload_langs') {
    // импортирует var/langs/*/addons/mwl_xlsx.po в БД
    fn_reinstall_addon_files('mwl_xlsx');
    fn_clear_cache(); // чтобы сразу увидеть обновления
    return [CONTROLLER_STATUS_OK, 'addons.update?addon=mwl_xlsx'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $mode === 'settings') {
    $settings = $_REQUEST['mwl_xlsx'] ?? [];

    foreach ($settings as $name => $value) {
        if (is_array($value)) {
            $value = array_filter(array_map('intval', $value));
            $value = array_unique($value);
            $value = implode(',', $value);
        }

        \Tygh\Settings::instance()->updateValue($name, (string) $value, 'mwl_xlsx');
    }

    fn_set_notification('N', __('notice'), __('mwl_xlsx.settings_saved'));
    return [CONTROLLER_STATUS_OK, 'mwl_xlsx.settings'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $mode === 'planfix_create_task') {
    $order_id = isset($_REQUEST['order_id']) ? (int) $_REQUEST['order_id'] : 0;
    $order_info = $order_id ? fn_get_order_info($order_id, false, true, true, false) : [];

    if (!$order_id || !$order_info) {
        if (defined('AJAX_REQUEST')) {
            Tygh::$app['ajax']->assign('success', false);
            Tygh::$app['ajax']->assign('message', __('mwl_xlsx.planfix_error_order_not_found'));

            return [CONTROLLER_STATUS_NO_CONTENT];
        }

        fn_set_notification('E', __('error'), __('mwl_xlsx.planfix_error_order_not_found'));
        $return_url = !empty($_REQUEST['return_url']) ? (string) $_REQUEST['return_url'] : 'orders.manage';

        return [CONTROLLER_STATUS_OK, $return_url];
    }

    $planfix_service = fn_mwl_planfix_service();
    $result = $planfix_service->createTaskForOrder($order_id, $order_info);

    if (defined('AJAX_REQUEST')) {
        Tygh::$app['ajax']->assign('success', (bool) ($result['success'] ?? false));
        Tygh::$app['ajax']->assign('message', (string) ($result['message'] ?? __('mwl_xlsx.planfix_error_unknown')));

        if (!empty($result['link']) && is_array($result['link'])) {
            Tygh::$app['ajax']->assign('link', [
                'planfix_object_id' => (string) ($result['link']['planfix_object_id'] ?? ''),
                'planfix_url'       => (string) ($result['link']['planfix_url'] ?? ''),
            ]);
        }

        if (!empty($result['response']) && is_array($result['response'])) {
            Tygh::$app['ajax']->assign('mcp_response', $result['response']);
        }

        return [CONTROLLER_STATUS_NO_CONTENT];
    }

    if (!empty($result['success'])) {
        fn_set_notification('N', __('notice'), $result['message']);
    } else {
        $message = isset($result['message']) ? (string) $result['message'] : __('mwl_xlsx.planfix_error_unknown');
        fn_set_notification('E', __('error'), $message);
    }

    $return_url = !empty($_REQUEST['return_url'])
        ? (string) $_REQUEST['return_url']
        : 'orders.details?order_id=' . $order_id;

    return [CONTROLLER_STATUS_OK, $return_url];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $mode === 'planfix_bind_task') {
    $order_id = isset($_REQUEST['order_id']) ? (int) $_REQUEST['order_id'] : 0;
    $planfix_task_id = isset($_REQUEST['planfix_task_id']) ? (string) $_REQUEST['planfix_task_id'] : '';
    $planfix_object_type = isset($_REQUEST['planfix_object_type']) ? (string) $_REQUEST['planfix_object_type'] : 'task';

    if (!$order_id) {
        fn_set_notification('E', __('error'), __('mwl_xlsx.planfix_error_order_not_found'));
        $return_url = !empty($_REQUEST['return_url']) ? (string) $_REQUEST['return_url'] : 'orders.manage';

        return [CONTROLLER_STATUS_OK, $return_url];
    }

    $company_id = (int) db_get_field('SELECT company_id FROM ?:orders WHERE order_id = ?i', $order_id);

    $planfix_service = fn_mwl_planfix_service();
    $result = $planfix_service->bindTaskToOrder($order_id, $company_id, $planfix_task_id, $planfix_object_type);

    if (!empty($result['success'])) {
        fn_set_notification('N', __('notice'), $result['message']);
    } else {
        $message = isset($result['message']) ? (string) $result['message'] : __('mwl_xlsx.planfix_error_unknown');
        fn_set_notification('E', __('error'), $message);
    }

    $return_url = !empty($_REQUEST['return_url'])
        ? (string) $_REQUEST['return_url']
        : 'orders.details?order_id=' . $order_id;

    return [CONTROLLER_STATUS_OK, $return_url];
}

if ($mode === 'settings') {
    $settings = Registry::get('addons.mwl_xlsx');
    foreach (['authorized_usergroups', 'allowed_usergroups', 'hide_features'] as $field) {
        $settings[$field] = $settings[$field] ? array_map('intval', explode(',', $settings[$field])) : [];
    }

    \Tygh::$app['view']->assign('mwl_xlsx', $settings);
    \Tygh::$app['view']->assign('usergroups', fn_get_usergroups(['type' => 'C'], CART_LANGUAGE));

    [$features] = fn_get_product_features([
        'exclude_group' => true,
        'plain'         => true,
        'status'        => 'A',
    ], 0, DESCR_SL);

    $feature_options = [];
    foreach ($features as $feature) {
        if (empty($feature['feature_id'])) {
            continue;
        }

        $feature_options[] = [
            'feature_id'  => (int) $feature['feature_id'],
            'description' => (string) ($feature['description'] ?? ''),
        ];
    }

    usort($feature_options, static function (array $a, array $b) {
        return strcmp($a['description'], $b['description']);
    });

    \Tygh::$app['view']->assign('product_features', $feature_options);
}

if ($mode === 'backup_settings') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        SettingsBackup::backup();
        fn_set_notification('N', __('notice'), __('mwl_xlsx.settings_backup_done'));

        return [CONTROLLER_STATUS_REDIRECT, 'mwl_xlsx.backup_settings'];
    }

    $row = db_get_row('SELECT created_at FROM ?:mwl_settings_backup WHERE addon = ?s', 'mwl_xlsx');
    $last_backup = null;

    if ($row && !empty($row['created_at'])) {
        $date_format = Registry::get('settings.Appearance.date_format');
        $time_format = Registry::get('settings.Appearance.time_format');
        $format = trim($date_format . ' ' . $time_format);
        $last_backup = fn_date_format($row['created_at'], $format);
    }

    Tygh::$app['view']->assign('last_backup', $last_backup);
}


if ($mode === 'update_currencies') {
    $path = Registry::get('addons.mwl_xlsx.currencies_path');
    if (empty($path) || !file_exists($path)) {
        return [CONTROLLER_STATUS_NO_CONTENT];
    }

    $data = json_decode(file_get_contents($path), true);
    if (!is_array($data)) {
        return [CONTROLLER_STATUS_NO_CONTENT];
    }

    $symbols = db_get_hash_array('SELECT currency_code, symbol FROM ?:currencies', 'currency_code');

    foreach ($data as $code => $coef) {
        $code = strtoupper($code);
        $currency_id = db_get_field('SELECT currency_id FROM ?:currencies WHERE currency_code = ?s', $code);
        if ($currency_id) {
            $currency_data = [
                'currency_code' => $code,
                'symbol' => $symbols[$code]['symbol'],
                'coefficient'   => $coef,
            ];
            fn_update_currency($currency_data, $currency_id);
        }
    }

    return [CONTROLLER_STATUS_NO_CONTENT];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $mode === 'send_recover_to_users') {
    // Из настроек/контекста
    $runtime_company_id = fn_get_runtime_company_id();

    // 7 дней (можно вынести в настройку аддона)
    $ttl_custom = defined('SECONDS_IN_DAY') ? 7 * SECONDS_IN_DAY : 7 * 24 * 60 * 60;

    $user_ids = (array) ($_REQUEST['user_ids'] ?? []);
    $sent = 0;
    $skipped = 0;

    /** @var \Tygh\Mailer\Mailer $mailer */
    $mailer = Tygh::$app['mailer'];

    foreach ($user_ids as $uid) {
        $uid = (int) $uid;

        // Тянем необходимые поля пользователя
        $user = db_get_row(
            "SELECT user_id, email, user_type, status, company_id, storefront_id, lang_code, firstname, lastname
             FROM ?:users
             WHERE user_id = ?i",
            $uid
        );

        // Базовые проверки (аналогично fn_recover_password_generate_key)
        if (
            !$user
            || $user['user_type'] !== UserTypes::CUSTOMER
            || empty($user['email'])
            || $user['status'] === 'D' // Disabled
        ) {
            $skipped++;
            continue;
        }

        // Уважим лимит активных ключей (если функция доступна)
        if (function_exists('fn_recovery_password_get_ekeys_count')
            && defined('RECOVERY_PASSWORD_EKEYS_LIMIT')
        ) {
            $cnt = (int) fn_recovery_password_get_ekeys_count((int) $user['user_id']);
            if ($cnt >= (int) RECOVERY_PASSWORD_EKEYS_LIMIT) {
                $skipped++;
                continue;
            }
        }

        // Генерируем ekey с пользовательским TTL (ядро сохранит в хранилище восстановления)
        if (!defined('RECOVERY_PASSWORD_EKEY_TYPE')) {
            // На всякий случай: тип ключа должен быть определён ядром
            $skipped++;
            continue;
        }

        $ekey = fn_generate_ekey((int) $user['user_id'], RECOVERY_PASSWORD_EKEY_TYPE, (int) $ttl_custom);
        if (!$ekey) {
            $skipped++;
            continue;
        }

        // Формируем ссылку "Установить пароль"
        $invite_link = fn_url(
            'auth.recover_password?ekey=' . rawurlencode($ekey), // . '&email=' . rawurlencode($user['email']),
            'C',
            'https'
        );

        // Данные для шаблона письма
        $data = [
            'invite_link'     => $invite_link,
            'firstname'       => (string) ($user['firstname'] ?? ''),
            'lastname'        => (string) ($user['lastname'] ?? ''),
            'email'           => (string) $user['email'],
            'store_name'      => Registry::get('settings.Company.company_name'),
            'invite_ttl_days' => 7, // для вывода в письме
        ];

        // Отправляем письмо-приглашение через Mailer
        $mailer->send([
            'to'            => $user['email'],
            'from'          => 'company_users_department', // Настройки → Компания → отдел по работе с пользователями
            'tpl'           => 'addons/mwl_xlsx/invite_user.tpl',
            'data'          => $data,
            'company_id'    => $runtime_company_id ?: (int) $user['company_id'],
            'storefront_id' => (int) ($user['storefront_id'] ?? 0) ?: null,
        ], 'C', $user['lang_code'] ?: CART_LANGUAGE);

        $sent++;
    }

    fn_set_notification('N', __('notice'), __('mwl_xlsx.recover_links_sent', ['[n]' => $sent]) . " / skipped: {$skipped}");
    return [CONTROLLER_STATUS_OK, 'profiles.manage'];
}

if ($mode === 'check_group_features') {
    $group_id = null;
    $group_code = $_REQUEST['group_code'] ?? null;
    
    if ($group_code) {
        // Ищем группу по коду
        $group_id = db_get_field(
            "SELECT id FROM ?:product_variation_groups WHERE code = ?s LIMIT 1",
            $group_code
        );
        if ($group_id) {
            echo "Checking features for variation group '{$group_code}' (ID: {$group_id}):\n\n";
        } else {
            echo "Group '{$group_code}' not found\n";
            return [CONTROLLER_STATUS_NO_CONTENT];
        }
    } else {
        $group_id = (int) ($_REQUEST['group_id'] ?? 20968);
        echo "Checking features for variation group #{$group_id}:\n\n";
    }
    
    $features = db_get_array(
        "SELECT pvgf.feature_id, pfd.description " .
        "FROM ?:product_variation_group_features pvgf " .
        "LEFT JOIN ?:product_features_descriptions pfd ON pvgf.feature_id = pfd.feature_id AND pfd.lang_code = 'en' " .
        "WHERE pvgf.group_id = ?i " .
        "ORDER BY pvgf.feature_id",
        $group_id
    );
    
    if (empty($features)) {
        echo "No features found in group #{$group_id}\n";
    } else {
        echo "Features in group #{$group_id}:\n";
        foreach ($features as $feature) {
            echo "  - Feature #{$feature['feature_id']}: {$feature['description']}\n";
        }
    }
    
    // Также проверяем features из CSV
    echo "\n\nFeatures mentioned in CSV (for comparison):\n";
    $csv_features = [
        'Genre',
        'URL',
        'Author',
        'Special Date',
        'Text Limit Size',
        'Title Limit Size',
        'Images'
    ];
    
    foreach ($csv_features as $csv_feature) {
        $feature_id = db_get_field(
            "SELECT feature_id FROM ?:product_features_descriptions WHERE description = ?s AND lang_code = 'en' LIMIT 1",
            $csv_feature
        );
        if ($feature_id) {
            $in_group = db_get_field(
                "SELECT 1 FROM ?:product_variation_group_features WHERE group_id = ?i AND feature_id = ?i",
                $group_id, $feature_id
            );
            $status = $in_group ? '[IN GROUP]' : '[NOT IN GROUP]';
            echo "  - {$csv_feature} (Feature #{$feature_id}) {$status}\n";
        } else {
            echo "  - {$csv_feature}: NOT FOUND in DB\n";
        }
    }
    
    return [CONTROLLER_STATUS_NO_CONTENT];
}

if ($mode === 'import_prepare') {
    // Получаем флаг отладки
    $debug = isset($_REQUEST['debug']) ? (bool) $_REQUEST['debug'] : false;
    
    if ($debug) {
        fn_mwl_xlsx_log_debug('========================================');
        fn_mwl_xlsx_log_debug('Import prepare: syncing variation group features from CSV');
    }
    
    // Получаем путь к CSV файлу
    $csv_path = Registry::get('config.dir.root') . '/var/files/products.csv';
    if (isset($_REQUEST['csv_path'])) {
        $csv_path = trim((string) $_REQUEST['csv_path']);
    }
    
    if ($debug) {
        fn_mwl_xlsx_log_debug("CSV file path: {$csv_path}");
    }
    
    if (!file_exists($csv_path)) {
        $message = "CSV file not found: {$csv_path}";
        echo "[error] {$message}" . PHP_EOL;
        fn_mwl_xlsx_append_log($message);
        return [CONTROLLER_STATUS_NO_CONTENT];
    }
    
    if (!is_readable($csv_path)) {
        $message = "CSV file not readable: {$csv_path}";
        echo "[error] {$message}" . PHP_EOL;
        fn_mwl_xlsx_append_log($message);
        return [CONTROLLER_STATUS_NO_CONTENT];
    }
    
    // Читаем CSV файл
    $handle = fopen($csv_path, 'rb');
    if ($handle === false) {
        $message = "Failed to open CSV file: {$csv_path}";
        echo "[error] {$message}" . PHP_EOL;
        fn_mwl_xlsx_append_log($message);
        return [CONTROLLER_STATUS_NO_CONTENT];
    }
    
    $first_line = fgets($handle);
    if ($first_line === false) {
        fclose($handle);
        $message = "CSV file is empty: {$csv_path}";
        echo "[error] {$message}" . PHP_EOL;
        fn_mwl_xlsx_append_log($message);
        return [CONTROLLER_STATUS_NO_CONTENT];
    }
    
    $delimiter = fn_mwl_xlsx_detect_csv_delimiter($first_line);
    rewind($handle);
    
    $header = fgetcsv($handle, 0, $delimiter);
    if ($header === false) {
        fclose($handle);
        $message = "Failed to read CSV header";
        echo "[error] {$message}" . PHP_EOL;
        fn_mwl_xlsx_append_log($message);
        return [CONTROLLER_STATUS_NO_CONTENT];
    }
    
    // Нормализуем заголовки
    $normalized_header = [];
    foreach ($header as $index => $column) {
        $normalized_header[$index] = fn_mwl_xlsx_normalize_csv_header_value((string) $column, $index === 0);
    }
    
    $header_map = array_flip($normalized_header);
    
    // Проверяем наличие необходимых колонок
    $required_columns = ['variation group code', 'features'];
    $missing_columns = [];
    foreach ($required_columns as $required_column) {
        if (!isset($header_map[$required_column])) {
            $missing_columns[] = $required_column;
        }
    }
    
    if (!empty($missing_columns)) {
        fclose($handle);
        $message = "Missing required columns: " . implode(', ', $missing_columns);
        echo "[error] {$message}" . PHP_EOL;
        fn_mwl_xlsx_append_log($message);
        return [CONTROLLER_STATUS_NO_CONTENT];
    }
    
    $variation_group_code_index = $header_map['variation group code'];
    $features_index = $header_map['features'];
    
    // Собираем данные по группам
    $groups_data = []; // ['group_code' => ['features' => [...]]]
    
    $line_number = 1;
    while (($data = fgetcsv($handle, 0, $delimiter)) !== false) {
        $line_number++;
        
        if (count($data) < max($variation_group_code_index, $features_index) + 1) {
            if ($debug) {
                fn_mwl_xlsx_log_debug("Line {$line_number}: insufficient columns");
            }
            continue;
        }
        
        $variation_group_code = trim((string) ($data[$variation_group_code_index] ?? ''));
        $features_string = trim((string) ($data[$features_index] ?? ''));
        
        if ($variation_group_code === '' || $features_string === '') {
            // Пропускаем пустые строки
            continue;
        }
        
        // Парсим features из строки
        $features = fn_mwl_xlsx_parse_features_from_csv_row($features_string);
        
        if (empty($features)) {
            continue;
        }
        
        // Добавляем features в группу (объединяем все features из всех продуктов группы)
        if (!isset($groups_data[$variation_group_code])) {
            $groups_data[$variation_group_code] = ['features' => []];
        }
        
        // Объединяем features (используем union, чтобы не было дубликатов)
        $groups_data[$variation_group_code]['features'] = array_merge(
            $groups_data[$variation_group_code]['features'],
            $features
        );
    }
    
    fclose($handle);
    
    // Убираем дубликаты features для каждой группы (сохраняем последнее значение)
    foreach ($groups_data as $group_code => &$group_data) {
        // Для ассоциативных массивов используем array_unique по ключам
        $unique_features = [];
        foreach ($group_data['features'] as $name => $type) {
            $unique_features[$name] = $type;
        }
        $group_data['features'] = $unique_features;
    }
    unset($group_data);
    
    if ($debug) {
        fn_mwl_xlsx_log_debug("Found " . count($groups_data) . " variation groups in CSV");
    }
    
    if (empty($groups_data)) {
        if ($debug) {
            fn_mwl_xlsx_log_debug("No variation groups found in CSV");
        }
        return [CONTROLLER_STATUS_NO_CONTENT];
    }
    
    $total_added = 0;
    $total_removed = 0;
    $total_errors = 0;
    $total_warnings = 0;
    
    // Синхронизируем features для каждой группы
    foreach ($groups_data as $group_code => $group_data) {
        if ($debug) {
            fn_mwl_xlsx_log_debug("Processing group: {$group_code}");
        }
        
        // Находим группу по коду через SQL (метод findGroupByCode не существует)
        $group_id = db_get_field(
            "SELECT id FROM ?:product_variation_groups WHERE code = ?s LIMIT 1",
            $group_code
        );
        
        if (!$group_id) {
            // Это не ошибка - группа не должна существовать перед импортом
            if ($debug) {
                fn_mwl_xlsx_log_debug("Group '{$group_code}' not found, skipping");
            }
            continue;
        }
        
        if ($debug) {
            fn_mwl_xlsx_log_debug("Group ID: {$group_id}");
        }
        
        // Синхронизируем features
        $sync_result = fn_mwl_xlsx_sync_group_features_from_csv($group_id, $group_data['features'], $debug);
        
        $total_added += count($sync_result['added']);
        $total_removed += count($sync_result['removed']);
        $total_errors += count($sync_result['errors']);
        $total_warnings += count($sync_result['warnings']);
    }
    
    // Выводим итоговую статистику (всегда, не только в debug)
    $metrics = [
        'groups_processed' => count($groups_data),
        'features_added' => $total_added,
        'features_removed' => $total_removed,
        'errors' => $total_errors,
    ];
    fn_mwl_xlsx_output_metrics('import_prepare', $metrics);
    
    if ($debug && $total_warnings > 0) {
        fn_mwl_xlsx_log_debug("Warnings: {$total_warnings} (features not found - not variation features)");
    }
    if ($debug) {
        fn_mwl_xlsx_log_debug('========================================');
    }
    
    return [CONTROLLER_STATUS_NO_CONTENT];
}

if ($mode === 'check_feature_63') {
    $pids = [20743, 20744, 20746, 20747, 20748];
    
    echo "Checking Feature #63 (Text Limit Size) for products:\n\n";
    
    foreach ($pids as $pid) {
        $exists = db_get_field(
            "SELECT 1 FROM ?:product_features_values WHERE product_id = ?i AND feature_id = 63 AND lang_code = 'en'",
            $pid
        );
        $purpose = db_get_field("SELECT purpose FROM ?:product_features WHERE feature_id = 63");
        $variant_id = db_get_field(
            "SELECT variant_id FROM ?:product_features_values WHERE product_id = ?i AND feature_id = 63 AND lang_code = 'en'",
            $pid
        );
        
        echo "Product #{$pid}:\n";
        echo "  - Feature #63 exists: " . ($exists ? 'YES' : 'NO') . "\n";
        echo "  - Feature purpose: {$purpose}\n";
        if ($variant_id) {
            $variant_name = db_get_field(
                "SELECT variant FROM ?:product_feature_variant_descriptions WHERE variant_id = ?i AND lang_code = 'en'",
                $variant_id
            );
            echo "  - Variant ID: {$variant_id} ({$variant_name})\n";
        }
        echo "\n";
    }
    
    // Проверяем, что возвращает findAvailableFeatures
    echo "\nChecking findAvailableFeatures for Product #20743:\n";
    $product_repository = \Tygh\Addons\ProductVariations\ServiceProvider::getProductRepository();
    $features = $product_repository->findAvailableFeatures(20743);
    echo "Found " . count($features) . " features:\n";
    foreach ($features as $fid => $feat) {
        echo "  - Feature #{$fid}: " . ($feat['description'] ?? 'unknown') . " (purpose: " . ($feat['purpose'] ?? 'unknown') . ")\n";
    }
    
    return [CONTROLLER_STATUS_NO_CONTENT];
}

