<?php
use Tygh\Tygh;
use Tygh\Registry;
use Tygh\Enum\UserTypes;
use Tygh\Storage;
use Tygh\Addons\MwlXlsx\Service\SettingsBackup;
use Tygh\Addons\MwlXlsx\Cron\DeleteUnusedProductsRunner;
use Tygh\Addons\MwlXlsx\Cron\FiltersSyncRunner;
use Tygh\Addons\MwlXlsx\Cron\PublishDownRunner;
use Tygh\Addons\MwlXlsx\Import\ImportPrepareRunner;


if (!defined('BOOTSTRAP')) { die('Access denied'); }

if ($mode === 'filters_sync') {
    $runner = new FiltersSyncRunner(fn_mwl_xlsx_filter_sync_service());

    return $runner->run((string) Registry::get('addons.mwl_xlsx.filters_csv_path'), $mode);
}

if ($mode === 'publish_down_missing_products_outdated') {
    $runner = new PublishDownRunner(fn_mwl_xlsx_publish_down_service());

    $period_setting = (int) Registry::get('addons.mwl_xlsx.publish_down_period');
    $period_seconds = $period_setting >= 0 ? $period_setting : 3600;

    $limit_setting = (int) Registry::get('addons.mwl_xlsx.publish_down_limit');
    $limit = $limit_setting > 0 ? $limit_setting : 0;

    return $runner->disableOutdatedProducts($period_seconds, $limit, $mode);
}

if ($mode === 'publish_down_missing_products_csv') {
    $runner = new PublishDownRunner(fn_mwl_xlsx_publish_down_service());

    return $runner->disableMissingProductsFromCsv('', $mode);
}

if ($mode === 'delete_unused_products') {
    $dry_run = false;

    if (defined('MWL_XLSX_DELETE_UNUSED_PRODUCTS_DRY_RUN')) {
        $dry_run = (bool) MWL_XLSX_DELETE_UNUSED_PRODUCTS_DRY_RUN;
    }

    $runner = new DeleteUnusedProductsRunner();

    return $runner->run($dry_run, $mode);
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
    $runner = new ImportPrepareRunner();

    return $runner->run($_REQUEST);
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

if ($mode === 'check_images') {
    $dry_run = false;
    
    if (defined('MWL_XLSX_CHECK_IMAGES_DRY_RUN')) {
        $dry_run = (bool) MWL_XLSX_CHECK_IMAGES_DRY_RUN;
    }
    
    if (!defined('MAX_FILES_IN_DIR')) {
        define('MAX_FILES_IN_DIR', 1000);
    }
    
    echo "Checking images in database...\n";
    echo "Dry run: " . ($dry_run ? 'YES' : 'NO') . "\n\n";
    
    // Получаем все изображения (включая с пустым image_path)
    $images = db_get_array("SELECT image_id, image_path FROM ?:images ORDER BY image_id");
    
    if (empty($images)) {
        echo "No images found in database.\n";
        return [CONTROLLER_STATUS_NO_CONTENT];
    }
    
    $total_images = count($images);
    $missing_images = [];
    $checked_count = 0;
    $found_count = 0;
    $deleted_count = 0;
    
    $storage = Storage::instance('images');
    
    foreach ($images as $image) {
        $image_id = (int) $image['image_id'];
        $image_path = trim((string) ($image['image_path'] ?? ''));
        
        $checked_count++;
        $file_exists = false;
        $checked_paths = [];
        $object_types = [];
        
        // Проверяем пустой image_path
        if (empty($image_path)) {
            // Пустой image_path - считаем как отсутствующий файл
            $missing_images[] = [
                'image_id' => $image_id,
                'image_path' => '',
                'checked_paths' => [],
                'object_types' => [],
                'empty_path' => true,
            ];
            
            // Удаляем сломанное изображение из БД если dry_run = false
            if (!$dry_run) {
                // Удаляем связи из images_links
                db_query('UPDATE ?:images_links SET image_id = 0 WHERE image_id = ?i', $image_id);
                db_query('UPDATE ?:images_links SET detailed_id = 0 WHERE detailed_id = ?i', $image_id);
                
                // Удаляем описание изображения
                db_query('DELETE FROM ?:common_descriptions WHERE object_id = ?i AND object_holder = ?s', $image_id, 'images');
                
                // Удаляем саму запись изображения
                db_query('DELETE FROM ?:images WHERE image_id = ?i', $image_id);
                
                $deleted_count++;
            }
            
            // Прогресс каждые 100 изображений
            if ($checked_count % 100 === 0) {
                echo "Checked: {$checked_count} / {$total_images}\n";
            }
            
            continue;
        }
        
        // Получаем все object_type для этого изображения из images_links
        $object_types = db_get_fields(
            "SELECT DISTINCT object_type FROM ?:images_links WHERE image_id = ?i AND image_id != 0",
            $image_id
        );
        
        // Также проверяем, используется ли как detailed
        $is_detailed = db_get_field(
            "SELECT 1 FROM ?:images_links WHERE detailed_id = ?i AND detailed_id != 0 LIMIT 1",
            $image_id
        );
        if ($is_detailed) {
            if (!in_array('detailed', $object_types)) {
                $object_types[] = 'detailed';
            }
        }
        
        // Если нет связей, пробуем стандартные типы
        if (empty($object_types)) {
            $object_types = ['product', 'category', 'detailed', 'banner'];
        }
        
        // Проверяем каждый возможный путь
        foreach ($object_types as $object_type) {
            $subdir = floor($image_id / MAX_FILES_IN_DIR);
            $relative_path = $object_type . '/' . $subdir . '/' . $image_path;
            
            $checked_paths[] = $relative_path;
            
            if ($storage->isExist($relative_path)) {
                $file_exists = true;
                break;
            }
        }
        
        if ($file_exists) {
            $found_count++;
        } else {
            // Файл не найден
            $missing_images[] = [
                'image_id' => $image_id,
                'image_path' => $image_path,
                'checked_paths' => $checked_paths,
                'object_types' => $object_types,
                'empty_path' => false,
            ];
            
            // Удаляем сломанное изображение из БД если dry_run = false
            if (!$dry_run) {
                // Удаляем связи из images_links
                db_query('UPDATE ?:images_links SET image_id = 0 WHERE image_id = ?i', $image_id);
                db_query('UPDATE ?:images_links SET detailed_id = 0 WHERE detailed_id = ?i', $image_id);
                
                // Удаляем описание изображения
                db_query('DELETE FROM ?:common_descriptions WHERE object_id = ?i AND object_holder = ?s', $image_id, 'images');
                
                // Удаляем саму запись изображения
                db_query('DELETE FROM ?:images WHERE image_id = ?i', $image_id);
                
                $deleted_count++;
            }
        }
        
        // Прогресс каждые 100 изображений
        if ($checked_count % 100 === 0) {
            echo "Checked: {$checked_count} / {$total_images}\n";
        }
    }
    
    // Удаляем пустые связи (если image_id и detailed_id оба 0) после всех удалений
    if (!$dry_run && $deleted_count > 0) {
        $empty_pairs = db_get_fields(
            'SELECT pair_id FROM ?:images_links WHERE image_id = 0 AND detailed_id = 0'
        );
        if (!empty($empty_pairs)) {
            db_query('DELETE FROM ?:images_links WHERE pair_id IN (?n)', $empty_pairs);
        }
    }
    
    // Выводим результаты
    echo "\n" . str_repeat('=', 80) . "\n";
    echo "RESULTS\n";
    echo str_repeat('=', 80) . "\n\n";
    
    if (!empty($missing_images)) {
        $action_text = $dry_run ? "Would delete" : "Deleted";
        echo "Missing files (" . count($missing_images) . "):\n\n";
        
        if ($dry_run) {
            foreach ($missing_images as $missing) {
                echo "Image ID: {$missing['image_id']}\n";
                if (!empty($missing['empty_path'])) {
                    echo "  Image path: (EMPTY)\n";
                } else {
                    echo "  Image path: {$missing['image_path']}\n";
                    echo "  Object types: " . implode(', ', $missing['object_types']) . "\n";
                    echo "  Checked paths:\n";
                    foreach ($missing['checked_paths'] as $path) {
                        echo "    - {$path}\n";
                    }
                }
                echo "\n";
            }
        } else {
            // В режиме удаления показываем только краткую информацию
            echo "Broken images removed from database:\n";
            foreach ($missing_images as $missing) {
                $path_display = !empty($missing['empty_path']) ? '(EMPTY)' : $missing['image_path'];
                echo "  - Image ID: {$missing['image_id']}, path: {$path_display}\n";
            }
            echo "\n";
        }
    } else {
        echo "All images found!\n\n";
    }
    
    // Статистика
    $will_delete_count = count($missing_images);
    
    echo str_repeat('=', 80) . "\n";
    echo "STATISTICS\n";
    echo str_repeat('=', 80) . "\n";
    echo "Total images in database: {$total_images}\n";
    echo "Checked images: {$checked_count}\n";
    echo "Found files: {$found_count}\n";
    echo "Missing files: {$will_delete_count}\n";
    echo "Will delete: {$will_delete_count}\n";
    if (!$dry_run) {
        echo "Deleted from database: {$deleted_count}\n";
    }
    echo "\n";
    
    return [CONTROLLER_STATUS_NO_CONTENT];
}

