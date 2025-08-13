<?php

if (!defined('BOOTSTRAP')) { die('Access denied'); }
use Tygh\Registry;

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

if ($mode === 'list' || $mode === 'view') {
    $list_id = (int) $_REQUEST['list_id'];
    if (!empty($auth['user_id'])) {
        $list = db_get_row("SELECT * FROM ?:mwl_xlsx_lists WHERE list_id = ?i AND user_id = ?i", $list_id, $auth['user_id']);
    } else {
        $list = db_get_row("SELECT * FROM ?:mwl_xlsx_lists WHERE list_id = ?i AND session_id = ?s", $list_id, Tygh::$app['session']->getID());
    }
    if ($list) {
        $products = fn_mwl_xlsx_get_list_products($list_id, CART_LANGUAGE);
        Tygh::$app['view']->assign('list', $list);
        Tygh::$app['view']->assign('products', $products);
        Tygh::$app['view']->assign('page_title', $list['name']);
        Tygh::$app['view']->assign('breadcrumbs', [
            ['title' => __('home'), 'link' => fn_url('')],
            ['title' => __('mwl_xlsx.wishlists'), 'link' => fn_url('mwl_xlsx.manage')],
            ['title' => $list['name']]
        ]);
    } else {
        return [CONTROLLER_STATUS_NO_PAGE];
    }
}

if ($mode === 'export') {
    $list_id = (int) $_REQUEST['list_id'];
    if (!empty($auth['user_id'])) {
        $list = db_get_row("SELECT * FROM ?:mwl_xlsx_lists WHERE list_id = ?i AND user_id = ?i", $list_id, $auth['user_id']);
    } else {
        $list = db_get_row("SELECT * FROM ?:mwl_xlsx_lists WHERE list_id = ?i AND session_id = ?s", $list_id, Tygh::$app['session']->getID());
    }
    if (!$list) {
        return [CONTROLLER_STATUS_NO_PAGE];
    }

    $vendor = dirname(__DIR__) . '/../vendor/autoload.php';
    if (file_exists($vendor)) {
        require_once $vendor;
    }

    $products = fn_mwl_xlsx_get_list_products($list_id, CART_LANGUAGE);

    $feature_names = fn_mwl_xlsx_collect_feature_names($products, CART_LANGUAGE);
    $feature_ids = array_keys($feature_names);

    $xlsx = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $xlsx->getActiveSheet();

    $header = array_merge([__('name')], array_values($feature_names));
    $data = [$header];

    foreach ($products as $p) {
        $row = [$p['product']];
        $values = fn_mwl_xlsx_get_feature_text_values($p['product_features'] ?? [], CART_LANGUAGE);
        foreach ($feature_ids as $feature_id) {
            $row[] = $values[$feature_id] ?? null;
        }
        $data[] = $row;
    }

    $sheet->fromArray($data, null, 'A1');
    foreach (range('A', $sheet->getHighestDataColumn()) as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }

    $filename = preg_replace('/[^A-Za-z0-9_\-]/', '_', $list['name']) . '.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($xlsx);
    $writer->save('php://output');
    exit;
}

if ($mode === 'create_list' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = [
        'user_id'    => $auth['user_id'] ?? 0,
        'session_id' => $auth['user_id'] ? '' : Tygh::$app['session']->getID(),
        'name'       => $_REQUEST['name'],
        'created_at' => date('Y-m-d H:i:s')
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
    $added = fn_mwl_xlsx_add($list_id, $_REQUEST['product_id'], $_REQUEST['product_options'] ?? [], 1);

    if ($added) {
        $list_name = db_get_field('SELECT name FROM ?:mwl_xlsx_lists WHERE list_id = ?i', $list_id);
        $message = __('mwl_xlsx.added', [
            '[list_name]' => htmlspecialchars($list_name, ENT_QUOTES, 'UTF-8'),
            '[list_url]'  => fn_url(fn_mwl_xlsx_url($list_id))
        ]);
    } else {
        $message = __('mwl_xlsx.already_exists');
    }

    exit(json_encode(['success' => true, 'message' => $message]));
}

if ($mode === 'add_list' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $list_id = (int) $_REQUEST['list_id'];
    $product_ids = array_slice((array) ($_REQUEST['product_ids'] ?? []), 0, 20);
    foreach ($product_ids as $pid) {
        fn_mwl_xlsx_add($list_id, (int) $pid, [], 1);
    }

    $list_name = db_get_field('SELECT name FROM ?:mwl_xlsx_lists WHERE list_id = ?i', $list_id);
    $message = __('mwl_xlsx.added', [
        '[list_name]' => htmlspecialchars($list_name, ENT_QUOTES, 'UTF-8'),
        '[list_url]'  => fn_url(fn_mwl_xlsx_url($list_id))
    ]);

    exit(json_encode(['success' => true, 'message' => $message]));
}
