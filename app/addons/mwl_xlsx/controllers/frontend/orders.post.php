<?php

use Tygh\Tygh;

if (!defined('BOOTSTRAP')) {
    die('Access denied');
}

if ($mode === 'search') {
    $view = Tygh::$app['view'];

    $orders = (array) $view->getTemplateVars('orders');
    if (!$orders || !function_exists('fn_vendor_communication_get_threads')) {
        $view->assign('mwl_xlsx_order_messages_count', []);
        return;
    }

    $order_ids = array_map(static function ($order) {
        return isset($order['order_id']) ? (int) $order['order_id'] : 0;
    }, $orders);
    $order_ids = array_filter($order_ids);

    if (!$order_ids) {
        $view->assign('mwl_xlsx_order_messages_count', []);
        return;
    }

    list($threads) = fn_vendor_communication_get_threads([
        'object_id' => $order_ids,
        'object_type' => VC_OBJECT_TYPE_ORDER,
        'items_per_page' => 0,
    ]);

    $order_message_counts = [];

    if ($threads) {
        $thread_ids = array_keys($threads);
        $message_counts = [];

        if ($thread_ids) {
            $message_counts = db_get_hash_single_array(
                'SELECT thread_id, COUNT(*) AS messages_count FROM ?:vendor_communication_messages WHERE thread_id IN (?n) GROUP BY thread_id',
                ['thread_id', 'messages_count'],
                $thread_ids
            );
        }

        foreach ($threads as $thread) {
            $order_id = (int) $thread['object_id'];
            $thread_id = (int) $thread['thread_id'];
            $messages_count = isset($message_counts[$thread_id]) ? (int) $message_counts[$thread_id] : 0;

            if (!isset($order_message_counts[$order_id])) {
                $order_message_counts[$order_id] = 0;
            }

            $order_message_counts[$order_id] += $messages_count;
        }
    }

    $view->assign('mwl_xlsx_order_messages_count', $order_message_counts);
}
