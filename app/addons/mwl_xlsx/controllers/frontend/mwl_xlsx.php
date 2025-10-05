<?php

if (!defined('BOOTSTRAP')) { die('Access denied'); }
use Tygh\Addons\MwlXlsx\MediaList\ListExporter;
use Tygh\Registry;
use Tygh\Storage;
use Tygh\Http;
use Tygh\Mailer;
use Tygh\Enum\NotificationSeverity;
use Google\Client;
use Google\Service\Sheets;
use Google\Service\Sheets\Spreadsheet;

if ($mode === 'planfix_changed_status') {
    fn_mwl_planfix_handle_planfix_status_webhook();
    return [CONTROLLER_STATUS_NO_CONTENT];
}

if (!fn_mwl_xlsx_user_can_access_lists($auth)) {
    return [CONTROLLER_STATUS_DENIED];
}

if ($mode === 'manage') {
    if (!empty($auth['user_id'])) {
        $lists = fn_mwl_xlsx_get_lists($auth['user_id']);
    } else {
        $lists = fn_mwl_xlsx_get_lists(null, Tygh::$app['session']->getID());
    }
    Tygh::$app['view']->assign('lists', $lists);
    Tygh::$app['view']->assign('page_title', __('mwl_xlsx.my_lists'));
    Tygh::$app['view']->assign('breadcrumbs', [
        ['title' => __('home'), 'link' => fn_url('')],
        ['title' => __('mwl_xlsx.wishlists')]
    ]);
}

if ($mode === 'settings') {
    $settings = fn_mwl_xlsx_get_user_settings($auth);
    Tygh::$app['view']->assign('user_settings', $settings);
    Tygh::$app['view']->assign('page_title', __('mwl_xlsx.settings'));
    Tygh::$app['view']->assign('breadcrumbs', [
        ['title' => __('home'), 'link' => fn_url('')],
        ['title' => __('mwl_xlsx.wishlists'), 'link' => fn_url('mwl_xlsx.manage')],
        ['title' => __('mwl_xlsx.settings')]
    ]);
}

if ($mode === 'view') {
    // get list data
    $list_id = (int) $_REQUEST['list_id'];
    $list = fn_mwl_xlsx_get_list($list_id, $auth);
    if (!$list) {
        return [CONTROLLER_STATUS_NO_PAGE];
    }
    $list_exporter = new ListExporter();
    $products = $list_exporter->getListProducts($list_id, $auth, CART_LANGUAGE);

    Tygh::$app['view']->assign('is_mwl_xlsx_view', true);
    Tygh::$app['view']->assign('list', $list);
    Tygh::$app['view']->assign('products', $products);
    Tygh::$app['view']->assign('search', [
        'sort_by'    => 'popularity',
        'sort_order' => 'desc',
        'layout'     => 'products_without_options',
    ]);
    Tygh::$app['view']->assign('page_title', $list['name']);
    Tygh::$app['view']->assign('breadcrumbs', [
        ['title' => __('home'), 'link' => fn_url('')],
        ['title' => __('mwl_xlsx.wishlists'), 'link' => fn_url('mwl_xlsx.manage')],
        ['title' => $list['name']]
    ]);
}

if ($mode === 'export') {
    $vendor = dirname(__DIR__) . '/../vendor/autoload.php';
    if (file_exists($vendor)) {
        require_once $vendor;
    }

    // get list data
    $list_id = (int) $_REQUEST['list_id'];
    $list = fn_mwl_xlsx_get_list($list_id, $auth);
    if (!$list) {
        return [CONTROLLER_STATUS_NO_PAGE];
    }
    $list_exporter = new ListExporter();
    $list_data = $list_exporter->getListData($list_id, $auth, CART_LANGUAGE);
    $data = $list_data['data'];

    // load xlsx template
    fn_mwl_xlsx_load_vendor_autoloader();
    
    $storage    = Storage::instance('custom_files');
    $company_id = fn_get_runtime_company_id();
    $tpl        = db_get_row('SELECT path FROM ?:mwl_xlsx_templates WHERE company_id = ?i', (int) $company_id);
    if ($tpl && $storage->isExist($tpl['path'])) {
        $path = $storage->getAbsolutePath($tpl['path']);
        $xlsx = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
        $sheet = $xlsx->getActiveSheet();
    } else {
        $xlsx = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $xlsx->getActiveSheet();
    }

    // fill sheet with data
    $sheet->fromArray($data, null, 'A1');
    // auto size columns
    foreach (range('A', $sheet->getHighestDataColumn()) as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }

    // save file
    $filename = preg_replace('/[^\p{L}\p{N} _().-]/u', '_', $list['name']) . '.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($xlsx);
    $writer->save('php://output');
    exit;
}

if ($mode === 'export_google') {
    $vendor = dirname(__DIR__) . '/../vendor/autoload.php';
    if (file_exists($vendor)) {
        require_once $vendor;
    }

    // google auth
    $cred = Registry::get('addons.mwl_xlsx.google_credentials');
    if (!$cred) {
        return [CONTROLLER_STATUS_NO_PAGE];
    }
    $auth_config = json_decode($cred, true);
    if (!$auth_config) {
        return [CONTROLLER_STATUS_NO_PAGE];
    }
    $client = new Client();
    $client->setAuthConfig($auth_config);
    $client->setScopes([Sheets::SPREADSHEETS, \Google\Service\Drive::DRIVE_FILE]);
    $service = new Sheets($client);

    $folder_id = trim((string) Registry::get('addons.mwl_xlsx.google_drive_folder_id'));

    // get list data
    $list_id = (int) $_REQUEST['list_id'];
    $list = fn_mwl_xlsx_get_list($list_id, $auth);
    if (!$list) {
        return [CONTROLLER_STATUS_NO_PAGE];
    }
    $list_exporter = new ListExporter($service);
    $list_data = $list_exporter->getListData($list_id, $auth, CART_LANGUAGE);
    $data = $list_data['data'];

    // doc title
    $user = fn_get_user_info($auth['user_id']);
    $user_company = $user['company'] ?? '';
    $doc_title = $user_company ? $user_company . ' - ' . $list['name'] : $list['name'];

    // try to create the spreadsheet from an XLSX template (if configured)
    $storage    = Storage::instance('custom_files');
    $company_id = fn_get_runtime_company_id();
    $tpl        = db_get_row('SELECT path FROM ?:mwl_xlsx_templates WHERE company_id = ?i', (int) $company_id);
    $id = null;
    $created_via_drive = false;
    if ($tpl && $storage->isExist($tpl['path'])) {
        try {
            $drive = new \Google\Service\Drive($client);
            $path = $storage->getAbsolutePath($tpl['path']);
            $file_meta = new \Google\Service\Drive\DriveFile([
                'name'     => $doc_title,
                'mimeType' => 'application/vnd.google-apps.spreadsheet',
                'parents'  => $folder_id ? [$folder_id] : null,
            ]);
            $created = $drive->files->create($file_meta, [
                'data'                => @file_get_contents($path),
                'mimeType'           => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'uploadType'         => 'multipart',
                'fields'             => 'id, parents',
                'supportsAllDrives'  => true,
            ]);
            $id = $created->id;
            $created_via_drive = true; // created via Drive import from XLSX
        } catch (\Google\Service\Exception $e_tpl) {
            // Fall through to normal creation below
        }
    }

    // create spreadsheet without template
    if (!$id) {
        fn_mwl_xlsx_load_vendor_autoloader();
        
        $spreadsheet = new Spreadsheet([
            'properties' => ['title' => $doc_title]
        ]);
        try {
            $spreadsheet = $service->spreadsheets->create($spreadsheet, ['fields' => 'spreadsheetId']);
            $id = $spreadsheet->spreadsheetId;
        } catch (\Google\Service\Exception $e) {
            // Fallback: create the spreadsheet using Drive API (sometimes Sheets create is blocked by policy)
            try {
                $drive = new \Google\Service\Drive($client);
                $file_meta = new \Google\Service\Drive\DriveFile([
                    'name' => $doc_title,
                    'mimeType' => 'application/vnd.google-apps.spreadsheet',
                    // Place into specific folder if provided
                    'parents' => $folder_id ? [$folder_id] : null,
                ]);
                $created = $drive->files->create($file_meta, [
                    'fields' => 'id, parents',
                    'supportsAllDrives' => true,
                ]);
                $id = $created->id;
                $created_via_drive = true;
            } catch (\Google\Service\Exception $e2) {
                throw $e2;
            }
        }
    }

    // If we created via Sheets and a folder is specified, add the folder as a parent
    if (!$created_via_drive && $folder_id) {
        try {
            $drive = isset($drive) ? $drive : new \Google\Service\Drive($client);
            $drive->files->update($id, new \Google\Service\Drive\DriveFile(), [
                'addParents' => $folder_id,
                'supportsAllDrives' => true,
                'fields' => 'id, parents',
            ]);
        } catch (\Google\Service\Exception $e3) {
            // Non-fatal; continue
        }
    }

    // Enable link sharing: anyone with the link can view
    try {
        $drive = isset($drive) ? $drive : new \Google\Service\Drive($client);
        $perm = new \Google\Service\Drive\Permission([
            'type' => 'anyone',
            'role' => 'reader',
            'allowFileDiscovery' => false, // anyone with the link, not publicly discoverable
        ]);
        $drive->permissions->create($id, $perm, [
            'supportsAllDrives' => true,
            'fields' => 'id',
            'sendNotificationEmail' => false,
        ]);
    } catch (\Google\Service\Exception $e4) {
        // Non-fatal; continue
    }

    // fill sheet with data
    $ok = $list_exporter->fillGoogleSheet($id, $data);
    if (!$ok) {
        echo "Error filling Google Sheet\n";
        exit;
    }

    $is_debug = false;
    if ($is_debug) {
        echo "Created Spreadsheet ID: $id\n";
        echo "URL: https://docs.google.com/spreadsheets/d/$id\n";
        exit;
    }

    fn_redirect('https://docs.google.com/spreadsheets/d/' . $id, true);
    exit;
}

if ($mode === 'create_list' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $now  = date('Y-m-d H:i:s');
    $data = [
        'user_id'    => $auth['user_id'] ?? 0,
        'session_id' => $auth['user_id'] ? '' : Tygh::$app['session']->getID(),
        'name'       => $_REQUEST['name'],
        'created_at' => $now,
        'updated_at' => $now,
    ];
    $list_id = db_query("INSERT INTO ?:mwl_xlsx_lists ?e", $data);
    exit(json_encode(['list_id' => $list_id, 'name' => $data['name']]));
}

if ($mode === 'rename_list' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_id = $auth['user_id'] ?? null;
    $session_id = $user_id ? null : Tygh::$app['session']->getID();
    $name = trim((string) $_REQUEST['name']);
    $updated = $name === '' ? false : fn_mwl_xlsx_update_list_name((int) $_REQUEST['list_id'], $name, $user_id, $session_id);
    exit(json_encode(['success' => $updated, 'name' => $name]));
}

if ($mode === 'delete_list' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_id = $auth['user_id'] ?? null;
    $session_id = $user_id ? null : Tygh::$app['session']->getID();
    $deleted = fn_mwl_xlsx_delete_list((int) $_REQUEST['list_id'], $user_id, $session_id);
    exit(json_encode(['success' => $deleted]));
}

if ($mode === 'add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $list_id = (int) $_REQUEST['list_id'];
    $status = fn_mwl_xlsx_add($list_id, $_REQUEST['product_id'], $_REQUEST['product_options'] ?? [], 1);

    if ($status === 'added') {
        $list_name = db_get_field('SELECT name FROM ?:mwl_xlsx_lists WHERE list_id = ?i', $list_id);
        $message = __('mwl_xlsx.added', [
            '[list_name]' => htmlspecialchars($list_name, ENT_QUOTES, 'UTF-8'),
            '[list_url]'  => fn_url(fn_mwl_xlsx_url($list_id))
        ]);
        $success = true;
    } elseif ($status === 'limit') {
        $message = __('mwl_xlsx.list_limit_reached', ['[limit]' => Registry::get('addons.mwl_xlsx.max_list_items')]);
        $success = false;
    } else {
        $message = __('mwl_xlsx.already_exists');
        $success = false;
    }

    exit(json_encode(['success' => $success, 'message' => $message]));
}

if ($mode === 'remove' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $list_id = (int) $_REQUEST['list_id'];
    $removed = fn_mwl_xlsx_remove($list_id, $_REQUEST['product_id']);
    exit(json_encode(['success' => $removed]));
}

if ($mode === 'save_settings' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = [
        'price_multiplier' => isset($_REQUEST['price_multiplier']) ? (float) $_REQUEST['price_multiplier'] : 1.0,
        'price_append'     => isset($_REQUEST['price_append']) ? (string) $_REQUEST['price_append'] : 0,
        'round_to'         => isset($_REQUEST['round_to']) ? (float) $_REQUEST['round_to'] : 10.0,
    ];
    fn_mwl_xlsx_save_user_settings($auth, $data);
    fn_set_notification('N', __('notice'), __('mwl_xlsx.settings_saved'));
    return [CONTROLLER_STATUS_REDIRECT, 'mwl_xlsx.settings'];
}

if ($mode === 'add_list' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $list_id = (int) $_REQUEST['list_id'];
    $limit = (int) Registry::get('addons.mwl_xlsx.max_list_items');
    $current = (int) db_get_field('SELECT COUNT(*) FROM ?:mwl_xlsx_list_products WHERE list_id = ?i', $list_id);
    $remaining = $limit > 0 ? max($limit - $current, 0) : 0;

    $requested = array_unique(array_map('intval', (array) ($_REQUEST['product_ids'] ?? [])));
    $product_ids = $remaining > 0 ? array_slice($requested, 0, $remaining) : [];
    $limit_hit = $remaining === 0 || count($product_ids) < count($requested);

    $added = false;
    foreach ($product_ids as $pid) {
        $status = fn_mwl_xlsx_add($list_id, $pid, [], 1);
        if ($status === 'added') {
            $added = true;
        } elseif ($status === 'limit') {
            $limit_hit = true;
            break;
        }
    }

    $list_name = db_get_field('SELECT name FROM ?:mwl_xlsx_lists WHERE list_id = ?i', $list_id);
    if ($limit_hit && !$added) {
        $message = __('mwl_xlsx.list_limit_reached', ['[limit]' => $limit]);
        $success = false;
    } elseif ($limit_hit && $added) {
        $message = __('mwl_xlsx.list_limit_reached', ['[limit]' => $limit]);
        $success = true;
    } elseif ($added) {
        $message = __('mwl_xlsx.added', [
            '[list_name]' => htmlspecialchars($list_name, ENT_QUOTES, 'UTF-8'),
            '[list_url]'  => fn_url(fn_mwl_xlsx_url($list_id))
        ]);
        $success = true;
    } else {
        $message = __('mwl_xlsx.already_exists');
        $success = false;
    }

    exit(json_encode(['success' => $success, 'message' => $message]));
}

if ($mode === 'request_price_check' /* && $_SERVER['REQUEST_METHOD'] === 'POST' */) {
    $item_id = (string) ($_REQUEST['item_id'] ?? '');
    $field   = (string) ($_REQUEST['field'] ?? '');
    $value   = (int) ($_REQUEST['value'] ?? '');


    $company_id = fn_get_runtime_company_id();
    $user_id    = $_SESSION['auth']['user_id'] ?? 0;
    $user_name  = $_SESSION['auth']['user_id'] ? fn_get_user_name($user_id) : __('guest');
    $storefront = fn_url('', 'C');
    $product_url = fn_url('products.view?product_id=' . (int) $item_id, 'C');

    // Сообщение, которое уйдёт в Telegram/email
    $text = "Запрос проверки цены\n"
          . "— URL: {$product_url}\n"
        //   . "— Элемент: {$item_id}\n"
        //   . "— Поле: {$field}\n"
          . "— Текущая цена: {$value}\n"
          . "— Пользователь: {$user_name} (ID {$user_id})\n"
          . "— Время: " . date('Y-m-d H:i:s');

    $ok = true;
    $via = Registry::get('addons.mwl_xlsx.notify_via');

    if ($via === 'telegram') {
        $token   = trim((string) Registry::get('addons.mwl_xlsx.telegram_bot_token'));
        $chat_id = trim((string) Registry::get('addons.mwl_xlsx.telegram_chat_id'));
        if ($token && $chat_id) {
            // Telegram Bot API
            $url = "https://api.telegram.org/bot{$token}/sendMessage";
            $resp = Http::post($url, [
                'chat_id'    => $chat_id,
                'text'       => $text,
                'parse_mode' => 'HTML',
            ], [
                'timeout'    => 10,
                'headers'    => [
                    'Content-Type' => 'application/x-www-form-urlencoded; charset=UTF-8',
                ],
                'log_pre'    => 'mwl_xlsx.telegram_request',
                'log_result' => true,
            ]);
            $ok = $resp && ($resp = json_decode($resp, true)) && !empty($resp['ok']);
        } else {
            $ok = false;
        }
    } else { // email
        $to = trim((string) Registry::get('addons.mwl_xlsx.notify_email'));
        if (!$to) {
            $company_data = fn_get_company_communication_settings($company_id);
            $to = $company_data['company_orders_department'] ?? Registry::get('settings.Company.company_orders_department');
        }
        $ok = Mailer::sendMail([
            'to'        => $to,
            'from'      => 'default_company_orders_department',
            'data'      => [
                'subject' => 'Запрос проверки цены',
                'message' => nl2br($text),
            ],
            'template_code' => 'addons:mwl_xlsx/price_check',
            'company_id'    => $company_id,
            'is_html'       => true,
        ], 'C', CART_LANGUAGE);
    }

    if ($ok) {
        fn_set_notification(NotificationSeverity::NOTICE, '', __('mwl_xlsx.price_check_requested'));
    } else {
        fn_set_notification(NotificationSeverity::ERROR, '', __('mwl_xlsx.price_check_failed'));
    }

    // AJAX-ответ без перезагрузки
    if (defined('AJAX_REQUEST')) {
        // return [CONTROLLER_STATUS_OK, fn_url($_REQUEST['redirect_url'] ?? '/')];
        exit; // Ничего не возвращаем — хватит нотификации
    }

    // return [CONTROLLER_STATUS_OK, fn_url($_REQUEST['redirect_url'] ?? '/')];
    exit;
}