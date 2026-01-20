<?php

use Tygh\Enum\UserTypes;
use Tygh\Tygh;

if (!defined('BOOTSTRAP')) {
    die('Access denied');
}

if ($mode === 'search') {
    $view = Tygh::$app['view'];

    $orders = (array) $view->getTemplateVars('orders');
    $view->assign('mwl_xlsx_order_messages', []);
    $view->assign('mwl_xlsx_order_items', []);

    if (!$orders || !function_exists('fn_vendor_communication_get_threads')) {
        return;
    }

    $order_ids = array_map(static function ($order) {
        return isset($order['order_id']) ? (int) $order['order_id'] : 0;
    }, $orders);
    $order_ids = array_filter($order_ids);

    if (!$order_ids) {
        return;
    }

    $order_items = [];
    
    // Get wallet recharge order IDs
    $wallet_recharge_order_ids = [];
    if ($order_ids) {
        $wallet_recharge_order_ids = db_get_fields(
            'SELECT order_id FROM ?:wallet_offline_payment WHERE order_id IN (?n)',
            $order_ids
        );
        $wallet_recharge_order_ids = array_map('intval', $wallet_recharge_order_ids);
    }
    
    $order_products = db_get_array(
        'SELECT item_id, order_id, product_id, product_code, amount, extra FROM ?:order_details WHERE order_id IN (?n) ORDER BY order_id, item_id',
        $order_ids
    );

    $product_ids = [];

    foreach ($order_products as &$order_product) {
        $order_product['extra'] = is_string($order_product['extra']) ? $order_product['extra'] : '';

        if ($order_product['extra'] !== '') {
            if (PHP_VERSION_ID >= 70000) {
                $order_product['extra'] = @unserialize($order_product['extra'], ['allowed_classes' => false]);
            } else {
                $order_product['extra'] = @unserialize($order_product['extra']);
            }
        } else {
            $order_product['extra'] = [];
        }

        if (!is_array($order_product['extra'])) {
            $order_product['extra'] = [];
        }

        $product_name = '';

        if (!empty($order_product['extra']['product'])) {
            $product_name = (string) $order_product['extra']['product'];
        }

        $order_product['product_name'] = $product_name;

        if ($product_name === '' && !empty($order_product['product_id'])) {
            $product_ids[] = (int) $order_product['product_id'];
        }
    }
    unset($order_product);

    $product_names = [];
    $product_ids = array_unique(array_filter($product_ids));

    if ($product_ids) {
        $product_names = db_get_hash_single_array(
            'SELECT product_id, product FROM ?:product_descriptions WHERE product_id IN (?n) AND lang_code = ?s',
            ['product_id', 'product'],
            $product_ids,
            $lang_code
        );
    }

    // Process wallet recharge orders first
    foreach ($wallet_recharge_order_ids as $wallet_order_id) {
        if (!isset($order_items[$wallet_order_id])) {
            $order_items[$wallet_order_id] = [];
        }
        $order_items[$wallet_order_id][] = [
            'product' => __('wallet_recharge'),
            'amount' => 1,
        ];
    }

    // Process regular order products (skip wallet recharge orders)
    foreach ($order_products as $order_product) {
        $order_id = (int) $order_product['order_id'];

        // Skip if this order is a wallet recharge order
        if (in_array($order_id, $wallet_recharge_order_ids, true)) {
            continue;
        }

        if (!isset($order_items[$order_id])) {
            $order_items[$order_id] = [];
        }

        $product_name = $order_product['product_name'];

        if ($product_name === '' && isset($product_names[$order_product['product_id']])) {
            $product_name = (string) $product_names[$order_product['product_id']];
        }

        if ($product_name === '') {
            $product_name = !empty($order_product['product_code'])
                ? (string) $order_product['product_code']
                : sprintf('#%d', (int) $order_product['product_id']);
        }

        $order_items[$order_id][] = [
            'product' => $product_name,
            'amount' => (int) $order_product['amount'],
        ];
    }

    list($threads) = fn_vendor_communication_get_threads([
        'object_id' => $order_ids,
        'object_type' => VC_OBJECT_TYPE_ORDER,
        'items_per_page' => 0,
    ]);

    $order_messages = [];
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
            $last_message = isset($thread['last_message']) ? trim((string) $thread['last_message']) : '';
            $last_updated = isset($thread['last_updated']) ? (int) $thread['last_updated'] : 0;

            if (!isset($order_messages[$order_id])) {
                $order_messages[$order_id] = [
                    'total' => 0,
                    'has_unread' => false,
                    'thread_id' => $thread_id,
                    'last_message' => $last_message,
                    'last_updated' => $last_updated,
                ];
            } elseif (empty($order_messages[$order_id]['thread_id'])) {
                $order_messages[$order_id]['thread_id'] = $thread_id;
                $order_messages[$order_id]['last_message'] = $last_message;
                $order_messages[$order_id]['last_updated'] = $last_updated;
            }

            $order_messages[$order_id]['total'] += $messages_count;

            $current_last_updated = isset($order_messages[$order_id]['last_updated'])
                ? (int) $order_messages[$order_id]['last_updated']
                : 0;

            if ($last_updated > $current_last_updated) {
                $order_messages[$order_id]['thread_id'] = $thread_id;
                $order_messages[$order_id]['last_message'] = $last_message;
                $order_messages[$order_id]['last_updated'] = $last_updated;
            } elseif ($order_messages[$order_id]['thread_id'] === $thread_id && $last_message !== '') {
                $order_messages[$order_id]['last_message'] = $last_message;

                if ($last_updated) {
                    $order_messages[$order_id]['last_updated'] = $last_updated;
                }
            }

            if ($order_messages[$order_id]['has_unread']) {
                continue;
            }

            $is_customer_message = isset($thread['last_message_user_type']) && $thread['last_message_user_type'] === UserTypes::CUSTOMER;
            $is_own_message = $auth_user_id && isset($thread['last_message_user_id']) && (int) $thread['last_message_user_id'] === $auth_user_id;

            if (!$is_customer_message && !$is_own_message) {
                $order_messages[$order_id]['has_unread'] = true;
            }
        }
    }

    $view->assign('mwl_xlsx_order_messages', $order_messages);
    $view->assign('mwl_xlsx_order_items', $order_items);
}
