<?php
use Tygh\Addons\MwlXlsx\Planfix\EventRepository;
use Tygh\Addons\MwlXlsx\Planfix\LinkRepository;
use Tygh\Addons\MwlXlsx\Planfix\StatusMapRepository;
use Tygh\Http;
use Tygh\Registry;
use Tygh\Storage;
use Tygh\Tygh;
use Google\Service\Sheets;
use Google\Service\Sheets\ValueRange;
use Google\Service\Sheets\BatchUpdateSpreadsheetRequest;

if (!defined('BOOTSTRAP')) { die('Access denied'); }

function fn_mwl_planfix_event_repository(): EventRepository
{
    static $repository;

    if ($repository === null) {
        $repository = new EventRepository(Tygh::$app['db']);
    }

    return $repository;
}

function fn_mwl_planfix_link_repository(): LinkRepository
{
    static $repository;

    if ($repository === null) {
        $repository = new LinkRepository(Tygh::$app['db']);
    }

    return $repository;
}

function fn_mwl_planfix_status_map_repository(): StatusMapRepository
{
    static $repository;

    if ($repository === null) {
        $repository = new StatusMapRepository(Tygh::$app['db']);
    }

    return $repository;
}

/**
 * Check if user belongs to user groups defined in add-on setting.
 *
 * Administrators always pass this check.
 *
 * @param array  $auth        Current authentication data
 * @param string $setting_key Add-on setting storing comma separated usergroup IDs
 *
 * @return bool
 */
function fn_mwl_xlsx_check_usergroup_access(array $auth, $setting_key)
{
    if (($auth['user_type'] ?? '') === 'A') {
        return true;
    }

    $allowed = Registry::get("addons.mwl_xlsx.$setting_key");
    // allow all if setting is empty
    if ($allowed === '') {
        return true;
    }

    $allowed = array_map('intval', explode(',', $allowed));
    if (!$allowed) {
        return true;
    }

    $usergroups = array_map('intval', $auth['usergroup_ids'] ?? []);
    return (bool) array_intersect($allowed, $usergroups);
}

/**
 * Check whether the current customer may work with media lists.
 *
 * @param array $auth Current authentication data
 *
 * @return bool
 */
function fn_mwl_xlsx_user_can_access_lists(array $auth)
{
    return fn_mwl_xlsx_check_usergroup_access($auth, 'allowed_usergroups');
}

/**
 * {mwl_user_can_access_lists auth=$auth assign="can"}
 * или просто {mwl_user_can_access_lists assign="can"} — auth возьмём из сессии.
 */
function smarty_function_mwl_user_can_access_lists(array $params, \Smarty_Internal_Template $template)
{
    $auth = $params['auth'] ?? (Tygh::$app['session']['auth'] ?? []);
    $result = fn_mwl_xlsx_user_can_access_lists($auth);

    if (!empty($params['assign'])) {
        $template->assign($params['assign'], (bool) $result);
        return '';
    }

    return $result ? '1' : '';
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

    return fn_mwl_xlsx_check_usergroup_access($auth, 'authorized_usergroups');
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
    // $str = number_format($price, 2, '.', '');
    // $str = rtrim(rtrim($str, '0'), '.');

    // return $str;
    return $price;
}

function fn_mwl_xlsx_url($list_id)
{
    $list_id = (int) $list_id;
    return "media-lists/{$list_id}";
}

/**
 * Get a media list record by ID for the current user or session.
 *
 * @param int   $list_id
 * @param array $auth
 *
 * @return array|null
 */
function fn_mwl_xlsx_get_list($list_id, array $auth)
{
    $list_id = (int) $list_id;
    if (!empty($auth['user_id'])) {
        return db_get_row("SELECT * FROM ?:mwl_xlsx_lists WHERE list_id = ?i AND user_id = ?i", $list_id, (int) $auth['user_id']);
    }

    $session_id = Tygh::$app['session']->getID();
    return db_get_row("SELECT * FROM ?:mwl_xlsx_lists WHERE list_id = ?i AND session_id = ?s", $list_id, $session_id);
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

function fn_mwl_xlsx_get_customer_status()
{
    $allowed_usergroups = ['Global', 'Continental', 'National', 'Local'];

    $status = '';
    $auth = Tygh::$app['session']['auth'] ?? [];
    $user_id = $auth['user_id'] ?? 0;

    $user_data = fn_get_user_info($user_id);
    $user_fields = $user_data['fields'] ?? [];
    
    $user_usergroups = $user_data['usergroups'] ?? [];
    $user_usergroups = array_filter($user_usergroups, function($usergroup) {
        return isset($usergroup['status']) && $usergroup['status'] === 'A';
    });
    $user_usergroups_ids = array_column($user_usergroups, 'usergroup_id');

    // global usergroups
    $usergroups = fn_get_usergroups();
    // map usergroup name => id for quick lookup
    $usergroup_name_to_id = [];
    foreach ($usergroups as $usergroup) {
        $usergroup_name_to_id[$usergroup['usergroup']] = $usergroup['usergroup_id'];
    }

    // iterate over allowed groups in priority order
    foreach ($allowed_usergroups as $allowed_group_name) {
        $allowed_group_id = isset($usergroup_name_to_id[$allowed_group_name]) ? $usergroup_name_to_id[$allowed_group_name] : null;
        if ($allowed_group_id && in_array($allowed_group_id, $user_usergroups_ids)) {
            $status = $allowed_group_name;
            break;
        }
    }

    return $status;
}

function smarty_function_mwl_xlsx_get_customer_status(array $params, \Smarty_Internal_Template $template)
{
    $status = fn_mwl_xlsx_get_customer_status();
    if (!empty($params['assign'])) {
        $template->assign($params['assign'], $status);
        return '';
    }

    return $status;
}

function smarty_function_mwl_xlsx_get_customer_status_text(array $params, \Smarty_Internal_Template $template)
{
    $status = fn_mwl_xlsx_get_customer_status();
    $status_map = [
        'Local' => 'Local',
        'National' => 'National',
        'Continental' => 'Continental',
        'Global' => 'Global',
    ];
    $status_map_en = [
        'Local' => 'Local',
        'National' => 'National',
        'Continental' => 'Continental',
        'Global' => 'Global',
    ];
    $lang_code = Tygh::$app['session']['lang_code'] ?? CART_LANGUAGE;
    if ($lang_code == 'ru') {
        $status = $status_map[$status] ?? $status;
    } else {
        $status = $status_map_en[$status] ?? $status;
    }

    if (!empty($params['assign'])) {
        $template->assign($params['assign'], $status);
        return '';
    }

    return $status;
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

    // Enrich with prices, taxes and promotions to reflect storefront pricing
    if ($products) {
        $params = [
            'get_icon' => true,
            'get_detailed' => true,
            'get_options' => true,
            'get_features' => false,
            'get_discounts' => true,
            'get_taxed_prices' => true,
        ];
        fn_gather_additional_products_data($products, $params, $lang_code);
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

function fn_mwl_xlsx_get_feature_values(array $features, $lang_code = CART_LANGUAGE)
{
    $values = [];
    foreach ($features as $feature) {
        $feature_id = $feature['feature_id'];
        if (!empty($feature['value_int'])) {
            $values[$feature_id] = floatval($feature['value_int']);
        } elseif (!empty($feature['value'])) {
            $values[$feature_id] = $feature['value'];
        } elseif (!empty($feature['variant'])) {
            $values[$feature_id] = $feature['variant'];
        } elseif (!empty($feature['variants'])) {
            $values[$feature_id] = implode(', ', array_column($feature['variants'], 'variant'));
        } else {
            $values[$feature_id] = '';
        }
    }

    return $values;
}

/**
 * Build tabular data (header + rows) for a media list export.
 * TODO: conflict with fn_mwl_xlsx_get_list_products
 *
 * @param int   $list_id
 * @param array $auth
 * @param string $lang_code
 *
 * @return array{data: array}
 */
function fn_mwl_xlsx_get_list_data($list_id, array $auth, $lang_code = CART_LANGUAGE)
{
    $products = fn_mwl_xlsx_get_list_products((int) $list_id, $lang_code);
    $feature_names = fn_mwl_xlsx_collect_feature_names($products, $lang_code);
    $feature_ids = array_keys($feature_names);

    // Header: Name, Price, then feature names
    $header = array_merge([__('name'), __('price')], array_values($feature_names));
    $data = [$header];

    $settings = fn_mwl_xlsx_get_user_settings($auth);
    foreach ($products as $p) {
        $price_str = fn_mwl_xlsx_transform_price_for_export($p['price'], $settings);
        $row = [$p['product'], $price_str];
        $values = fn_mwl_xlsx_get_feature_values($p['product_features'] ?? [], $lang_code);
        foreach ($feature_ids as $feature_id) {
            $row[] = $values[$feature_id] ?? null;
        }
        $data[] = $row;
    }

    return ['data' => $data];
}

function fn_mwl_xlsx_add($list_id, $product_id, $options = [], $amount = 1)
{
    $limit = (int) Registry::get('addons.mwl_xlsx.max_list_items');
    if ($limit > 0) {
        $count = (int) db_get_field('SELECT COUNT(*) FROM ?:mwl_xlsx_list_products WHERE list_id = ?i', $list_id);
        if ($count >= $limit) {
            return 'limit';
        }
    }

    $serialized = serialize($options);
    $exists = db_get_field(
        "SELECT 1 FROM ?:mwl_xlsx_list_products WHERE list_id = ?i AND product_id = ?i AND product_options = ?s",
        $list_id,
        $product_id,
        $serialized
    );

    if ($exists) {
        return 'exists';
    }

    db_query("INSERT INTO ?:mwl_xlsx_list_products ?e", [
        'list_id'        => $list_id,
        'product_id'     => $product_id,
        'product_options'=> $serialized,
        'amount'         => $amount,
        'timestamp'      => TIME
    ]);

    db_query('UPDATE ?:mwl_xlsx_lists SET updated_at = ?s WHERE list_id = ?i', date('Y-m-d H:i:s'), $list_id);

    return 'added';
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


/**
 * Fill a Google Spreadsheet with values and apply basic formatting (auto-resize columns).
 *
 * @param Sheets $sheets        Authorized Google Sheets service
 * @param string $spreadsheet_id Spreadsheet ID
 * @param array  $data          2D array of values (first row is header)
 * @param bool   $debug         When true, prints debug info and non-fatally handles formatting errors
 *
 * @return bool True on successful values write, false if write failed in debug mode
 * @throws \Google\Service\Exception When values write fails and debug is false
 */
function fn_mwl_xlsx_fill_google_sheet(Sheets $sheets, $spreadsheet_id, array $data, $debug = false)
{
    // Limit to 50 rows total (including headers)
    $data = array_slice($data, 0, 51);

    // Normalize rows to indexed arrays; coerce nulls to empty strings
    // Google Sheets API expects arrays-of-arrays, not associative arrays
    $normalized = [];
    foreach ($data as $row) {
        if ($row instanceof \Traversable) {
            $row = iterator_to_array($row);
        } elseif (is_object($row)) {
            $row = (array) $row;
        }

        if (is_array($row)) {
            $row = array_values($row);
        } else {
            // Coerce scalars to single-cell rows
            $row = [$row];
        }

        foreach ($row as $i => $cell) {
            if ($cell === null) {
                $row[$i] = '';
            }
        }

        $normalized[] = $row;
    }
    $data = $normalized;

    // var_dump($data); exit;
    // 1) Write all values starting from A1
    try {
        $body = new ValueRange([
            'majorDimension' => 'ROWS',
            'values' => $data,
        ]);
        $sheets->spreadsheets_values->update($spreadsheet_id, 'A1', $body, ['valueInputOption' => 'RAW']);
    } catch (\Google\Service\Exception $e) {
        header('Content-Type: text/plain; charset=UTF-8');
        echo "Sheets API values.update error:\n";
        echo $e->getCode() . " " . $e->getMessage() . "\n";
        if (method_exists($e, 'getErrors')) {
            echo json_encode($e->getErrors(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
        }
        echo "Created Spreadsheet ID: $spreadsheet_id\n";
        echo "URL: https://docs.google.com/spreadsheets/d/$spreadsheet_id\n";
        return false;
    }

    // 2) Auto-resize columns (best effort)
    try {
        $ss = $sheets->spreadsheets->get($spreadsheet_id, ['fields' => 'sheets(properties(sheetId,title))']);
        $sheets_list = $ss->getSheets();
        $sheet_id = $sheets_list && isset($sheets_list[0]) ? $sheets_list[0]->getProperties()->sheetId : null;

        if ($sheet_id !== null) {
            // Determine maximum number of columns used
            $column_count = 0;
            foreach ($data as $r) {
                $column_count = max($column_count, is_array($r) ? count($r) : 0);
            }

            $requests = [
                [
                    'autoResizeDimensions' => [
                        'dimensions' => [
                            'sheetId'   => $sheet_id,
                            'dimension' => 'COLUMNS',
                            'startIndex'=> 0,
                            'endIndex'  => $column_count,
                        ],
                    ],
                ],
            ];
            $batch_req = new BatchUpdateSpreadsheetRequest(['requests' => $requests]);
            $sheets->spreadsheets->batchUpdate($spreadsheet_id, $batch_req);

            if ($debug) {
                echo "Auto-resize columns applied on sheetId=$sheet_id for $column_count columns\n\n";
            }
        } elseif ($debug) {
            echo "Warning: Could not determine sheetId for auto-resize\n\n";
        }
    } catch (\Google\Service\Exception $e) {
        if ($debug) {
            echo "Sheets API batchUpdate (auto-resize) error:\n";
            echo $e->getCode() . " " . $e->getMessage() . "\n";
            if (method_exists($e, 'getErrors')) {
                echo json_encode($e->getErrors(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
            }
            echo "\n";
        }
        // Non-fatal
    }

    return true;
}

/**
 * Обработка события из Vendor Communication.
 *
 * @param BaseMessageSchema $schema
 * @param array $receiver_search_conditions
 */
function fn_mwl_xlsx_handle_vc_event($schema, $receiver_search_conditions, ?int $event_id = null)
{
    $event_repository = fn_mwl_planfix_event_repository();

    if ($event_id === null) {
        $event_id = $event_repository->logVendorCommunicationEvent($schema, $receiver_search_conditions);
    }

    // Получаем данные из схемы
    $data = $schema->data ?? [];
    $thread_id = $data['thread_id'] ?? null;
    // $user_id = $data['user_id'] ?? null;
    $order_id = $data['object_type'] === 'O' ? $data['object_id'] : null;
    $last_message = $data['last_message'] ?? null;
    $last_message_user_type = $data['last_message_user_type'] ?? null;
    $last_message_user_id = $data['last_message_user_id'] ?? null;
    // $communication_type = $data['communication_type'] ?? null;
    $message_author = $data['message_author'] ?? null;
    $action_url = $data['action_url'] ?? null;
    $customer_email = $data['customer_email'] ?? null;
    $company = $data['company'] ?? null;
    $is_admin = $last_message_user_type === 'A';

    // error_log(print_r($data, true));
    // Формируем текст сообщения для Telegram
    $text = "Новое сообщение по заказу {$order_id}\n"
          //   . "— Thread ID: {$thread_id}\n"
          . "- Компания: {$company}\n"
          . "- Заказ: {$order_id}\n"
          . "- Пользователь: {$message_author}\n"
          //   . "— Email: {$customer_email}\n"
          . "- Кто написал: " . ($is_admin ? 'Администратор' : 'Клиент') . "\n"
          . "- Время: " . date('Y-m-d H:i:s')
          . "\n"
          . "<a href=\"" . fn_url($action_url, 'A') . "\">URL</a>"
          . "\n\n"
          . "Сообщение: \n"
          . $last_message;

    // Отправляем в Telegram
    $token = trim((string) Registry::get('addons.mwl_xlsx.telegram_bot_token'));
    $chat_id = trim((string) Registry::get('addons.mwl_xlsx.telegram_chat_id'));
    
    if ($token && $chat_id) {
        // Telegram Bot API
        $url = "https://api.telegram.org/bot{$token}/sendMessage";
        $resp = Http::post($url, [
            'chat_id'    => $chat_id,
            'text'       => $text,
            'parse_mode' => 'HTML',
        ], [
            'timeout'    => 10,
            'headers'    => [
                'Content-Type' => 'application/x-www-form-urlencoded; charset=UTF-8',
            ],
            'log_pre'    => 'mwl_xlsx.telegram_vc_request',
            'log_result' => true,
        ]);
        $ok = $resp && ($resp = json_decode($resp, true)) && !empty($resp['ok']);
        
        $error_message = null;
        if (!$ok) {
            $error_message = 'Failed to send Telegram notification for VC event: ' . print_r($resp, true);
            error_log($error_message);
            $event_repository->markProcessed($event_id, EventRepository::STATUS_FAILED, $error_message);
        } else {
            $event_repository->markProcessed($event_id, EventRepository::STATUS_PROCESSED);
        }
    } else {
        $error_message = 'Telegram bot token or chat_id not configured for VC notifications';
        error_log($error_message);
        $event_repository->markProcessed($event_id, EventRepository::STATUS_FAILED, $error_message);
    }

    return $event_id;
}

/**
 * Переключает выбранную валюту пользователя и (по желанию) пересчитывает корзину.
 */
function fn_mwl_xlsx_switch_currency(string $target_currency, bool $recalc_cart = true): void
{
    if (AREA !== 'C') {
        return;
    }

    // Есть ли такая валюта и активна ли она
    $currencies = Registry::get('currencies') ?: [];
    if (empty($currencies[$target_currency]) || $currencies[$target_currency]['status'] !== 'A') {
        return; // не трогаем, если валюта недоступна
    }

    $current = $_SESSION['settings']['secondary_currencyC']['value'];
    if ($current === $target_currency) {
        return; // уже установлена
    }

    $_SESSION['settings']['secondary_currencyC']['value'] = $target_currency;
    // Registry::set('secondary_currency', $target_currency);
    // fn_set_cookie('currency', $target_currency, COOKIE_ALIVE_TIME);
    // var_dump($_SESSION['settings']); exit;

    if ($recalc_cart && !empty($_SESSION['cart'])) {
        $cart = &$_SESSION['cart'];
        $auth = &$_SESSION['auth'];
        fn_calculate_cart_content($cart, $auth, 'S', true, 'F', true);
    }
}