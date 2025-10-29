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
    $summary_message = __('mwl_xlsx.filters_sync_summary', [
        '[created]' => $summary['created'],
        '[updated]' => $summary['updated'],
        '[skipped]' => $summary['skipped'],
        '[errors]' => $summary['errors'],
    ]);

    echo $summary_message . PHP_EOL;

    foreach ($report->getErrors() as $error) {
        echo '[error] ' . $error . PHP_EOL;
    }

    foreach ($report->getSkipped() as $skip) {
        echo '[skip] ' . $skip . PHP_EOL;
    }

    fn_mwl_xlsx_append_log($summary_message . ' | ' . json_encode($report->toArray(), JSON_UNESCAPED_UNICODE));

    return [CONTROLLER_STATUS_NO_CONTENT];
}

if ($mode === 'publish_down_missing_products') {
    $enabled = (string) Registry::get('addons.mwl_xlsx.publish_down_missing_products') === 'Y';

    if (!$enabled) {
        $message = __('mwl_xlsx.publish_down_disabled');
        echo $message . PHP_EOL;
        fn_mwl_xlsx_append_log($message);

        return [CONTROLLER_STATUS_NO_CONTENT];
    }

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
        fn_mwl_xlsx_append_log('[error] ' . $error_message);

        exit(1);
    }

    $summary_message = __('mwl_xlsx.publish_down_summary', [
        '[candidates]' => $publish_summary['candidates'],
        '[disabled]' => count($publish_summary['disabled']),
        '[period]' => $period_seconds,
    ]);

    echo $summary_message . PHP_EOL;

    foreach ($publish_summary['disabled'] as $product_id) {
        $line = __('mwl_xlsx.publish_down_disabled_entry', ['[product_id]' => $product_id]);
        echo '[disabled] ' . $line . PHP_EOL;
    }

    foreach ($publish_summary['errors'] as $error) {
        echo '[error] ' . $error . PHP_EOL;
        fn_mwl_xlsx_append_log('[error] ' . $error);
    }

    if ($publish_summary['limit_reached'] && $limit > 0) {
        $limit_message = __('mwl_xlsx.publish_down_limit_reached', ['[limit]' => $limit]);
        echo '[info] ' . $limit_message . PHP_EOL;
        fn_mwl_xlsx_append_log('[info] ' . $limit_message);
    }

    $log_payload = [
        'disabled' => $publish_summary['disabled'],
        'errors' => $publish_summary['errors'],
        'period_seconds' => $period_seconds,
        'limit' => $limit,
        'candidates' => $publish_summary['candidates'],
        'outdated_total' => $publish_summary['outdated_total'],
    ];

    fn_mwl_xlsx_append_log($summary_message . ' | ' . json_encode($log_payload, JSON_UNESCAPED_UNICODE));

    return [CONTROLLER_STATUS_NO_CONTENT];
}

if ($mode === 'delete_unused_products') {
    $dry_run = false;

    if (defined('MWL_XLSX_DELETE_UNUSED_PRODUCTS_DRY_RUN')) {
        $dry_run = (bool) MWL_XLSX_DELETE_UNUSED_PRODUCTS_DRY_RUN;
    }

    if (isset($_REQUEST['dry_run'])) {
        $dry_run_value = (string) $_REQUEST['dry_run'];
        $dry_run = in_array(strtolower($dry_run_value), ['1', 'y', 'yes', 'true'], true);
    }

    $critical_tables = [
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
        '?:wishlist_products' => [
            'columns' => ['product_id'],
        ],
        '?:user_session_products' => [
            'columns' => ['product_id'],
        ],
        '?:product_subscriptions' => [
            'columns' => ['product_id'],
        ],
        '?:discussion_posts' => [
            'columns' => ['object_id'],
            'condition' => "object_type = 'P'",
        ],
    ];

    $existing_tables = [];
    $referenced_product_ids = [];
    $db_prefix = (string) Registry::get('config.db_prefix');

    foreach ($critical_tables as $table => $columns) {
        $table_name = str_replace('?:', $db_prefix, $table);
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

    $all_product_ids = array_map('intval', db_get_fields('SELECT product_id FROM ?:products WHERE status = ?s', 'D'));

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

    $summary_message = __('mwl_xlsx.delete_unused_products_summary', [
        '[disabled_total]' => count($all_product_ids),
        '[referenced_disabled]' => $referenced_disabled_count,
        '[candidates]' => count($unused_product_ids),
    ]);

    echo $summary_message . PHP_EOL;
    fn_mwl_xlsx_append_log('[delete_unused] ' . $summary_message);

    if ($dry_run) {
        $dry_run_message = __('mwl_xlsx.delete_unused_products_dry_run_enabled');
        echo '[info] ' . $dry_run_message . PHP_EOL;
        fn_mwl_xlsx_append_log('[delete_unused] ' . $dry_run_message);
    }

    if (!$unused_product_ids) {
        $message = __('mwl_xlsx.delete_unused_products_none');
        echo '[info] ' . $message . PHP_EOL;
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
            db_query('DELETE FROM ?:seo_names WHERE object_type = ?s AND object_id = ?i', 'p', $product_id);

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

    if ($dry_run) {
        $result_message = __('mwl_xlsx.delete_unused_products_dry_run_result', [
            '[planned]' => count($planned_products),
            '[skipped]' => count($skipped_products),
            '[errors]' => count($errors),
        ]);
    } else {
        $result_message = __('mwl_xlsx.delete_unused_products_result', [
            '[deleted]' => count($deleted_products),
            '[skipped]' => count($skipped_products),
            '[errors]' => count($errors),
        ]);
    }

    echo $result_message . PHP_EOL;
    fn_mwl_xlsx_append_log('[delete_unused] ' . $result_message);

    if ($dry_run && $planned_products) {
        $planned_message = __('mwl_xlsx.delete_unused_products_dry_run_list', ['[ids]' => implode(', ', $planned_products)]);
        echo '[info] ' . $planned_message . PHP_EOL;
        fn_mwl_xlsx_append_log('[delete_unused] ' . $planned_message);
    }

    if (!$dry_run && $deleted_products) {
        $ids_message = __('mwl_xlsx.delete_unused_products_deleted_list', ['[ids]' => implode(', ', $deleted_products)]);
        echo '[info] ' . $ids_message . PHP_EOL;
        fn_mwl_xlsx_append_log('[delete_unused] ' . $ids_message);
    }

    if ($errors) {
        $errors_message = __('mwl_xlsx.delete_unused_products_errors_list', ['[ids]' => implode(', ', $errors)]);
        echo '[error] ' . $errors_message . PHP_EOL;
        fn_mwl_xlsx_append_log('[delete_unused] ' . $errors_message);
    }

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
            $value = implode(',', $value);
        }
        \Tygh\Settings::instance()->updateValue($name, $value, 'mwl_xlsx');
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
    foreach (['authorized_usergroups', 'allowed_usergroups'] as $field) {
        $settings[$field] = $settings[$field] ? explode(',', $settings[$field]) : [];
    }

    \Tygh::$app['view']->assign('mwl_xlsx', $settings);
    \Tygh::$app['view']->assign('usergroups', fn_get_usergroups(['type' => 'C'], CART_LANGUAGE));
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

