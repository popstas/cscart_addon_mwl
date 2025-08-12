<?php
use Tygh\Registry;

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
        $products = fn_mwl_xlsx_get_list_products($list_id);
        Tygh::$app['view']->assign('list', $list);
        Tygh::$app['view']->assign('products', $products);
    } else {
        return [CONTROLLER_STATUS_NO_PAGE];
    }
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
    fn_mwl_xlsx_add($_REQUEST['list_id'], $_REQUEST['product_id'], $_REQUEST['product_options'] ?? [], 1);
    exit(json_encode(['success' => true]));
}
