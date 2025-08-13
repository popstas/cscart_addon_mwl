<?php

if (!defined('BOOTSTRAP')) { die('Access denied'); }

if ($mode === 'manage') {
    if (!empty($auth['user_id'])) {
        $lists = fn_mwl_xlsx_get_lists($auth['user_id']);
    } else {
        $lists = fn_mwl_xlsx_get_lists(null, Tygh::$app['session']->getID());
    }
    Tygh::$app['view']->assign('lists', $lists);
}

if ($mode === 'list') {
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

    $feature_names = fn_mwl_xlsx_collect_feature_names($products);
    $feature_ids = array_keys($feature_names);

    $xlsx = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $xlsx->getActiveSheet();

    $header = array_merge([__('name')], array_values($feature_names));
    $data = [$header];

    foreach ($products as $p) {
        $row = [$p['product']];
        $values = fn_mwl_xlsx_get_feature_text_values($p['product_features'] ?? []);
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

if ($mode === 'add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $added = fn_mwl_xlsx_add($_REQUEST['list_id'], $_REQUEST['product_id'], $_REQUEST['product_options'] ?? [], 1);
    $message = $added ? __('mwl_xlsx.added') : __('mwl_xlsx.already_exists');
    exit(json_encode(['success' => true, 'message' => $message]));
}
