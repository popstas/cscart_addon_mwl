<?php
use Tygh\Registry;

if (!defined('BOOTSTRAP')) { die('Access denied'); }

function fn_mwl_xlsx_get_lists($user_id = null, $session_id = null)
{
    // If user is not authorized and session_id wasn't provided, use current session id
    if (!$user_id && !$session_id) {
        $session_id = Tygh::$app['session']->getID();
    }

    return db_get_array(
        "SELECT l.*, COUNT(lp.product_id) as products_count FROM ?:mwl_xlsx_lists AS l LEFT JOIN ?:mwl_xlsx_list_products AS lp ON l.list_id = lp.list_id WHERE ?w GROUP BY l.list_id ORDER BY created_at ASC",
        $user_id ? ['user_id' => $user_id] : ['session_id' => $session_id]
    );
}

function fn_mwl_xlsx_add($list_id, $product_id, $options = [], $amount = 1)
{
    db_replace_into('mwl_xlsx_list_products', [
        'list_id'        => $list_id,
        'product_id'     => $product_id,
        'product_options'=> serialize($options),
        'amount'         => $amount,
        'timestamp'      => TIME
    ]);
}
