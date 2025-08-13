<?php
use Tygh\Registry;

if (!defined('BOOTSTRAP')) { die('Access denied'); }

function fn_mwl_xlsx_get_lists($user_id = null, $session_id = null)
{
    // If user is not authorized and session_id wasn't provided, use current session id
    if (!$user_id && !$session_id) {
        $session_id = Tygh::$app['session']->getID();
    }

    $condition = $user_id ? ['user_id' => $user_id] : ['session_id' => $session_id];

    return db_get_array(
        "SELECT l.*, COUNT(lp.product_id) as products_count"
        . " FROM ?:mwl_xlsx_lists as l"
        . " LEFT JOIN ?:mwl_xlsx_list_products as lp ON lp.list_id = l.list_id"
        . " WHERE ?w GROUP BY l.list_id ORDER BY l.created_at ASC",
        $condition
    );
}

function fn_mwl_xlsx_get_list_products($list_id)
{
    $items = db_get_hash_array(
        "SELECT product_id, product_options, amount FROM ?:mwl_xlsx_list_products WHERE list_id = ?i",
        'product_id',
        $list_id
    );
    if (!$items) {
        return [];
    }

    $params = [
        'pid' => array_keys($items),
        'extend' => ['description', 'images']
    ];
    list($products) = fn_get_products($params);
    fn_gather_additional_products_data($products, [
        'get_icon'            => true,
        'get_detailed'        => true,
        'get_options'         => true,
        'get_features'        => true,
        'features_display_on' => 'A',
    ]);

    foreach ($products as $product_id => &$product) {
        $product['selected_options'] = empty($items[$product_id]['product_options']) ? [] : @unserialize($items[$product_id]['product_options']);
        $product['amount'] = $items[$product_id]['amount'];
    }

    return $products;
}

function fn_mwl_xlsx_collect_feature_names(array $products)
{
    $names = [];
    foreach ($products as $product) {
        if (empty($product['product_features'])) {
            continue;
        }
        foreach ($product['product_features'] as $feature_id => $feature) {
            $names[$feature_id] = $feature['description'];
        }
    }
    return $names;
}

function fn_mwl_xlsx_get_feature_text_values(array $features)
{
    $values = [];
    foreach ($features as $feature_id => $feature) {
        if (!empty($feature['value'])) {
            $values[$feature_id] = $feature['value'];
        } elseif (!empty($feature['variant'])) {
            $values[$feature_id] = $feature['variant'];
        } elseif (!empty($feature['variants'])) {
            $values[$feature_id] = implode(', ', array_column($feature['variants'], 'variant'));
        } else {
            $values[$feature_id] = null;
        }
    }
    return $values;
}

function fn_mwl_xlsx_add($list_id, $product_id, $options = [], $amount = 1)
{
    $serialized = serialize($options);
    $exists = db_get_field(
        "SELECT 1 FROM ?:mwl_xlsx_list_products WHERE list_id = ?i AND product_id = ?i AND product_options = ?s",
        $list_id,
        $product_id,
        $serialized
    );

    if ($exists) {
        return false;
    }

    db_query("INSERT INTO ?:mwl_xlsx_list_products ?e", [
        'list_id'        => $list_id,
        'product_id'     => $product_id,
        'product_options'=> $serialized,
        'amount'         => $amount,
        'timestamp'      => TIME
    ]);

    return true;
}
