<?php
use Tygh\Registry;
use Tygh\Storage;

if (!defined('BOOTSTRAP')) { die('Access denied'); }

/**
 * Check whether the current customer may work with media lists.
 *
 * @param array $auth Current authentication data
 *
 * @return bool
 */
function fn_mwl_xlsx_user_can_access_lists(array $auth)
{
    $allowed = Registry::get('addons.mwl_xlsx.allowed_usergroups');
    $allowed = $allowed !== '' ? array_map('intval', explode(',', $allowed)) : [];
    if (!$allowed) {
        return true;
    }

    $usergroups = array_map('intval', array_keys($auth['usergroup_ids'] ?? []));
    return (bool) array_intersect($allowed, $usergroups);
}

/**
 * Determine if price should be shown to the current customer.
 *
 * @param array $auth Current authentication data
 *
 * @return bool
 */
function fn_mwl_xlsx_can_view_price(array $auth)
{
    if (Registry::get('addons.mwl_xlsx.hide_price_for_guests') === 'Y' && empty($auth['user_id'])) {
        return false;
    }

    $allowed = Registry::get('addons.mwl_xlsx.authorized_usergroups');
    $allowed = $allowed !== '' ? array_map('intval', explode(',', $allowed)) : [];
    if (!$allowed) {
        return true;
    }

    $usergroups = array_map('intval', array_keys($auth['usergroup_ids'] ?? []));
    return (bool) array_intersect($allowed, $usergroups);
}

/**
 * Ensures settings table exists.
 */
function fn_mwl_xlsx_ensure_settings_table()
{
    db_query("CREATE TABLE IF NOT EXISTS `?:mwl_xlsx_user_settings` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `user_id` INT UNSIGNED NOT NULL DEFAULT 0,
        `session_id` VARCHAR(64) NOT NULL DEFAULT '',
        `price_multiplier` DECIMAL(12,4) NOT NULL DEFAULT '1.0000',
        `price_append` INT NOT NULL DEFAULT '0',
        `round_to` INT NOT NULL DEFAULT '10',
        `updated_at` DATETIME NOT NULL,
        PRIMARY KEY (`id`),
        KEY `user_id` (`user_id`),
        KEY `session_id` (`session_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8");

    // Ensure new columns exist for backward compatibility
    $prefix = Registry::get('config.table_prefix');
    $table = $prefix . 'mwl_xlsx_user_settings';
    $has_round_to = (int) db_get_field(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?s AND COLUMN_NAME = 'round_to'",
        $table
    );
    if (!$has_round_to) {
        db_query("ALTER TABLE ?:mwl_xlsx_user_settings ADD COLUMN `round_to` DECIMAL(12,4) NOT NULL DEFAULT 10");
    }
}

/**
 * Get user (or session) settings for Media Lists.
 *
 * @param array $auth
 * @return array{price_multiplier:float, price_append:string, round_to:float}
 */
function fn_mwl_xlsx_get_user_settings(array $auth)
{
    fn_mwl_xlsx_ensure_settings_table();

    if (!empty($auth['user_id'])) {
        $row = db_get_row('SELECT price_multiplier, price_append, round_to FROM ?:mwl_xlsx_user_settings WHERE user_id = ?i ORDER BY id DESC LIMIT 1', (int) $auth['user_id']);
    } else {
        $session_id = Tygh::$app['session']->getID();
        $row = db_get_row('SELECT price_multiplier, price_append, round_to FROM ?:mwl_xlsx_user_settings WHERE session_id = ?s ORDER BY id DESC LIMIT 1', $session_id);
    }

    return [
        'price_multiplier' => isset($row['price_multiplier']) ? (float) $row['price_multiplier'] : 1,
        'price_append'     => isset($row['price_append']) ? (int) $row['price_append'] : 0,
        'round_to'         => isset($row['round_to']) ? (int) $row['round_to'] : 10,
    ];
}

/**
 * Save user (or session) settings for Media Lists.
 *
 * @param array $auth
 * @param array $data ['price_multiplier','price_append','round_to']
 * @return void
 */
function fn_mwl_xlsx_save_user_settings(array $auth, array $data)
{
    fn_mwl_xlsx_ensure_settings_table();

    $price_multiplier = isset($data['price_multiplier']) ? (float) $data['price_multiplier'] : 1;
    $price_append = isset($data['price_append']) ? (int) $data['price_append'] : 0;
    $round_to = isset($data['round_to']) ? (int) $data['round_to'] : 10;

    $row = [
        'user_id'         => !empty($auth['user_id']) ? (int) $auth['user_id'] : 0,
        'session_id'      => !empty($auth['user_id']) ? '' : Tygh::$app['session']->getID(),
        'price_multiplier'=> $price_multiplier,
        'price_append'    => $price_append,
        'round_to'        => $round_to,
        'updated_at'      => date('Y-m-d H:i:s'),
    ];

    // Upsert by user or session
    if (!empty($auth['user_id'])) {
        $exists = db_get_field('SELECT id FROM ?:mwl_xlsx_user_settings WHERE user_id = ?i ORDER BY id DESC LIMIT 1', (int) $auth['user_id']);
        if ($exists) {
            db_query('UPDATE ?:mwl_xlsx_user_settings SET ?u WHERE id = ?i', $row, (int) $exists);
        } else {
            db_query('INSERT INTO ?:mwl_xlsx_user_settings ?e', $row);
        }
    } else {
        $sid = $row['session_id'];
        $exists = db_get_field('SELECT id FROM ?:mwl_xlsx_user_settings WHERE session_id = ?s ORDER BY id DESC LIMIT 1', $sid);
        if ($exists) {
            db_query('UPDATE ?:mwl_xlsx_user_settings SET ?u WHERE id = ?i', $row, (int) $exists);
        } else {
            db_query('INSERT INTO ?:mwl_xlsx_user_settings ?e', $row);
        }
    }
}

/**
 * Apply price transformation according to settings.
 *
 * @param float $price
 * @param array $settings ['price_multiplier'=>float,'price_append'=>int,'round_to'=>float]
 * @return string Price prepared for export (multiplied, integer appended to value, then rounded)
 */
function fn_mwl_xlsx_transform_price_for_export($price, array $settings)
{
    $price = (float) $price;
    $mult = isset($settings['price_multiplier']) ? (float) $settings['price_multiplier'] : 1;
    $append = isset($settings['price_append']) ? (int) $settings['price_append'] : 0;
    $round_to = isset($settings['round_to']) ? (int) $settings['round_to'] : 10;

    if ($mult > 0) {
        $price = $price * $mult;
    }

    // Add integer append to price before rounding
    if ($append !== 0) {
        $price += $append;
    }

    if ($round_to > 0) {
        // Round up to the next multiple of $round_to (ceiling)
        $price = ceil($price / $round_to) * $round_to;
    }

    // Normalize to string with up to 2 decimals, trimming trailing zeros
    $str = number_format($price, 2, '.', '');
    $str = rtrim(rtrim($str, '0'), '.');

    return $str;
}

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

/**
 * Returns the number of media lists of the current user or session.
 *
 * @param array $auth Authentication data
 *
 * @return int
 */
function fn_mwl_xlsx_get_media_lists_count(array $auth)
{
    if (!empty($auth['user_id'])) {
        $condition = db_quote('l.user_id = ?i', $auth['user_id']);
    } else {
        $session_id = Tygh::$app['session']->getID();
        $condition = db_quote('l.session_id = ?s', $session_id);
    }

    $count = (int) db_get_field(
        'SELECT COUNT(*) FROM ?:mwl_xlsx_lists AS l WHERE ?p',
        $condition
    );

    return $count;
}

/** Смarty-плагин: {mwl_media_lists_count assign=\"count\"} */
function fn_mwl_xlsx_smarty_media_lists_count($params, \Smarty_Internal_Template $tpl)
{
    $auth = Tygh::$app['session']['auth'] ?? [];
    $count = fn_mwl_xlsx_get_media_lists_count($auth);

    if (!empty($params['assign'])) {
        $tpl->assign($params['assign'], $count);
        return '';
    }

    return $count;
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

    db_query('UPDATE ?:mwl_xlsx_lists SET updated_at = ?s WHERE list_id = ?i', date('Y-m-d H:i:s'), $list_id);

    return true;
}

function fn_mwl_xlsx_remove($list_id, $product_id)
{
    $deleted = db_query(
        "DELETE FROM ?:mwl_xlsx_list_products WHERE list_id = ?i AND product_id = ?i",
        $list_id,
        $product_id
    );

    if ($deleted) {
        db_query('UPDATE ?:mwl_xlsx_lists SET updated_at = ?s WHERE list_id = ?i', date('Y-m-d H:i:s'), $list_id);
    }

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
        db_query('UPDATE ?:mwl_xlsx_lists SET name = ?s, updated_at = ?s WHERE list_id = ?i', $name, date('Y-m-d H:i:s'), $list_id);
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

