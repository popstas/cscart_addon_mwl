<?php
use Tygh\Addons\MwlXlsx\Planfix\EventRepository;
use Tygh\Addons\MwlXlsx\Planfix\IntegrationSettings;
use Tygh\Addons\MwlXlsx\Planfix\LinkRepository;
use Tygh\Addons\MwlXlsx\Planfix\StatusMapRepository;
use Tygh\Addons\MwlXlsx\Planfix\McpClient;
use Tygh\Addons\MwlXlsx\Planfix\PlanfixService;
use Tygh\Addons\MwlXlsx\Planfix\WebhookHandler;
use Tygh\Addons\MwlXlsx\Security\AccessService;
use Tygh\Addons\MwlXlsx\Service\SettingsBackup;
use Tygh\Addons\MwlXlsx\Telegram\TelegramService;
use Tygh\Registry;
use Tygh\Storage;
use Tygh\Tygh;
use Google\Service\Sheets;
use Google\Service\Sheets\ValueRange;
use Google\Service\Sheets\BatchUpdateSpreadsheetRequest;

if (!defined('BOOTSTRAP')) { die('Access denied'); }

/**
 * Ленивая загрузка Composer vendor autoloader
 * Загружается только когда действительно нужны vendor-библиотеки
 */
function fn_mwl_xlsx_load_vendor_autoloader()
{
    static $loaded = false;
    
    if (!$loaded && file_exists(__DIR__ . '/vendor/autoload.php')) {
        // Временно подавляем warnings от HTMLPurifier
        $old_error_reporting = error_reporting();
        error_reporting($old_error_reporting & ~E_USER_WARNING);
        
        require_once __DIR__ . '/vendor/autoload.php';
        
        // Восстанавливаем уровень error_reporting
        error_reporting($old_error_reporting);
        
        $loaded = true;
    }
}

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
        fn_mwl_planfix_ensure_planfix_links_schema();
        $repository = new LinkRepository(Tygh::$app['db']);
    }

    return $repository;
}

function fn_mwl_planfix_ensure_planfix_links_schema(): void
{
    static $ensured = false;

    if ($ensured) {
        return;
    }

    $ensured = true;

    $columns = db_get_hash_array('SHOW COLUMNS FROM ?:mwl_planfix_links', 'Field');

    if (!isset($columns['last_push_at'])) {
        db_query('ALTER TABLE ?:mwl_planfix_links ADD COLUMN `last_push_at` INT UNSIGNED NULL DEFAULT NULL AFTER `updated_at`');
    }

    if (!isset($columns['last_payload_out'])) {
        db_query('ALTER TABLE ?:mwl_planfix_links ADD COLUMN `last_payload_out` MEDIUMTEXT NULL AFTER `last_push_at`');
    }
}

function fn_mwl_planfix_status_map_repository(): StatusMapRepository
{
    static $repository;

    if ($repository === null) {
        $repository = new StatusMapRepository(Tygh::$app['db']);
    }

    return $repository;
}

function fn_mwl_planfix_build_object_url(array $link, ?string $origin = null): string
{
    return fn_mwl_planfix_service()->buildObjectUrl($link, $origin);
}

function fn_mwl_planfix_integration_settings(bool $force_reload = false): IntegrationSettings
{
    static $settings;

    if ($force_reload || $settings === null) {
        $settings = IntegrationSettings::fromRegistry();
    }

    return $settings;
}

function fn_mwl_planfix_service(bool $force_reload = false): PlanfixService
{
    static $service;
    static $signature;

    $settings = fn_mwl_planfix_integration_settings($force_reload);
    $settings_signature = md5(json_encode($settings->toArray()));

    if ($force_reload || $service === null || $signature !== $settings_signature) {
        $link_repository = fn_mwl_planfix_link_repository();
        $client = new McpClient($settings->getMcpEndpoint(), $settings->getMcpAuthToken());
        $service = new PlanfixService(
            $link_repository,
            $client,
            $settings,
            fn_mwl_xlsx_get_telegram_service()
        );

        $signature = $settings_signature;
    }

    return $service;
}

function fn_mwl_planfix_webhook_handler(bool $force_reload = false): WebhookHandler
{
    static $handler;
    static $signature;

    $service = fn_mwl_planfix_service($force_reload);
    $settings = $service->getSettings();
    $settings_signature = md5(json_encode($settings->toArray()));

    if ($force_reload || $handler === null || $signature !== $settings_signature) {
        $handler = new WebhookHandler(
            $settings,
            fn_mwl_planfix_link_repository(),
            fn_mwl_planfix_status_map_repository(),
            $service
        );

        $signature = $settings_signature;
    }

    return $handler;
}

function fn_mwl_planfix_format_order_id($order_id): string
{
    if (function_exists('fn_format_order_id')) {
        return (string) fn_format_order_id($order_id);
    }

    return (string) $order_id;
}

function fn_mwl_xlsx_get_telegram_service(): TelegramService
{
    static $service;

    if ($service !== null) {
        return $service;
    }

    $container = Tygh::$app ?? null;

    if ($container instanceof \ArrayAccess && $container->offsetExists('addons.mwl_xlsx.telegram_service')) {
        $service = $container['addons.mwl_xlsx.telegram_service'];
    } else {
        $service = new TelegramService();
    }

    return $service;
}

function fn_mwl_xlsx_access_service(): AccessService
{
    static $service;

    if ($service instanceof AccessService) {
        return $service;
    }

    $container = Tygh::$app ?? null;

    if ($container instanceof \ArrayAccess && $container->offsetExists('addons.mwl_xlsx.access_service')) {
        $service = $container['addons.mwl_xlsx.access_service'];
    } else {
        $service = new AccessService();
    }

    return $service;
}

function fn_mwl_xlsx_order_details_has_column(string $column): bool
{
    static $columns;

    if ($columns === null) {
        $fields = db_get_fields('SHOW COLUMNS FROM ?:order_details');

        if (!is_array($fields)) {
            $fields = [];
        }

        $columns = array_fill_keys($fields, true);
    }

    return isset($columns[$column]);
}

function fn_mwl_xlsx_resolve_lang_code(?string $lang_code): string
{
    static $available_langs = null;

    if ($available_langs === null) {
        $available_langs = [];
        $languages = fn_get_languages(['include_hidden' => true]);

        foreach ($languages as $code => $language) {
            $available_langs[strtolower((string) $code)] = (string) $code;
        }
    }

    $lang_code_normalized = strtolower((string) $lang_code);

    if ($lang_code_normalized === '' || !isset($available_langs[$lang_code_normalized])) {
        $lang_code_normalized = strtolower((string) CART_LANGUAGE);
    }

    return $available_langs[$lang_code_normalized] ?? (string) CART_LANGUAGE;
}

function fn_mwl_xlsx_get_order_lang_code(?int $order_id): string
{
    if ($order_id === null) {
        return fn_mwl_xlsx_resolve_lang_code(null);
    }

    static $lang_cache = [];

    if (!isset($lang_cache[$order_id])) {
        $order_lang_code = db_get_field('SELECT lang_code FROM ?:orders WHERE order_id = ?i', $order_id);
        $lang_cache[$order_id] = fn_mwl_xlsx_resolve_lang_code($order_lang_code);
    }

    return $lang_cache[$order_id];
}

function fn_mwl_planfix_mcp_client(bool $force_reload = false): McpClient
{
    if ($force_reload) {
        return fn_mwl_planfix_service(true)->getMcpClient();
    }

    return fn_mwl_planfix_service()->getMcpClient();
}

function fn_mwl_planfix_decode_link_extra($extra): array
{
    return fn_mwl_planfix_service()->decodeLinkExtra($extra);
}

function fn_mwl_planfix_update_link_extra(array $link, array $extra_updates, array $additional_fields = []): void
{
    fn_mwl_planfix_service()->updateLinkExtra($link, $extra_updates, $additional_fields);
}

function fn_mwl_planfix_output_json(int $status_code, array $data): void
{
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8', true, $status_code);
    }

    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function fn_mwl_xlsx_change_order_status_post(
    $order_id,
    $status_to,
    $status_from,
    $force_notification,
    $order_info,
    $skip_query_processing,
    $notify_user,
    $send_order_notification = false
) {
    $order_id = (int) $order_id;

    if (!$order_id) {
        return;
    }

    $service = fn_mwl_planfix_service();

    if ($service->consumeStatusSkip($order_id)) {
        return;
    }

    $service->syncOrderStatus(
        $order_id,
        (string) $status_to,
        (string) $status_from,
        is_array($order_info) ? $order_info : []
    );
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
    return fn_mwl_xlsx_access_service()->checkUsergroupAccess($auth, (string) $setting_key);
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
    return fn_mwl_xlsx_access_service()->canAccessLists($auth);
}

/**
 * {mwl_user_can_access_lists auth=$auth assign="can"}
 * или просто {mwl_user_can_access_lists assign="can"} — auth возьмём из сессии.
 */
function smarty_function_mwl_user_can_access_lists(array $params, \Smarty_Internal_Template $template)
{
    $auth = $params['auth'] ?? (Tygh::$app['session']['auth'] ?? []);
    $result = fn_mwl_xlsx_access_service()->canAccessLists($auth);

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
    return fn_mwl_xlsx_access_service()->canViewPrice($auth);
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

function fn_mwl_xlsx_install()
{
    SettingsBackup::restore();
}

function fn_mwl_xlsx_uninstall()
{
    SettingsBackup::backup();
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
    $telegram_service = fn_mwl_xlsx_get_telegram_service();
    $message_author_text = htmlspecialchars((string) $message_author, ENT_QUOTES, 'UTF-8');
    $last_message_html = nl2br(htmlspecialchars((string) $last_message, ENT_QUOTES, 'UTF-8'), false);
    $message_author_plain = htmlspecialchars_decode($message_author_text, ENT_QUOTES);
    $message_body_plain = str_replace(["\r\n", "\r"], "\n", (string) $last_message);
    $message_body_plain = str_replace(["\r\n", "\r"], "\n", $message_body_plain);

    $order_lang_code = fn_mwl_xlsx_get_order_lang_code($order_id !== null ? (int) $order_id : null);

    $http_host_raw = (string) Registry::get('config.http_host');
    $http_host = htmlspecialchars($http_host_raw, ENT_QUOTES, 'UTF-8');
    $admin_url = fn_url($action_url, 'A', 'current', $order_lang_code, true);
    $admin_url_html = htmlspecialchars($admin_url, ENT_QUOTES, 'UTF-8');

    $order_id_display = $order_id !== null ? (string) $order_id : '';
    $order_line_plain = __('mwl_xlsx_vc_new_message_order', ['[order_id]' => $order_id_display], $order_lang_code);
    $order_line_html = htmlspecialchars($order_line_plain, ENT_QUOTES, 'UTF-8');
    $order_context_text = '';
    $order_url = '';
    $order_url_html = '';

    if ($order_id !== null) {
        $order_id_int = (int) $order_id;
        $first_product_name = '';

        $select_fields = ['od.product_id'];

        if (fn_mwl_xlsx_order_details_has_column('product')) {
            $select_fields[] = 'od.product';
        }

        $first_product = db_get_row(
            'SELECT ' . implode(', ', $select_fields) . ' FROM ?:order_details AS od WHERE od.order_id = ?i ORDER BY od.item_id ASC LIMIT 1',
            $order_id_int
        );

        if ($first_product) {
            if (!empty($first_product['product'])) {
                $first_product_name = (string) $first_product['product'];
            }

            if ($first_product_name === '' && !empty($first_product['product_id'])) {
                $first_product_name = (string) db_get_field(
                    'SELECT product FROM ?:product_descriptions WHERE product_id = ?i AND lang_code = ?s',
                    (int) $first_product['product_id'],
                    $order_lang_code
                );
            }
        }

        $company_name = '';

        if (is_array($company)) {
            $company_name = trim((string) ($company['company'] ?? ''));
        } elseif (is_string($company)) {
            $company_name = trim($company);
        }

        if ($first_product_name !== '') {
            $order_context_text = $first_product_name;
        } elseif ($company_name !== '') {
            $order_context_text = $company_name;
        }

        if ($order_context_text !== '') {
            $order_line_plain .= ', ' . $order_context_text;
            $order_line_html .= ', ' . htmlspecialchars($order_context_text, ENT_QUOTES, 'UTF-8');
        }

        $order_url = fn_url('orders.details?order_id=' . $order_id_int, 'C', 'current', $order_lang_code, true);
        $order_url_html = htmlspecialchars($order_url, ENT_QUOTES, 'UTF-8');
    }

    $author_lang_var = $is_admin ? 'mwl_xlsx_vc_author_admin' : 'mwl_xlsx_vc_author_customer';
    $author_label = __($author_lang_var, [], $order_lang_code);
    $author_label_html = htmlspecialchars($author_label, ENT_QUOTES, 'UTF-8');

    $planfix_details = [
        $order_line_html,
        __('mwl_xlsx_vc_planfix_author_line', ['[author]' => $author_label_html], $order_lang_code),
        __('mwl_xlsx_vc_planfix_admin_link', ['[host]' => $http_host, '[url]' => $admin_url_html], $order_lang_code),
    ];

    $token = trim((string) Registry::get('addons.mwl_xlsx.telegram_bot_token'));
    $chat_id = trim((string) Registry::get('addons.mwl_xlsx.telegram_chat_id'));

    $message_author_telegram = $telegram_service->resolveUserTelegram((int) ($last_message_user_id ?? 0));
    $customer_telegram_display_html = '';

    if ($message_author_telegram !== '') {
        $handle_display = $telegram_service->formatHandle($message_author_telegram);
        $customer_telegram_display_html = $handle_display['html'];

        if ($customer_telegram_display_html !== '') {
            $planfix_details[] = __('mwl_xlsx_vc_planfix_telegram', ['[handle]' => $customer_telegram_display_html], $order_lang_code);
        }
    }

    if ($customer_email !== null && $customer_email !== '') {
        $planfix_details[] = __('mwl_xlsx_vc_planfix_email', ['[email]' => htmlspecialchars((string) $customer_email, ENT_QUOTES, 'UTF-8')], $order_lang_code);
    }

    if ($company !== null) {
        if (is_array($company)) {
            if (!empty($company['company'])) {
                $planfix_details[] = __('mwl_xlsx_vc_planfix_company', ['[company]' => htmlspecialchars((string) $company['company'], ENT_QUOTES, 'UTF-8')], $order_lang_code);
            }
            if (!empty($company['email'])) {
                $planfix_details[] = __('mwl_xlsx_vc_planfix_company_email', ['[email]' => htmlspecialchars((string) $company['email'], ENT_QUOTES, 'UTF-8')], $order_lang_code);
            }
            if (!empty($company['phone'])) {
                $planfix_details[] = __('mwl_xlsx_vc_planfix_company_phone', ['[phone]' => htmlspecialchars((string) $company['phone'], ENT_QUOTES, 'UTF-8')], $order_lang_code);
            }
        } elseif (is_string($company) && $company !== '') {
            $planfix_details[] = __('mwl_xlsx_vc_planfix_company', ['[company]' => htmlspecialchars($company, ENT_QUOTES, 'UTF-8')], $order_lang_code);
        }
    }

    $planfix_details = array_values(array_filter($planfix_details, static function ($line) {
        return $line !== '';
    }));

    $telegram_messages = $telegram_service->buildVendorCommunicationMessages([
        'message_author_text'          => $message_author_text,
        'last_message_html'            => $last_message_html,
        'message_author_plain'         => $message_author_plain,
        'message_body_plain'           => $message_body_plain,
        'order_line_html'              => $order_line_html,
        'admin_url'                    => $admin_url,
        'admin_url_html'               => $admin_url_html,
        'order_url'                    => $order_url,
        'order_url_html'               => $order_url_html,
        'http_host'                    => $http_host,
        'order_lang_code'              => $order_lang_code,
        'customer_telegram_display_html' => $customer_telegram_display_html,
    ]);

    $text_telegram = $telegram_messages['admin_text'];
    $customer_text_telegram = $telegram_messages['customer_text'];
    $admin_message_intro_html = $telegram_messages['admin_message_intro_html'];

    $planfix_parts_html = [$admin_message_intro_html];

    if ($planfix_details) {
        $planfix_parts_html[] = '<span style="color:#888888;font-size:smaller;">' . implode('<br>', $planfix_details) . '</span>';
    }

    $planfix_message = implode('<br><br>', $planfix_parts_html);

    $send_result = $telegram_service->sendVendorCommunicationNotifications([
        'is_admin'                => $is_admin,
        'token'                   => $token,
        'chat_id'                 => $chat_id,
        'admin_text'              => $text_telegram,
        'customer_text'           => $customer_text_telegram,
        'admin_url'               => $admin_url,
        'order_url'               => $order_url,
        'order_lang_code'         => $order_lang_code,
        'message_author_telegram' => $message_author_telegram,
    ]);

    $error_message = $send_result['error_message'];

    if ($error_message !== null) {
        $event_repository->markProcessed($event_id, EventRepository::STATUS_FAILED, $error_message);
    } else {
        $event_repository->markProcessed($event_id, EventRepository::STATUS_PROCESSED);
    }

    // Отправляем в Планфикс
    $link_repository = fn_mwl_planfix_link_repository();
    $link = $link_repository->findByEntity($data['company_id'], 'order', $order_id);
    $planfix_task_id = $link['planfix_object_id'] ?? '';
    $recipients = $is_admin ? ['roles' => []] : ['roles' => ['assignee']]; // notify assignee only about customer messages
    if ($planfix_task_id !== '') {
        $planfix_client = fn_mwl_planfix_mcp_client();
        $planfix_client->createComment(['taskId' => (int) $planfix_task_id, 'description' => $planfix_message, 'recipients' => $recipients]);
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