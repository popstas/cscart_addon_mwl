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
