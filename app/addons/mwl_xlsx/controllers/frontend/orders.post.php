<?php

use Tygh\Tygh;

if (!defined('BOOTSTRAP')) {
    die('Access denied');
}

if ($mode === 'search') {
    $view = Tygh::$app['view'];

    $orders = (array) $view->getTemplateVars('orders');
    if (!$orders || !function_exists('fn_vendor_communication_get_threads')) {
        $view->assign('mwl_xlsx_order_messages', []);
        return;
    }

    $order_ids = array_map(static function ($order) {
        return isset($order['order_id']) ? (int) $order['order_id'] : 0;
    }, $orders);
    $order_ids = array_filter($order_ids);

    if (!$order_ids) {
        $view->assign('mwl_xlsx_order_messages', []);
        return;
    }

    list($threads) = fn_vendor_communication_get_threads([
        'object_id' => $order_ids,
        'object_type' => VC_OBJECT_TYPE_ORDER,
        'items_per_page' => 0,
    ]);

    $order_messages = [];
    $auth = Tygh::$app['session']['auth'];
    $auth_user_id = isset($auth['user_id']) ? (int) $auth['user_id'] : 0;

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

            if (!isset($order_messages[$order_id])) {
                $order_messages[$order_id] = [
                    'total' => 0,
                    'has_unread' => false,
                    'thread_id' => $thread_id,
                ];
            } elseif (empty($order_messages[$order_id]['thread_id'])) {
                $order_messages[$order_id]['thread_id'] = $thread_id;
            }

            $order_messages[$order_id]['total'] += $messages_count;

            if ($order_messages[$order_id]['has_unread']) {
                continue;
            }

            $is_customer_message = isset($thread['last_message_user_type']) && $thread['last_message_user_type'] === VC_USER_TYPE_CUSTOMER;
            $is_own_message = $auth_user_id && isset($thread['last_message_user_id']) && (int) $thread['last_message_user_id'] === $auth_user_id;

            if (!$is_customer_message && !$is_own_message) {
                $order_messages[$order_id]['has_unread'] = true;
            }
        }
    }

    $view->assign('mwl_xlsx_order_messages', $order_messages);
}
