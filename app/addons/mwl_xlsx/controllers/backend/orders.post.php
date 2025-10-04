<?php

use Tygh\Enum\UserTypes;
use Tygh\Registry;
use Tygh\Tygh;

if (!defined('BOOTSTRAP')) {
    die('Access denied');
}

if ($mode === 'manage') {
    /** @var array $auth */
    global $auth;

    $view = Tygh::$app['view'];

    $orders = (array) $view->getTemplateVars('orders');
    $view->assign('mwl_xlsx_order_messages', []);
    $view->assign('mwl_xlsx_order_items', []);
    $view->assign('mwl_planfix_order_links', []);

    if (!$orders) {
        return;
    }

    $order_ids = array_map(static function ($order) {
        return isset($order['order_id']) ? (int) $order['order_id'] : 0;
    }, $orders);
    $order_ids = array_filter($order_ids);

    if (!$order_ids) {
        return;
    }

    $company_ids = array_map(static function ($order) {
        return isset($order['company_id']) ? (int) $order['company_id'] : 0;
    }, $orders);
    $company_ids = array_unique(array_filter($company_ids));

    $user_ids = array_map(static function ($order) {
        return isset($order['user_id']) ? (int) $order['user_id'] : 0;
    }, $orders);
    $user_ids = array_unique(array_filter($user_ids));

    $user_companies = [];

    if ($user_ids) {
        $user_companies = db_get_hash_single_array(
            'SELECT user_id, company FROM ?:users WHERE user_id IN (?n)',
            ['user_id', 'company'],
            $user_ids
        );
    }

    foreach ($orders as &$order) {
        $user_id = isset($order['user_id']) ? (int) $order['user_id'] : 0;
        $company = '';

        if ($user_id && isset($user_companies[$user_id])) {
            $company = (string) $user_companies[$user_id];
        }

        if (!isset($order['user']) || !is_array($order['user'])) {
            $order['user'] = [];
        }

        $order['user']['company'] = $company;
    }
    unset($order);

    $view->assign('orders', $orders);

    $order_items = [];
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
            DESCR_SL
        );
    }

    foreach ($order_products as $order_product) {
        $order_id = (int) $order_product['order_id'];

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

    $view->assign('mwl_xlsx_order_items', $order_items);

    $link_repository = fn_mwl_planfix_link_repository();
    $planfix_origin = (string) Registry::get('addons.mwl_xlsx.planfix_origin');
    $planfix_links_raw = $link_repository->findByEntities('order', $order_ids, $company_ids);
    $planfix_links = [];

    foreach ($planfix_links_raw as $entity_id => $link) {
        if (!is_array($link)) {
            continue;
        }

        $link['extra'] = fn_mwl_planfix_decode_link_extra($link['extra'] ?? null);
        $payload_out = isset($link['last_payload_out']) ? $link['last_payload_out'] : '';
        if (is_string($payload_out) && $payload_out !== '') {
            $decoded_payload = json_decode($payload_out, true);
            if (is_array($decoded_payload)) {
                $link['last_payload_out_decoded'] = $decoded_payload;
            }
        }

        $link['planfix_url'] = fn_mwl_planfix_build_object_url($link, $planfix_origin);
        $planfix_links[(int) $entity_id] = $link;
    }

    $view->assign('mwl_planfix_order_links', $planfix_links);
    $view->assign('mwl_planfix_can_create_links', true);

    if (!function_exists('fn_vendor_communication_get_threads') || !defined('VC_OBJECT_TYPE_ORDER')) {
        return;
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

            $is_customer_message = isset($thread['last_message_user_type']) && $thread['last_message_user_type'] === UserTypes::CUSTOMER;
            $is_own_message = $auth_user_id && isset($thread['last_message_user_id']) && (int) $thread['last_message_user_id'] === $auth_user_id;

            if ($is_customer_message && !$is_own_message) {
                $order_messages[$order_id]['has_unread'] = true;
            }
        }
    }

    $view->assign('mwl_xlsx_order_messages', $order_messages);
}

if ($mode === 'details' || $mode === 'update') {
    $order_id = isset($_REQUEST['order_id']) ? (int) $_REQUEST['order_id'] : 0;

    if ($order_id) {
        $company_id = (int) db_get_field('SELECT company_id FROM ?:orders WHERE order_id = ?i', $order_id);

        $link_repository = fn_mwl_planfix_link_repository();
        $link = $link_repository->findByEntity($company_id, 'order', $order_id);

        if ($link) {
            $link['extra'] = fn_mwl_planfix_decode_link_extra($link['extra'] ?? null);
            $payload_out = isset($link['last_payload_out']) ? $link['last_payload_out'] : '';
            if (is_string($payload_out) && $payload_out !== '') {
                $decoded_payload = json_decode($payload_out, true);
                if (is_array($decoded_payload)) {
                    $link['last_payload_out_decoded'] = $decoded_payload;
                }
            }
            $link['planfix_url'] = fn_mwl_planfix_build_object_url(
                $link,
                (string) Registry::get('addons.mwl_xlsx.planfix_origin')
            );
        }

        Tygh::$app['view']->assign('mwl_planfix_order_link', $link);

        $navigation_tabs = Registry::get('navigation.tabs');

        if (!isset($navigation_tabs['mwl_planfix'])) {
            $navigation_tabs['mwl_planfix'] = [
                'title' => __('mwl_xlsx.planfix_tab_title'),
                'js' => true,
            ];

            Registry::set('navigation.tabs', $navigation_tabs);
        }
    }
}
