<?php
use Tygh\Registry;
use Tygh\Storage;

if (!defined('BOOTSTRAP')) { die('Access denied'); }

function fn_mwl_xlsx_url($list_id)
{
    $list_id = (int) $list_id;
    return "media-lists/{$list_id}";
}

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

function fn_mwl_xlsx_get_list_products($list_id, $lang_code = CART_LANGUAGE)
{
    $items = db_get_hash_array(
        "SELECT product_id, product_options, amount FROM ?:mwl_xlsx_list_products WHERE list_id = ?i",
        'product_id',
        $list_id
    );
    if (!$items) {
        return [];
    }

    $auth = Tygh::$app['session']['auth'];
    $products = [];
    foreach ($items as $product_id => $item) {
        $product = fn_get_product_data($product_id, $auth, $lang_code);
        if ($product) {
            $features = fn_get_product_features_list([
                'product_id'          => $product_id,
                'features_display_on' => 'A',
            ], 0, $lang_code);

            $product['product_features'] = $features;
            $product['selected_options'] = empty($item['product_options']) ? [] : @unserialize($item['product_options']);
            $product['amount'] = $item['amount'];
            $product['mwl_list_id'] = $list_id;
            $products[] = $product;
        }
    }

    return $products;
}

function fn_mwl_xlsx_collect_feature_names(array $products, $lang_code = CART_LANGUAGE)
{
    $feature_ids = [];
    foreach ($products as $product) {
        if (empty($product['product_features'])) {
            continue;
        }
        foreach ($product['product_features'] as $feature) {
            if (!empty($feature['feature_id'])) {
                $feature_ids[] = $feature['feature_id'];
            }
        }
    }

    $feature_ids = array_unique($feature_ids);
    if (!$feature_ids) {
        return [];
    }

    list($features) = fn_get_product_features([
        'feature_id' => $feature_ids,
    ], 0, $lang_code);

    $names = [];
    foreach ($features as $feature) {
        $names[$feature['feature_id']] = $feature['description'];
    }

    return $names;
}

function fn_mwl_xlsx_get_feature_text_values(array $features, $lang_code = CART_LANGUAGE)
{
    $values = [];
    foreach ($features as $feature) {
        $feature_id = $feature['feature_id'];
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

function fn_mwl_xlsx_remove($list_id, $product_id)
{
    $deleted = db_query(
        "DELETE FROM ?:mwl_xlsx_list_products WHERE list_id = ?i AND product_id = ?i",
        $list_id,
        $product_id
    );

    return (bool) $deleted;
}

function fn_mwl_xlsx_update_list_name($list_id, $name, $user_id = null, $session_id = null)
{
    if (!$user_id && !$session_id) {
        $session_id = Tygh::$app['session']->getID();
    }
    $condition = $user_id ? ['list_id' => $list_id, 'user_id' => $user_id] : ['list_id' => $list_id, 'session_id' => $session_id];
    $exists = db_get_field('SELECT list_id FROM ?:mwl_xlsx_lists WHERE ?w', $condition);
    if ($exists) {
        db_query('UPDATE ?:mwl_xlsx_lists SET name = ?s WHERE list_id = ?i', $name, $list_id);
        return true;
    }
    return false;
}

function fn_mwl_xlsx_delete_list($list_id, $user_id = null, $session_id = null)
{
    if (!$user_id && !$session_id) {
        $session_id = Tygh::$app['session']->getID();
    }
    $condition = $user_id ? ['list_id' => $list_id, 'user_id' => $user_id] : ['list_id' => $list_id, 'session_id' => $session_id];
    $exists = db_get_field('SELECT list_id FROM ?:mwl_xlsx_lists WHERE ?w', $condition);
    if ($exists) {
        db_query('DELETE FROM ?:mwl_xlsx_lists WHERE list_id = ?i', $list_id);
        db_query('DELETE FROM ?:mwl_xlsx_list_products WHERE list_id = ?i', $list_id);
        return true;
    }
    return false;
}

function fn_mwl_xlsx_uninstall()
{
    db_query("DROP TABLE IF EXISTS ?:mwl_xlsx_templates");
    Storage::instance('custom_files')->deleteDir('mwl_xlsx/templates');
}

