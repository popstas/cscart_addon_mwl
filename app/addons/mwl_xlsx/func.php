<?php
use Tygh\Addons\MwlXlsx\Customer\StatusResolver;
use Tygh\Addons\MwlXlsx\MediaList\ListRepository;
use Tygh\Addons\MwlXlsx\MediaList\ListService;
use Tygh\Addons\MwlXlsx\Planfix\EventRepository;
use Tygh\Addons\MwlXlsx\Planfix\IntegrationSettings;
use Tygh\Addons\MwlXlsx\Planfix\LinkRepository;
use Tygh\Addons\MwlXlsx\Planfix\StatusMapRepository;
use Tygh\Addons\MwlXlsx\Planfix\McpClient;
use Tygh\Addons\MwlXlsx\Planfix\PlanfixService;
use Tygh\Addons\MwlXlsx\Planfix\WebhookHandler;
use Tygh\Addons\MwlXlsx\Security\AccessService;
use Tygh\Addons\MwlXlsx\Repository\FilterRepository;
use Tygh\Addons\MwlXlsx\Service\FilterSyncService;
use Tygh\Addons\MwlXlsx\Service\ProductPublishDownService;
use Tygh\Addons\MwlXlsx\Service\SettingsBackup;
use Tygh\Addons\MwlXlsx\Telegram\TelegramService;
use Tygh\Registry;
use Tygh\Storage;
use Tygh\Tygh;
use Tygh\Enum\YesNo;
use Google\Service\Sheets;
use Google\Service\Sheets\ValueRange;
use Google\Service\Sheets\BatchUpdateSpreadsheetRequest;

if (!defined('BOOTSTRAP')) { die('Access denied'); }

/**
 * Log info message (for changes/important events)
 * Always logs - used for important changes that should be visible
 * 
 * @param string $message Message to log
 * @return void
 */
function fn_mwl_xlsx_log_info($message)
{
    error_log('[MWL_XLSX] ' . $message);
}

/**
 * Log debug message (for detailed debugging)
 * Only logs when debug is enabled via $_REQUEST['debug']
 * 
 * @param string $message Message to log
 * @return void
 */
function fn_mwl_xlsx_log_debug($message)
{
    $debug = isset($_REQUEST['debug']) ? (bool) $_REQUEST['debug'] : false;
    if ($debug) {
        error_log('[MWL_XLSX] [DEBUG] ' . $message);
    }
}

/**
 * Статическое хранилище для передачи данных между хуками
 * Используется для передачи информации о группах variation между
 * хуками variation_group_add_products_to_group и import_post
 * 
 * @param int $group_id ID группы
 * @param array $feature_ids Массив feature_id для проверки
 * @param bool $is_update_scenario Флаг update scenario
 * @return void
 */
function fn_mwl_xlsx_set_groups_to_fix($group_id, $feature_ids, $is_update_scenario)
{
    static $groups_to_fix = [];
    
    if (!isset($groups_to_fix[$group_id])) {
        $groups_to_fix[$group_id] = [
            'feature_ids' => [],
            'is_update_scenario' => false
        ];
    }
    
    // Объединяем feature_ids (могут быть вызовы и для manually_added, и для update scenario)
    $groups_to_fix[$group_id]['feature_ids'] = array_unique(array_merge(
        $groups_to_fix[$group_id]['feature_ids'],
        $feature_ids
    ));
    
    // Устанавливаем флаг update scenario
    if ($is_update_scenario) {
        $groups_to_fix[$group_id]['is_update_scenario'] = true;
    }
    
    // Сохраняем в статическую переменную для fn_mwl_xlsx_get_groups_to_fix
    fn_mwl_xlsx_get_groups_to_fix($groups_to_fix);
}

/**
 * Получить список групп для обработки в import_post
 * 
 * @param array|null $set Если передан, устанавливает значение вместо возврата
 * @return array Массив групп для обработки
 */
function fn_mwl_xlsx_get_groups_to_fix($set = null)
{
    static $groups_to_fix = [];
    
    if ($set !== null) {
        $groups_to_fix = $set;
        return $groups_to_fix;
    }
    
    return $groups_to_fix;
}

/**
 * Общее хранилище для product_features (используется set и get функциями)
 * 
 * @param array|null $set Если передан, устанавливает значение вместо возврата
 * @return array
 */
function fn_mwl_xlsx_products_features_storage($set = null)
{
    static $storage = [];
    
    if ($set !== null) {
        $storage = $set;
    }
    
    return $storage;
}

/**
 * Сохранить product_features для группы
 * 
 * @param int $group_id ID группы
 * @param array $products_features_map Массив product_id => variation_features
 * @return void
 */
function fn_mwl_xlsx_set_products_features($group_id, $products_features_map)
{
    $storage = fn_mwl_xlsx_products_features_storage();
    $storage[$group_id] = $products_features_map;
    fn_mwl_xlsx_products_features_storage($storage);
}

/**
 * Получить product_features для группы
 * 
 * @param int|null $group_id ID группы (null = вернуть все)
 * @return array|null
 */
function fn_mwl_xlsx_get_products_features($group_id = null)
{
    $storage = fn_mwl_xlsx_products_features_storage();
    
    if ($group_id === null) {
        return $storage;
    }
    
    return isset($storage[$group_id]) ? $storage[$group_id] : null;
}

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

function fn_mwl_xlsx_filter_sync_service(): FilterSyncService
{
    static $service;

    if ($service === null) {
        $repository = new FilterRepository(Tygh::$app['db']);
        $service = new FilterSyncService($repository);
    }

    return $service;
}

function fn_mwl_xlsx_append_log(string $message): void
{
    $log_dir = Registry::get('config.dir.root') . '/var/log';
    fn_mkdir($log_dir);

    $line = sprintf('[%s] %s%s', date('c'), $message, PHP_EOL);
    file_put_contents($log_dir . '/mwl_xlsx.log', $line, FILE_APPEND);
}

/**
 * Output a metric line for cron tasks.
 *
 * @param string $mode Task name (e.g., 'filters_sync', 'delete_unused_products')
 * @param string $name Metric name (e.g., 'created', 'updated', 'deleted')
 * @param int $value Metric value
 */
function fn_mwl_xlsx_output_metric(string $mode, string $name, int $value): void
{
    echo sprintf('[%s] %s: %d', $mode, $name, $value) . PHP_EOL;
}

/**
 * Output a JSON summary line for cron tasks.
 *
 * @param string $mode Task name (e.g., 'filters_sync', 'delete_unused_products')
 * @param array<string, mixed> $metrics Associative array of metrics
 */
function fn_mwl_xlsx_output_json(string $mode, array $metrics): void
{
    echo sprintf('[%s] json: %s', $mode, json_encode($metrics, JSON_UNESCAPED_UNICODE)) . PHP_EOL;
}

/**
 * Output both individual metric lines and JSON summary for cron tasks.
 * By default, all metrics are output. Metrics in $skip_zeros_metrics are skipped if their value is 0.
 *
 * @param string $mode Task name (e.g., 'filters_sync', 'delete_unused_products')
 * @param array<string, int> $metrics Associative array of metrics (name => value)
 * @param array<int, string> $skip_zeros_metrics Metric names to skip when value is 0 (default: ['errors'])
 */
function fn_mwl_xlsx_output_metrics(string $mode, array $metrics, array $skip_zeros_metrics = ['errors']): void
{
    $skip_zeros_map = array_flip($skip_zeros_metrics);
    $json_metrics = $metrics;

    foreach ($metrics as $name => $value) {
        $value = (int) $value;
        
        // Skip if value = 0 AND it's in the skip_zeros_metrics list
        if ($value === 0 && isset($skip_zeros_map[$name])) {
            unset($json_metrics[$name]);
            continue;
        }
        
        fn_mwl_xlsx_output_metric($mode, $name, $value);
    }

    fn_mwl_xlsx_output_json($mode, $json_metrics);
}

/**
 * @return array{rows: array<int, array<string, mixed>>, errors: array<int, string>}
 */

function fn_mwl_xlsx_publish_down_service(): ProductPublishDownService
{
    static $service;

    if ($service === null) {
        $service = new ProductPublishDownService(Tygh::$app['db']);
    }

    return $service;
}



/**
 * Read products CSV file for publish_down_missing_products_csv mode.
 * Expected columns: "Variation group code", "Product code"
 *
 * @param string $path Path to CSV file
 * @return array{rows: array<array{variation_group_code: string, product_code: string}>, errors: array<string>}
 */

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

/**
 * @return int[]
 */
function fn_mwl_xlsx_get_hidden_feature_ids(): array
{
    $setting = Registry::get('addons.mwl_xlsx.hide_features');

    if (is_array($setting)) {
        $ids = $setting;
    } elseif ($setting === null || $setting === '') {
        $ids = [];
    } else {
        $ids = explode(',', (string) $setting);
    }

    $ids = array_map('intval', $ids);
    $ids = array_filter($ids, static function ($value) {
        return $value > 0;
    });

    return array_values(array_unique($ids));
}

function fn_mwl_xlsx_should_hide_features(?array $auth = null, ?array $feature_ids = null): bool
{
    if ($feature_ids === null) {
        $feature_ids = fn_mwl_xlsx_get_hidden_feature_ids();
    }

    if (!$feature_ids) {
        return false;
    }

    if ($auth === null) {
        $auth = [];
        $session = Tygh::$app['session'] ?? [];
        if ($session instanceof \ArrayAccess) {
            $auth = $session['auth'] ?? [];
        } elseif (is_array($session)) {
            $auth = $session['auth'] ?? [];
        }
    }

    $access_service = fn_mwl_xlsx_access_service();

    return !$access_service->canViewPrice($auth);
}

// TODO: remove this hook, it uses static cache, user independent
/* function fn_mwl_xlsx_get_filters_products_count_before_select_filters(&$sf_fields, &$sf_join, &$condition, &$sf_sorting, $params)
{
    if (AREA !== 'C') {
        return;
    }

    $feature_ids = fn_mwl_xlsx_get_hidden_feature_ids();
    if (!$feature_ids) {
        return;
    }

    if (!fn_mwl_xlsx_should_hide_features(null, $feature_ids)) {
        return;
    }

    $condition .= db_quote(' AND ?:product_filters.feature_id NOT IN (?n)', $feature_ids);
} */

function fn_mwl_xlsx_get_product_features(&$fields, &$join, &$condition, $params)
{
    if (AREA !== 'C') {
        return;
    }

    $feature_ids = fn_mwl_xlsx_get_hidden_feature_ids();
    if (!$feature_ids) {
        return;
    }

    if (!fn_mwl_xlsx_should_hide_features(null, $feature_ids)) {
        return;
    }

    $condition .= db_quote(' AND pf.feature_id NOT IN (?n)', $feature_ids);
}

function fn_mwl_xlsx_list_repository(): ListRepository
{
    static $repository;

    if ($repository instanceof ListRepository) {
        return $repository;
    }

    $container = Tygh::$app ?? null;

    if ($container instanceof \ArrayAccess && $container->offsetExists('addons.mwl_xlsx.list_repository')) {
        $repository = $container['addons.mwl_xlsx.list_repository'];
    } else {
        $db = null;
        if ($container instanceof \ArrayAccess && $container->offsetExists('db')) {
            $db = $container['db'];
        }

        $repository = new ListRepository($db);
    }

    return $repository;
}

function fn_mwl_xlsx_list_service(): ListService
{
    static $service;

    if ($service instanceof ListService) {
        return $service;
    }

    $container = Tygh::$app ?? null;

    if ($container instanceof \ArrayAccess && $container->offsetExists('addons.mwl_xlsx.list_service')) {
        $service = $container['addons.mwl_xlsx.list_service'];
    } else {
        $session = null;
        if ($container instanceof \ArrayAccess && $container->offsetExists('session')) {
            $session = $container['session'];
        }

        $service = new ListService(fn_mwl_xlsx_list_repository(), $session);
    }

    return $service;
}

function fn_mwl_xlsx_customer_status_resolver(): StatusResolver
{
    static $resolver;

    if ($resolver instanceof StatusResolver) {
        return $resolver;
    }

    $resolver = StatusResolver::fromContainer();

    return $resolver;
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

/** Смarty-плагин: {mwl_media_lists_count assign=\"count\"} */
function fn_mwl_xlsx_smarty_media_lists_count($params, \Smarty_Internal_Template $tpl)
{
    $auth = Tygh::$app['session']['auth'] ?? [];
    $count = fn_mwl_xlsx_list_service()->getMediaListsCount($auth);

    if (!empty($params['assign'])) {
        $tpl->assign($params['assign'], $count);
        return '';
    }

    return $count;
}

function smarty_function_mwl_xlsx_get_customer_status(array $params, \Smarty_Internal_Template $template)
{
    $resolver = fn_mwl_xlsx_customer_status_resolver();
    $status = $resolver->resolveStatus();
    if (!empty($params['assign'])) {
        $template->assign($params['assign'], $status);
        return '';
    }

    return $status;
}

function smarty_function_mwl_xlsx_get_customer_status_text(array $params, \Smarty_Internal_Template $template)
{
    $resolver = fn_mwl_xlsx_customer_status_resolver();
    $lang_code = isset($params['lang_code']) ? (string) $params['lang_code'] : null;
    $status = $resolver->resolveStatusLabel($lang_code);

    if (!empty($params['assign'])) {
        $template->assign($params['assign'], $status);
        return '';
    }

    return $status;
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

/**
 * Hook handler: skips product image import if product already has images.
 */
function fn_mwl_xlsx_exim_import_images_pre(
    $prefix,
    $image_file,
    $detailed_file,
    $position,
    $type,
    $object_id,
    $object,
    $import_options,
    &$perform_import
) {
    if ($object !== 'product' || empty($object_id)) {
        return;
    }

    $main_pair = fn_get_image_pairs($object_id, 'product', 'M', true, true);
    if (!empty($main_pair)) {
        $perform_import = false;
        echo 'image_exists' . PHP_EOL;

        return;
    }

    if (!$detailed_file) {
        $perform_import = false;
        echo 'no_image' . PHP_EOL;
        return;
    }

    echo 'image_import' . PHP_EOL;
    // $additional_pairs = fn_get_image_pairs($object_id, 'product', 'A', true, true);
    // if (!empty($additional_pairs)) {
    //     error_log(print_r($additional_pairs, true));
    //     $perform_import = false;
    // }
}

/**
 * Hook: Синхронизирует features группы вариаций с features продуктов при импорте
 * 
 * Проблемы которые решаются:
 * 1. Добавление features: При импорте продуктов с новыми features (A,B -> A,B,C),
 *    новая feature C игнорируется при импорте.
 * 2. Удаление features: Если feature удалена из продуктов но осталась в группе,
 *    возникает ошибка "exact same combination of feature values".
 * 
 * Решение: Перед добавлением продуктов в группу проверяем все доступные features
 * у базового продукта и полностью синхронизируем список features группы в БД.
 * Это гарантирует, что группа содержит только те features, которые реально есть у продуктов.
 * 
 * @param \Tygh\Addons\ProductVariations\Service             $service         Экземпляр сервиса
 * @param \Tygh\Common\OperationResult                       $result          Результат операции
 * @param array                                              $products        Список продуктов для добавления
 * @param \Tygh\Addons\ProductVariations\Product\Group\Group &$group          Группа вариаций (по ссылке!)
 * @param array                                              $products_status Статусы продуктов
 */
function fn_mwl_xlsx_variation_group_add_products_to_group($service, $result, $products, &$group, $products_status)
{
    // Счетчик вызовов для отслеживания множественных срабатываний
    static $call_counter = [];
    static $features_updated = []; // Отслеживаем для каких групп мы уже обновили features
    
    $group_id_key = $group ? $group->getId() : 'null';
    if (!isset($call_counter[$group_id_key])) {
        $call_counter[$group_id_key] = 0;
    }
    $call_counter[$group_id_key]++;
    $current_call = $call_counter[$group_id_key];
    
    // Debug: хук вызван
    fn_mwl_xlsx_log_debug('========================================');
    fn_mwl_xlsx_log_debug('Hook variation_group_add_products_to_group called (call #' . $current_call . ' for group #' . $group_id_key . ')');
    fn_mwl_xlsx_log_debug('Products count: ' . count($products));
    fn_mwl_xlsx_log_debug('Has group: ' . ($group ? 'yes' : 'no'));
    fn_mwl_xlsx_log_debug('Group ID: ' . ($group ? $group->getId() : 'null'));

    if (empty($products) || !$group || !$group->getId()) {
        fn_mwl_xlsx_log_debug('Hook skipped: empty products or invalid group');
        return;
    }

    try {
        fn_mwl_xlsx_log_debug('Starting group features update check');
        fn_mwl_xlsx_log_debug('Group ID: ' . $group->getId());
        fn_mwl_xlsx_log_debug('Group code: ' . $group->getCode());
        
        $current_feature_ids = $group->getFeatureIds();
        fn_mwl_xlsx_log_debug('Current feature IDs (from object): ' . (empty($current_feature_ids) ? 'EMPTY!' : implode(', ', $current_feature_ids)));
        
        // ЗАЩИТА: Если объект группы пустой, загружаем features из БД
        if (empty($current_feature_ids)) {
            $db_features_check = db_get_array(
                "SELECT feature_id FROM ?:product_variation_group_features WHERE group_id = ?i",
                $group->getId()
            );
            if (!empty($db_features_check)) {
                fn_mwl_xlsx_log_debug('⚠ Group object has no features, but DB has: ' . implode(', ', array_column($db_features_check, 'feature_id')));
                fn_mwl_xlsx_log_debug('→ Group object not fully loaded, reloading from DB...');
                
                $fresh_group = $group_repository->findGroupById($group->getId());
                if ($fresh_group) {
                    $current_feature_ids = $fresh_group->getFeatureIds();
                    fn_mwl_xlsx_log_debug('→ Reloaded features: ' . implode(', ', $current_feature_ids));
                }
            }
        }

        // Получаем сервисы product_variations (ВАЖНО: используем ведущий \ для глобального namespace)
        $product_repository = \Tygh\Addons\ProductVariations\ServiceProvider::getProductRepository();
        $group_repository = \Tygh\Addons\ProductVariations\ServiceProvider::getGroupRepository();
        
        // Получаем ID всех продуктов (существующие + новые)
        $new_product_ids = array_keys($products);
        $existing_product_ids = $group->getProductIds();
        $all_product_ids = array_merge($existing_product_ids, $new_product_ids);
        
        if (empty($all_product_ids)) {
            fn_mwl_xlsx_log_debug('Hook skipped: no product IDs found');
            return;
        }
        
        fn_mwl_xlsx_log_debug('Product IDs collected');
        fn_mwl_xlsx_log_debug('New product IDs: ' . implode(', ', $new_product_ids));
        fn_mwl_xlsx_log_debug('Existing product IDs: ' . implode(', ', $existing_product_ids));
        fn_mwl_xlsx_log_debug('Total products: ' . count($all_product_ids));
        
        // Debug: проверяем ВСЕ продукты в группе из БД с кодами
        $all_group_products_db = db_get_array(
            "SELECT gp.product_id, gp.parent_product_id, p.product_code " .
            "FROM ?:product_variation_group_products gp " .
            "LEFT JOIN ?:products p ON gp.product_id = p.product_id " .
            "WHERE gp.group_id = ?i",
            $group->getId()
        );
        fn_mwl_xlsx_log_debug('Products in DB for group #' . $group->getId() . ':');
        foreach ($all_group_products_db as $gp) {
            fn_mwl_xlsx_log_debug('Product #' . $gp['product_id'] . ' [' . $gp['product_code'] . '] (parent: ' . ($gp['parent_product_id'] ?: 'none') . ')');
        }
        
        // КРИТИЧЕСКИ ВАЖНО: Собираем features со ВСЕХ новых продуктов, а не только базового
        // Это гарантирует что новые features из импорта будут добавлены в группу
        fn_mwl_xlsx_log_debug('Collecting available features from ALL new products...');
        $available_features = [];
        
        foreach ($new_product_ids as $check_product_id) {
            // Сначала пытаемся получить features из БД (через findAvailableFeatures)
            $product_features = $product_repository->findAvailableFeatures($check_product_id);
            fn_mwl_xlsx_log_debug('Product #' . $check_product_id . ' has ' . count($product_features) . ' variation features from DB');
            
            // Объединяем features из БД
            foreach ($product_features as $feature_id => $feature) {
                if (!isset($available_features[$feature_id])) {
                    $available_features[$feature_id] = $feature;
                    fn_mwl_xlsx_log_debug('  * Added feature #' . $feature_id . ': ' . ($feature['description'] ?? 'unknown'));
                }
            }
            
            // ВАЖНО: Также собираем features из import_data, которые могут ещё не быть в БД
            // Это нужно потому что findAvailableFeatures вызывается ДО сохранения feature values в БД
            if (isset($products[$check_product_id]['variation_features'])) {
                foreach ($products[$check_product_id]['variation_features'] as $fid => $feature_data) {
                    if (!isset($available_features[$fid])) {
                        // Загружаем информацию о feature из БД
                        $feature_info = db_get_row(
                            "SELECT f.feature_id, fd.description, f.feature_type, f.purpose " .
                            "FROM ?:product_features f " .
                            "LEFT JOIN ?:product_features_descriptions fd ON f.feature_id = fd.feature_id AND fd.lang_code = 'en' " .
                            "WHERE f.feature_id = ?i",
                            $fid
                        );
                        
                        if ($feature_info) {
                            $available_features[$fid] = [
                                'feature_id' => $feature_info['feature_id'],
                                'description' => $feature_info['description'],
                                'internal_name' => $feature_info['description'],
                                'feature_type' => $feature_info['feature_type'],
                                'purpose' => $feature_info['purpose'] ?: 'group_variation_catalog_item'
                            ];
                            fn_mwl_xlsx_log_debug('  * Added feature #' . $fid . ' from import_data: ' . ($feature_info['description'] ?? 'unknown'));
                        }
                    }
                }
            }
        }
        
        // ВАЖНО: Также добавляем features, которые уже есть в группе, но не были найдены для новых продуктов
        // Это нужно потому что новые продукты могут не иметь всех features в variation_features,
        // если они еще не были добавлены в группу
        if (!empty($current_feature_ids)) {
            fn_mwl_xlsx_log_debug('Also checking features already in group...');
            foreach ($current_feature_ids as $fid) {
                if (!isset($available_features[$fid])) {
                    // Загружаем информацию о feature из БД
                    $feature_info = db_get_row(
                        "SELECT f.feature_id, fd.description, f.feature_type, f.purpose " .
                        "FROM ?:product_features f " .
                        "LEFT JOIN ?:product_features_descriptions fd ON f.feature_id = fd.feature_id AND fd.lang_code = 'en' " .
                        "WHERE f.feature_id = ?i AND f.purpose = 'group_variation_catalog_item'",
                        $fid
                    );
                    
                    if ($feature_info) {
                        $available_features[$fid] = [
                            'feature_id' => $feature_info['feature_id'],
                            'description' => $feature_info['description'],
                            'internal_name' => $feature_info['description'],
                            'feature_type' => $feature_info['feature_type'],
                            'purpose' => $feature_info['purpose']
                        ];
                        fn_mwl_xlsx_log_debug('  * Added feature #' . $fid . ' from group: ' . ($feature_info['description'] ?? 'unknown'));
                    }
                }
            }
        }
        
        // Если новых продуктов нет или нет новых features, проверяем существующие
        if (empty($available_features)) {
            fn_mwl_xlsx_log_debug('No features from new products, checking existing...');
            $base_product_id = reset($all_product_ids);
            $available_features = $product_repository->findAvailableFeatures($base_product_id);
            fn_mwl_xlsx_log_debug('Base product ID: ' . $base_product_id);
        }
        
        fn_mwl_xlsx_log_debug('Available features found: ' . count($available_features));
        foreach ($available_features as $feature) {
            $name = $feature['description'] ?? $feature['internal_name'] ?? 'unknown';
            $purpose = $feature['purpose'] ?? 'unknown';
            fn_mwl_xlsx_log_debug('Feature #' . $feature['feature_id'] . ': ' . $name . ' (purpose: ' . $purpose . ')');
        }
        
        // Debug: проверяем feature values ИМПОРТИРУЕМЫХ продуктов
        fn_mwl_xlsx_log_debug('Checking feature values of new products...');
        foreach ($new_product_ids as $pid) {
            if (isset($products[$pid]['variation_features'])) {
                fn_mwl_xlsx_log_debug('Product #' . $pid . ' features from import data:');
                foreach ($products[$pid]['variation_features'] as $fid => $feature) {
                    $variant = $feature['variant'] ?? 'null';
                    fn_mwl_xlsx_log_debug('  * Feature #' . $fid . ': ' . $variant);
                }
            } else {
                fn_mwl_xlsx_log_debug('Product #' . $pid . ': NO variation_features in import data');
            }
            
            // Проверяем feature values из БД для этого продукта
            $db_features = db_get_array(
                "SELECT feature_id, variant_id, value_int " .
                "FROM ?:product_features_values " .
                "WHERE product_id = ?i AND lang_code = 'en' AND feature_id IN (?a)",
                $pid, array_keys($available_features)
            );
            
            if ($db_features) {
                fn_mwl_xlsx_log_debug('Product #' . $pid . ' features from DB:');
                foreach ($db_features as $db_feat) {
                    fn_mwl_xlsx_log_debug('  * Feature #' . $db_feat['feature_id'] . ': variant_id=' . $db_feat['variant_id'] . ', value_int=' . $db_feat['value_int']);
                }
            } else {
                fn_mwl_xlsx_log_debug('Product #' . $pid . ': NO features in DB!');
            }
        }
        
        if (empty($available_features)) {
            fn_mwl_xlsx_log_debug('Hook skipped: no available features for product #' . $base_product_id);
            return;
        }
        
        // Получаем новые feature IDs
        $new_feature_ids = array_keys($available_features);
        
        // Проверяем, есть ли новые или удаленные features
        $added_feature_ids = array_diff($new_feature_ids, $current_feature_ids);
        $removed_feature_ids = array_diff($current_feature_ids, $new_feature_ids);
        
        fn_mwl_xlsx_log_debug('Features comparison:');
        fn_mwl_xlsx_log_debug('Current features: ' . implode(', ', $current_feature_ids));
        fn_mwl_xlsx_log_debug('Available features: ' . implode(', ', $new_feature_ids));
        if (!empty($added_feature_ids)) {
            fn_mwl_xlsx_log_info('Features to add: ' . implode(', ', $added_feature_ids));
        }
        if (!empty($removed_feature_ids)) {
            fn_mwl_xlsx_log_info('Features to remove: ' . implode(', ', $removed_feature_ids));
        }
        
        // КРИТИЧЕСКОЕ РЕШЕНИЕ: Обновляем features только при УДАЛЕНИИ, не при добавлении
        // Причина: CS-Cart фильтрует variation features при сохранении через ProductsHookHandler
        // Когда мы добавляем новую feature, CS-Cart не сохраняет её values → ошибка "required features"
        if (!empty($added_feature_ids)) {
            fn_mwl_xlsx_log_debug('⚠ New features detected: ' . implode(', ', $added_feature_ids));
            fn_mwl_xlsx_log_debug('→ Auto-adding features DISABLED due to CS-Cart import filtering');
            fn_mwl_xlsx_log_debug('→ Will fix feature values in import_post hook');
            fn_mwl_xlsx_log_debug('→ New features: ' . implode(', ', array_map(function($fid) use ($available_features) {
                $name = isset($available_features[$fid]['description']) ? $available_features[$fid]['description'] : 'Feature #' . $fid;
                return $name . ' (ID: ' . $fid . ')';
            }, $added_feature_ids)));
            
            // Сохраняем в Registry для обработки в import_post
            \Tygh\Registry::set('mwl_xlsx.group_' . $group_id_key . '_new_feature_ids', $added_feature_ids);
            fn_mwl_xlsx_log_debug('→ Saved to Registry for post-processing');
        }
        
        // Обновляем features только при удалении (безопасно)
        $has_feature_changes = !empty($removed_feature_ids);
        
        // Проверяем текущее состояние features в БД
        $current_db_features = db_get_array(
            "SELECT feature_id FROM ?:product_variation_group_features WHERE group_id = ?i ORDER BY feature_id",
            $group->getId()
        );
        $current_db_feature_ids = empty($current_db_features) ? [] : array_column($current_db_features, 'feature_id');
        
        fn_mwl_xlsx_log_debug('Current features in DB: ' . (empty($current_db_features) ? 'none (table is EMPTY!)' : implode(', ', $current_db_feature_ids)));
        
        // ВАЖНО: Проверяем features которые в БД но НЕ были в объекте группы
        // Это значит feature была добавлена вручную ПЕРЕД импортом
        $manually_added_features = array_diff($current_db_feature_ids, $current_feature_ids);
        if (!empty($manually_added_features)) {
            fn_mwl_xlsx_log_debug('Features added manually before import: ' . implode(', ', $manually_added_features));
            fn_mwl_xlsx_log_debug('→ Will fix their values in import_post hook');
            
            // Сохраняем в статическое хранилище для import_post
            fn_mwl_xlsx_set_groups_to_fix($group_id_key, $manually_added_features, false);
            
            // NOTE: product_features будут сохранены в update_product_features_value_pre
            fn_mwl_xlsx_log_debug('Saved to static storage (features will be saved in update_product_features_value_pre)');
        }
        
        // ДОПОЛНИТЕЛЬНО: Если это ОБНОВЛЕНИЕ существующих продуктов (а не создание новых),
        // всегда фиксим все variation features в import_post чтобы предотвратить некорректную синхронизацию
        $truly_new_ids = array_diff($new_product_ids, $existing_product_ids);
        if (empty($truly_new_ids) && !empty($current_db_feature_ids)) {
            // Все продукты обновляются (нет новых) → это update scenario
            fn_mwl_xlsx_log_debug('Update scenario detected (no new products, all are being updated)');
            fn_mwl_xlsx_log_debug('Will verify/fix ALL variation feature values in import_post');
            
            // Сохраняем ВСЕ variation features для проверки
            $all_variation_features = array_unique(array_merge(
                isset($manually_added_features) ? $manually_added_features : [],
                $current_db_feature_ids
            ));
            
            fn_mwl_xlsx_log_debug('Saving to STATIC variable for import_post:');
            fn_mwl_xlsx_log_debug('  Group ID: ' . $group_id_key);
            fn_mwl_xlsx_log_debug('Features to check: [' . implode(', ', $all_variation_features) . ']');
            
            // Используем статическую переменную вместо Registry
            // потому что Registry сбрасывается между хуками
            fn_mwl_xlsx_set_groups_to_fix($group_id_key, $all_variation_features, true);
            
            // NOTE: product_features теперь сохраняются в хуке update_product_features_value_pre
            // который вызывается РАНЬШЕ и содержит исходные значения до синхронизации CS-Cart
            fn_mwl_xlsx_log_debug('Saved to static storage (features will be saved in update_product_features_value_pre)');
        }
        
        // ЗАЩИТА: Если features в БД пусты, а в группе есть - кто-то уже удалил!
        if (empty($current_db_features) && !empty($current_feature_ids)) {
            fn_mwl_xlsx_log_debug('⚠ WARNING: Group object has features but DB is empty!');
            fn_mwl_xlsx_log_debug('Someone deleted features from DB before us');
            fn_mwl_xlsx_log_debug('Will restore features to DB');
            $has_feature_changes = true; // Принудительно обновляем
        }
        
        // ЗАЩИТА: Если мы уже обновляли features для этой группы в этом запросе - пропускаем
        if (isset($features_updated[$group_id_key])) {
            $prev_features = $features_updated[$group_id_key];
            sort($prev_features);
            $new_features_sorted = $new_feature_ids;
            sort($new_features_sorted);
            
            if ($prev_features == $new_features_sorted) {
                fn_mwl_xlsx_log_debug('Features already updated in this request (call #' . $current_call . '), skipping');
                fn_mwl_xlsx_log_debug('Previously updated to: ' . implode(', ', $prev_features));
                $has_feature_changes = false;
            } else {
                fn_mwl_xlsx_log_debug('Features changed since last update in this request');
                fn_mwl_xlsx_log_debug('Previous: ' . implode(', ', $prev_features));
                fn_mwl_xlsx_log_debug('New: ' . implode(', ', $new_features_sorted));
            }
        }
        
        if (!$has_feature_changes) {
            fn_mwl_xlsx_log_debug('No changes in features list');
        } else {
            // КРИТИЧЕСКИ ВАЖНО: Обновляем features в БД СРАЗУ, до удаления дубликатов!
            // Если обновить после detach, группа может пересохраниться и features потеряются
            fn_mwl_xlsx_log_debug('Will update group features BEFORE removing duplicates');
            fn_mwl_xlsx_log_debug('Adding features: ' . (!empty($added_feature_ids) ? implode(', ', $added_feature_ids) : 'none'));
            fn_mwl_xlsx_log_debug('Removing features: ' . (!empty($removed_feature_ids) ? implode(', ', $removed_feature_ids) : 'none'));
            
            fn_mwl_xlsx_log_debug('Updating DB: deleting old features (if any)...');
            $deleted_rows = db_query("DELETE FROM ?:product_variation_group_features WHERE group_id = ?i", $group->getId());
            fn_mwl_xlsx_log_debug('Deleted rows: ' . ($deleted_rows ? $deleted_rows : '0'));
            
            $insert_data = [];
            foreach ($available_features as $feature) {
                $insert_data[] = [
                    'group_id' => $group->getId(),
                    'feature_id' => $feature['feature_id'],
                    'purpose' => $feature['purpose']
                ];
            }
            
            fn_mwl_xlsx_log_debug('Prepared ' . count($insert_data) . ' features for insertion:');
            foreach ($insert_data as $idx => $feat_data) {
                fn_mwl_xlsx_log_debug('  ' . ($idx+1) . '. Feature #' . $feat_data['feature_id'] . ' (purpose: ' . $feat_data['purpose'] . ')');
            }
            
            if (!empty($insert_data)) {
                try {
                    // Используем прямой INSERT вместо VALUES ?e для надежности
                    foreach ($insert_data as $data) {
                        db_query(
                            "INSERT INTO ?:product_variation_group_features (group_id, feature_id, purpose) VALUES (?i, ?i, ?s)",
                            $data['group_id'], $data['feature_id'], $data['purpose']
                        );
                    }
                    fn_mwl_xlsx_log_debug('✓ Features inserted to DB (inserted ' . count($insert_data) . ' records)');
                    
                    // Проверяем что features действительно в БД
                    $check_features = db_get_array(
                        "SELECT feature_id, purpose FROM ?:product_variation_group_features WHERE group_id = ?i ORDER BY feature_id",
                        $group->getId()
                    );
                    fn_mwl_xlsx_log_debug('Verification: ' . count($check_features) . ' features in DB after insert:');
                    if (empty($check_features)) {
                        fn_mwl_xlsx_log_debug('✗ ERROR: Features not found in DB after INSERT!');
                        fn_mwl_xlsx_log_debug('→ INSERT may have failed silently or transaction rolled back');
                    } else {
                        foreach ($check_features as $cf) {
                            fn_mwl_xlsx_log_debug('Feature #' . $cf['feature_id'] . ' (purpose: ' . $cf['purpose'] . ')');
                        }
                        // Запоминаем что обновили features для этой группы
                        $features_updated[$group_id_key] = $new_feature_ids;
                        fn_mwl_xlsx_log_debug('Marked group #' . $group_id_key . ' as features-updated');
                    }
                } catch (\Exception $db_error) {
                    fn_mwl_xlsx_log_debug('✗ ERROR inserting features to DB: ' . $db_error->getMessage());
                    fn_mwl_xlsx_log_debug('Stack trace: ' . $db_error->getTraceAsString());
                }
            } else {
                fn_mwl_xlsx_log_debug('⚠ No features to insert!');
            }
            
            // Перезагружаем группу с обновленными features
            fn_mwl_xlsx_log_debug('Reloading group from DB...');
            $updated_group = $group_repository->findGroupById($group->getId());
            
            if ($updated_group) {
                fn_mwl_xlsx_log_debug('Group reloaded, features: ' . implode(', ', $updated_group->getFeatureIds()));
                
                try {
                    $reflection = new \ReflectionClass($group);
                    $features_property = $reflection->getProperty('features');
                    $features_property->setAccessible(true);
                    $features_property->setValue($group, $updated_group->getFeatures());
                    
                    fn_mwl_xlsx_log_debug('✓ Successfully updated group features via Reflection');
                    fn_mwl_xlsx_log_debug('Group features after reflection: ' . implode(', ', $group->getFeatureIds()));
                    
                    // ВАЖНО: Если были добавлены новые features, нужно отключить автосинхронизацию для них
                    // чтобы CS-Cart не скопировал значения между вариациями
                    if (!empty($added_feature_ids)) {
                        fn_mwl_xlsx_log_debug('Added features: ' . implode(', ', $added_feature_ids));
                        fn_mwl_xlsx_log_debug('→ These features should NOT be auto-synced between variations');
                        fn_mwl_xlsx_log_debug('→ CS-Cart sync service will run later and may overwrite values!');
                    }
                    
                } catch (\ReflectionException $e) {
                    fn_mwl_xlsx_log_debug('✗ Reflection ERROR: ' . $e->getMessage());
                }
            } else {
                fn_mwl_xlsx_log_debug('✗ ERROR: Failed to reload group from DB');
            }
        }
        
        // ТЕПЕРЬ проверяем и удаляем дубликаты (ПОСЛЕ обновления features)
        fn_mwl_xlsx_log_debug('Checking for duplicate combinations...');
        
        // Получаем combinations новых продуктов из импортируемых данных
        $new_combinations = [];
        foreach ($new_product_ids as $new_pid) {
            if (isset($products[$new_pid]['variation_features'])) {
                $combo = [];
                foreach ($new_feature_ids as $fid) {
                    if (isset($products[$new_pid]['variation_features'][$fid]['variant_id'])) {
                        $combo[$fid] = $products[$new_pid]['variation_features'][$fid]['variant_id'];
                    }
                }
                if (!empty($combo)) {
                    ksort($combo);
                    $combo_key = implode('_', $combo);
                    $new_combinations[$new_pid] = [
                        'key' => $combo_key,
                        'features' => $combo
                    ];
                    fn_mwl_xlsx_log_debug('New product #' . $new_pid . ' combination: ' . $combo_key);
                }
            } else {
                fn_mwl_xlsx_log_debug('New product #' . $new_pid . ': NO variation_features in import data');
            }
        }
        
        // Получаем combinations существующих продуктов из БД
        // Используем только те existing_product_ids которые НЕ в new_product_ids (избегаем сравнения с собой)
        $truly_existing_ids = array_diff($existing_product_ids, $new_product_ids);
        
        if (!empty($truly_existing_ids) && !empty($new_feature_ids)) {
            fn_mwl_xlsx_log_debug('Checking existing products (excluding new): ' . implode(', ', $truly_existing_ids));
            
            $existing_features_db = db_get_array(
                "SELECT product_id, feature_id, variant_id " .
                "FROM ?:product_features_values " .
                "WHERE product_id IN (?a) AND feature_id IN (?a) AND lang_code = 'en' " .
                "ORDER BY product_id, feature_id",
                $truly_existing_ids, $new_feature_ids
            );
            
            $existing_combinations = [];
            foreach ($existing_features_db as $row) {
                $existing_combinations[$row['product_id']][$row['feature_id']] = $row['variant_id'];
            }
            
            $products_to_remove = [];
            foreach ($existing_combinations as $existing_pid => $combo) {
                ksort($combo);
                $combo_key = implode('_', $combo);
                fn_mwl_xlsx_log_debug('Existing product #' . $existing_pid . ' combination: ' . $combo_key);
                
                // Сравниваем с новыми продуктами
                foreach ($new_combinations as $new_pid => $new_combo_data) {
                    if ($combo_key === $new_combo_data['key']) {
                        $products_to_remove[] = $existing_pid;
                        fn_mwl_xlsx_log_debug('⚠ Product #' . $existing_pid . ' has SAME combination as new product #' . $new_pid);
                        fn_mwl_xlsx_log_debug('→ Will remove existing product #' . $existing_pid . ' from group');
                    }
                }
            }
            
            // Удаляем дубликаты
            if (!empty($products_to_remove)) {
                fn_mwl_xlsx_log_debug('Removing ' . count($products_to_remove) . ' existing products with duplicate combinations...');
                foreach ($products_to_remove as $pid_to_remove) {
                    $group->detachProductById($pid_to_remove);
                    fn_mwl_xlsx_log_debug('Detached product #' . $pid_to_remove . ' from group');
                    
                    // ВАЖНО: Отключаем продукт после удаления из группы
                    // чтобы он не остался "осиротевшим" активным продуктом
                    db_query("UPDATE ?:products SET status = 'D' WHERE product_id = ?i", $pid_to_remove);
                    fn_mwl_xlsx_log_debug('Disabled product #' . $pid_to_remove . ' (status=D)');
                }
            } else {
                fn_mwl_xlsx_log_debug('No duplicate combinations with existing products');
            }
        } else {
            fn_mwl_xlsx_log_debug('No existing products to check (or all are being updated)');
        }
        
        // Проверяем дубликаты ВНУТРИ импортируемой партии
        $seen_combinations = [];
        foreach ($new_combinations as $pid => $combo_data) {
            $combo_key = $combo_data['key'];
            if (isset($seen_combinations[$combo_key])) {
                $duplicate_pid = $seen_combinations[$combo_key];
                fn_mwl_xlsx_log_debug('⚠ WARNING: Duplicate in import batch!');
                fn_mwl_xlsx_log_debug('Product #' . $duplicate_pid . ' and #' . $pid . ' both have: ' . $combo_key);
                fn_mwl_xlsx_log_debug('→ CS-Cart will reject one of them');
            } else {
                $seen_combinations[$combo_key] = $pid;
            }
        }
        
        if (count($new_combinations) === count($seen_combinations)) {
            fn_mwl_xlsx_log_debug('✓ All new products have unique combinations within import batch');
        }
        
        fn_mwl_xlsx_log_debug('Duplicate check completed');
        
    } catch (\Exception $e) {
        // Логируем ошибку, но не прерываем процесс импорта
        echo '[MWL_XLSX] ✗ EXCEPTION: ' . $e->getMessage() . PHP_EOL;
        echo '  Trace: ' . $e->getTraceAsString() . PHP_EOL;
    }
    
    fn_mwl_xlsx_log_debug('Hook variation_group_add_products_to_group finished (call #' . $current_call . ')');
    fn_mwl_xlsx_log_debug('========================================');
}

/**
 * Hook: Логирование после сохранения группы вариаций
 * 
 * Используется для debug - показывает какие продукты остались в группе после всех операций
 * 
 * @param \Tygh\Addons\ProductVariations\Service           $service
 * @param \Tygh\Addons\ProductVariations\Product\Group\Group $group
 * @param array                                             $events
 */
function fn_mwl_xlsx_variation_group_save_group($service, $group, $events)
{
    static $save_counter = [];
    
    if (!$group || !$group->getId()) {
        return;
    }
    
    $group_id = $group->getId();
    if (!isset($save_counter[$group_id])) {
        $save_counter[$group_id] = 0;
    }
    $save_counter[$group_id]++;
    $save_num = $save_counter[$group_id];
    
    fn_mwl_xlsx_log_debug('========================================');
    fn_mwl_xlsx_log_debug('Group saved #' . $save_num . ': "' . $group->getCode() . '" (ID:' . $group->getId() . ')');
    fn_mwl_xlsx_log_debug('Products in group: ' . implode(', ', $group->getProductIds()));
    fn_mwl_xlsx_log_debug('Features in group (from object): ' . implode(', ', $group->getFeatureIds()));
    fn_mwl_xlsx_log_debug('Events count: ' . count($events));
    
    // Проверяем features в БД
    $db_features = db_get_array(
        "SELECT feature_id FROM ?:product_variation_group_features WHERE group_id = ?i ORDER BY feature_id",
        $group_id
    );
    fn_mwl_xlsx_log_debug('Features in DB: ' . (empty($db_features) ? 'NONE (table is empty!)' : implode(', ', array_column($db_features, 'feature_id'))));
    
    // Debug: показываем events
    if (!empty($events)) {
        fn_mwl_xlsx_log_debug('Events triggered:');
        foreach ($events as $i => $event) {
            $event_class = get_class($event);
            $event_type = basename(str_replace('\\', '/', $event_class));
            fn_mwl_xlsx_log_debug('  ' . ($i+1) . '. ' . $event_type);
            
            // Показываем детали для ProductUpdatedEvent
            if (method_exists($event, 'getTo') && method_exists($event, 'getFrom')) {
                $from = $event->getFrom();
                $to = $event->getTo();
                fn_mwl_xlsx_log_debug('     From: Product #' . $from->getProductId());
                fn_mwl_xlsx_log_debug('     To: Product #' . $to->getProductId());
                
                if (method_exists($from, 'getFeatureValues') && method_exists($to, 'getFeatureValues')) {
                    $from_values = [];
                    $to_values = [];
                    foreach ($from->getFeatureValues() as $fv) {
                        $from_values[$fv->getFeatureId()] = $fv->getVariantId();
                    }
                    foreach ($to->getFeatureValues() as $fv) {
                        $to_values[$fv->getFeatureId()] = $fv->getVariantId();
                    }
                    if ($from_values != $to_values) {
                        fn_mwl_xlsx_log_debug('     Feature changes: ' . json_encode($from_values) . ' → ' . json_encode($to_values));
                    }
                }
            }
        }
    }
    
    // Проверяем feature combinations всех продуктов в группе
    fn_mwl_xlsx_log_debug('Checking feature combinations in saved group (from DB):');
    $product_ids = $group->getProductIds();
    if (!empty($product_ids)) {
        // Сначала получаем product_code'ы
        $product_codes = db_get_hash_single_array(
            "SELECT product_id, product_code FROM ?:products WHERE product_id IN (?a)",
            ['product_id', 'product_code'],
            $product_ids
        );
        
        $combinations_check = db_get_array(
            "SELECT pfv.product_id, pfv.feature_id, pfv.variant_id, pfv.value_int, pfvd.variant " .
            "FROM ?:product_features_values pfv " .
            "LEFT JOIN ?:product_feature_variant_descriptions pfvd ON pfv.variant_id = pfvd.variant_id AND pfvd.lang_code = 'en' " .
            "WHERE pfv.product_id IN (?a) AND pfv.feature_id IN (?a) AND pfv.lang_code = 'en' " .
            "ORDER BY pfv.product_id, pfv.feature_id",
            $product_ids, $group->getFeatureIds()
        );
        
        $by_product = [];
        $by_product_detailed = [];
        foreach ($combinations_check as $row) {
            $by_product[$row['product_id']][$row['feature_id']] = $row['variant'] ?? $row['variant_id'];
            $by_product_detailed[$row['product_id']][$row['feature_id']] = [
                'variant_id' => $row['variant_id'],
                'variant' => $row['variant'],
                'value_int' => $row['value_int']
            ];
        }
        
        foreach ($by_product as $pid => $features) {
            $product_code = isset($product_codes[$pid]) ? $product_codes[$pid] : 'unknown';
            $feature_list = implode(', ', $features);
            
            // Показываем детали
            $details = [];
            foreach ($by_product_detailed[$pid] as $fid => $info) {
                $details[] = 'F' . $fid . '=' . $info['variant_id'] . '(' . ($info['variant'] ?? $info['value_int']) . ')';
            }
            fn_mwl_xlsx_log_debug('Product #' . $pid . ' [' . $product_code . ']: ' . $feature_list . ' [' . implode(', ', $details) . ']');
        }
        
        // Проверяем дубликаты
        $combinations = [];
        foreach ($by_product as $pid => $features) {
            ksort($features); // Сортируем для консистентности
            $combo_key = implode('_', $features);
            if (isset($combinations[$combo_key])) {
                fn_mwl_xlsx_log_debug('⚠ DUPLICATE COMBINATION detected in saved group!');
                fn_mwl_xlsx_log_debug('Product #' . $pid . ' has same combination as Product #' . $combinations[$combo_key]);
                fn_mwl_xlsx_log_debug('Combination: ' . implode(', ', $features) . ' (key: ' . $combo_key . ')');
                fn_mwl_xlsx_log_debug('→ This should NOT happen! Check why feature values not updated correctly');
            } else {
                $combinations[$combo_key] = $pid;
            }
        }
        
        if (count($by_product) === count($combinations)) {
            fn_mwl_xlsx_log_debug('✓ All products in group have unique combinations');
        }
    } else {
        fn_mwl_xlsx_log_debug('No products in group');
    }
    
    fn_mwl_xlsx_log_debug('========================================');
}

/**
 * Hook: Предотвращает фильтрацию новых variation features при импорте
 * 
 * Проблема: ProductsHookHandler::onUpdateProductFeaturesValuePre удаляет variation features
 * из списка сохранения. Когда мы добавляем новую feature в группу, CS-Cart не сохраняет 
 * её значения, что приводит к ошибке "doesn't have the required features".
 * 
 * Решение: Если мы только что добавили новые variation features через хук 
 * variation_group_add_products_to_group, помечаем в Registry что их НЕ нужно фильтровать.
 * 
 * @param int    $product_id
 * @param array  &$product_features  Массив features для сохранения (по ссылке!)
 * @param array  $add_new_variant
 * @param string $lang_code
 * @param array  $params
 */
function fn_mwl_xlsx_update_product_features_value_pre($product_id, &$product_features, $add_new_variant, $lang_code, $params)
{
    // Проверяем есть ли у продукта группа вариаций
    $group_repository = \Tygh\Addons\ProductVariations\ServiceProvider::getGroupRepository();
    $group_id = $group_repository->findGroupIdByProductId($product_id);
    
    if (!$group_id) {
        return; // Не в группе вариаций
    }
    
    // НОВАЯ ЛОГИКА: Сохраняем ВСЕ исходные feature values ДО того как CS-Cart их синхронизирует
    // Это нужно для import_post чтобы восстановить правильные значения после синхронизации
    //
    // NOTE: Этот хук вызывается ДО variation_group_add_products_to_group, 
    // поэтому мы ещё не знаем какие группы требуют обработки.
    // Сохраняем ВСЕ features для ВСЕХ продуктов в variation groups.
    
    static $saved_count = [];
    if (!isset($saved_count[$group_id])) {
        $saved_count[$group_id] = 0;
        fn_mwl_xlsx_log_debug('========================================');
        fn_mwl_xlsx_log_debug('Hook update_product_features_value_pre for group #' . $group_id);
    }
    $saved_count[$group_id]++;
    
    fn_mwl_xlsx_log_debug('  - Saving original feature values for product #' . $product_id);
    fn_mwl_xlsx_log_debug('    Available product_features (' . count($product_features) . '): ' . implode(', ', array_keys($product_features)));
    
    // Конвертируем $product_features в формат который использует import_post
    // $product_features имеет формат: feature_id => variant_id (или массив для множественных)
    $converted_features = [];
    foreach ($product_features as $feature_id => $variant_id) {
        // Обрабатываем множественные features (тип M) - они приходят как массив
        if (is_array($variant_id)) {
            // Для множественных features берем первый элемент (или все, если нужно)
            // В variation groups обычно используется только один variant
            $variant_id = !empty($variant_id) ? reset($variant_id) : null;
        }
        
        if ($variant_id === null) {
            continue; // Пропускаем пустые значения
        }
        
        $converted_features[$feature_id] = [
            'variant_id' => $variant_id,
            'value' => $variant_id
        ];
        
        // Debug: показываем feature name и variant
        $feature_name = db_get_field("SELECT description FROM ?:product_features_descriptions WHERE feature_id = ?i AND lang_code = 'en'", $feature_id);
        $variant_name = db_get_field("SELECT variant FROM ?:product_feature_variant_descriptions WHERE variant_id = ?i AND lang_code = 'en'", $variant_id);
        fn_mwl_xlsx_log_debug('      * Feature #' . $feature_id . ' (' . ($feature_name ?: 'unknown') . '): variant_id=' . (is_array($variant_id) ? '[' . implode(',', $variant_id) . ']' : $variant_id) . ' (' . ($variant_name ?: 'unknown') . ')');
    }
    
    // Сохраняем в статическое хранилище (объединяем с уже сохраненными)
    $existing_map = fn_mwl_xlsx_get_products_features($group_id) ?: [];
    $existing_map[$product_id] = $converted_features;
    fn_mwl_xlsx_set_products_features($group_id, $existing_map);
    
    fn_mwl_xlsx_log_debug('    → Saved ' . count($converted_features) . ' features to static storage');
    fn_mwl_xlsx_log_debug('    → Total products in storage for group #' . $group_id . ': ' . count($existing_map));
}

/**
 * Hook: Пересоздает группы вариаций после импорта когда все feature values сохранены
 * 
 * Проблема: Когда мы добавляем новую feature в группу во время импорта,
 * CS-Cart фильтрует её из сохранения и затем выдает ошибку "doesn't have required features".
 * 
 * Решение: После импорта проверяем группы для которых мы обновляли features,
 * и пересоздаем их заново с актуальными feature values из БД.
 * 
 * @param array $pattern
 * @param array $import_data
 * @param array $options
 * @param array $result
 * @param array $processed_data
 */
function fn_mwl_xlsx_import_post($pattern, $import_data, $options, $result, $processed_data)
{
    fn_mwl_xlsx_log_debug('========================================');
    fn_mwl_xlsx_log_debug('Hook import_post CALLED!');
    fn_mwl_xlsx_log_debug('Pattern section: ' . (isset($pattern['section']) ? $pattern['section'] : 'NOT SET'));
    fn_mwl_xlsx_log_debug('Pattern pattern_id: ' . (isset($pattern['pattern_id']) ? $pattern['pattern_id'] : 'NOT SET'));
    fn_mwl_xlsx_log_debug('Import data rows: ' . (is_array($import_data) ? count($import_data) : 'NOT ARRAY'));
    
    // Проверяем что это импорт продуктов
    if (empty($pattern['pattern_id']) || $pattern['pattern_id'] !== 'products') {
        fn_mwl_xlsx_log_debug('Not a products import, skipping (pattern_id=' . (isset($pattern['pattern_id']) ? $pattern['pattern_id'] : 'NULL') . ')');
        fn_mwl_xlsx_log_debug('========================================');
        return;
    }
    
    fn_mwl_xlsx_log_debug('Products import confirmed, processing...');
    
    // Получаем список групп из статического хранилища
    $groups_to_fix = fn_mwl_xlsx_get_groups_to_fix();
    
    fn_mwl_xlsx_log_debug('Groups in static storage: ' . count($groups_to_fix));
    if (!empty($groups_to_fix)) {
        foreach ($groups_to_fix as $gid => $data) {
            fn_mwl_xlsx_log_debug('  Group #' . $gid . ': features=[' . implode(',', $data['feature_ids']) . '], update_scenario=' . ($data['is_update_scenario'] ? 'true' : 'false'));
        }
    }
    
    if (empty($groups_to_fix)) {
        fn_mwl_xlsx_log_debug('No groups to fix found, skipping post-processing');
        fn_mwl_xlsx_log_debug('========================================');
        return;
    }
    
    fn_mwl_xlsx_log_debug('Found ' . count($groups_to_fix) . ' groups to process');
    
    $group_repository = \Tygh\Addons\ProductVariations\ServiceProvider::getGroupRepository();
    
    // ВАЖНО: Для групп с новыми features нужно принудительно обновить feature values
    // потому что ProductsHookHandler отфильтровал их при сохранении
    foreach ($groups_to_fix as $group_id => $group_data) {
        $new_feature_ids = $group_data['feature_ids'];
        $is_update_scenario = $group_data['is_update_scenario'];
        
        if ($is_update_scenario) {
            fn_mwl_xlsx_log_debug('Processing group #' . $group_id . ' in UPDATE SCENARIO mode');
            fn_mwl_xlsx_log_debug('→ Will verify/fix ALL variation feature values: ' . implode(', ', $new_feature_ids));
        } else {
            fn_mwl_xlsx_log_debug('Processing group #' . $group_id . ' with new features: ' . implode(', ', $new_feature_ids));
        }
        
        $group = $group_repository->findGroupById($group_id);
        if (!$group) {
            fn_mwl_xlsx_log_debug('Group not found, skipping');
            continue;
        }
        
        // В update_scenario используем ВСЕ features группы из БД, а не только из статического хранилища
        // потому что features могут быть добавлены в разных вызовах хука
        if ($is_update_scenario) {
            $all_group_features = db_get_fields(
                "SELECT feature_id FROM ?:product_variation_group_features WHERE group_id = ?i ORDER BY feature_id",
                $group_id
            );
            if (!empty($all_group_features)) {
                $new_feature_ids = $all_group_features;
                fn_mwl_xlsx_log_debug('→ Using ALL group features from DB: ' . implode(', ', $new_feature_ids));
            }
        }
        
        $product_ids = $group->getProductIds();
        fn_mwl_xlsx_log_debug('Products currently in group: ' . (empty($product_ids) ? 'none' : implode(', ', $product_ids)));
        
        // ВАЖНО: Ищем ВСЕ продукты из import_data с этим variation_group_code,
        // а не только те, которые уже в группе
        // Это нужно потому что продукты могут не быть добавлены в группу из-за отсутствия required features
        $group_code = $group->getCode();
        fn_mwl_xlsx_log_debug('Searching for products with variation_group_code="' . $group_code . '" in import_data (total rows: ' . count($import_data) . ')...');
        
        $products_to_fix = [];
        $product_codes_to_find = [];
        
        // Сначала собираем все product_code из import_data с нужным variation_group_code
        foreach ($import_data as $idx => $import_row) {
            $row = reset($import_row);
            $row_group_code = isset($row['variation_group_code']) ? $row['variation_group_code'] : (isset($row['Variation group code']) ? $row['Variation group code'] : null);
            $row_product_code = isset($row['product_code']) ? $row['product_code'] : null;
            
            if ($row_group_code === $group_code && $row_product_code) {
                $product_codes_to_find[] = $row_product_code;
            }
        }
        
        if (empty($product_codes_to_find)) {
            fn_mwl_xlsx_log_debug('No products with variation_group_code="' . $group_code . '" in import data');
            continue;
        }
        
        fn_mwl_xlsx_log_debug('Found ' . count($product_codes_to_find) . ' product codes with this group code: ' . implode(', ', $product_codes_to_find));
        
        // Получаем product_id для всех найденных product_code
        $product_codes_map = db_get_hash_single_array(
            "SELECT product_id, product_code FROM ?:products WHERE product_code IN (?a)",
            ['product_code', 'product_id'],
            $product_codes_to_find
        );
        
        fn_mwl_xlsx_log_debug('Product IDs found: ' . implode(', ', array_map(function($code, $pid) {
            return '#' . $pid . '=' . $code;
        }, array_keys($product_codes_map), $product_codes_map)));
        
        // Теперь находим соответствующие строки в import_data
        foreach ($import_data as $idx => $import_row) {
            $row = reset($import_row);
            $row_product_code = isset($row['product_code']) ? $row['product_code'] : null;
            
            if ($row_product_code && isset($product_codes_map[$row_product_code])) {
                $found_pid = $product_codes_map[$row_product_code];
                $products_to_fix[$found_pid] = $row;
                fn_mwl_xlsx_log_debug('  * Found product #' . $found_pid . ' (code: ' . $row_product_code . ') at import_data[' . $idx . ']');
            }
        }
        
        if (empty($products_to_fix)) {
            fn_mwl_xlsx_log_debug('No products from this group in import data');
            continue;
        }
        
        fn_mwl_xlsx_log_debug('Found ' . count($products_to_fix) . ' products in import data to fix');
        
        // Получаем product_features из статического хранилища
        $products_features_map = fn_mwl_xlsx_get_products_features($group_id);
        fn_mwl_xlsx_log_debug('Products features map contains ' . (empty($products_features_map) ? '0' : count($products_features_map)) . ' products');
        
        // Для продуктов, которых нет в статическом хранилище, получаем features из БД
        // Это нужно для продуктов, которые ещё не в группе
        $missing_pids = array_diff(array_keys($products_to_fix), array_keys($products_features_map ?: []));
        if (!empty($missing_pids)) {
            fn_mwl_xlsx_log_debug('Products not in static storage (will load from DB): ' . implode(', ', $missing_pids));
            
            // Загружаем features из БД для этих продуктов
            $db_features = db_get_array(
                "SELECT product_id, feature_id, variant_id " .
                "FROM ?:product_features_values " .
                "WHERE product_id IN (?a) AND feature_id IN (?a) AND lang_code = 'en'",
                $missing_pids, $new_feature_ids
            );
            
            foreach ($db_features as $row) {
                $pid = $row['product_id'];
                $fid = $row['feature_id'];
                $vid = $row['variant_id'];
                
                if (!isset($products_features_map[$pid])) {
                    $products_features_map[$pid] = [];
                }
                $products_features_map[$pid][$fid] = [
                    'variant_id' => $vid,
                    'value' => $vid
                ];
            }
            
            fn_mwl_xlsx_log_debug('Loaded ' . count($db_features) . ' features from DB for missing products');
        }
        
        // Для каждого продукта обновляем feature values для новых features
        $products_with_changes = [];
        foreach ($products_to_fix as $pid => $import_row) {
            if (!isset($products_features_map[$pid])) {
                fn_mwl_xlsx_log_debug('Product #' . $pid . ': NO product_features available (not in storage and not in DB)');
                continue;
            }
            
            $product_features = $products_features_map[$pid];
            if (!is_array($product_features)) {
                fn_mwl_xlsx_log_debug('Product #' . $pid . ': product_features is not array: ' . gettype($product_features));
                continue;
            }
            
            fn_mwl_xlsx_log_debug('Fixing product #' . $pid . ' feature values...');
            fn_mwl_xlsx_log_debug('Available product_features: ' . implode(', ', array_keys($product_features)));
            
            $features_fixed = 0;
            // Проверяем какие из новых features есть в импортируемых данных
            foreach ($new_feature_ids as $new_fid) {
                if (isset($product_features[$new_fid])) {
                    $feature_data = $product_features[$new_fid];
                    $variant_id = isset($feature_data['variant_id']) ? $feature_data['variant_id'] : (isset($feature_data['value']) ? $feature_data['value'] : null);
                    
                    if (!$variant_id) {
                        fn_mwl_xlsx_log_debug('  * Feature #' . $new_fid . ': NO variant_id found');
                        continue;
                    }
                    
                    // Получаем название feature и variant для логирования
                    $feature_name = db_get_field("SELECT description FROM ?:product_features_descriptions WHERE feature_id = ?i AND lang_code = 'en'", $new_fid);
                    $variant_name = db_get_field("SELECT variant FROM ?:product_feature_variant_descriptions WHERE variant_id = ?i AND lang_code = 'en'", $variant_id);
                    
                    fn_mwl_xlsx_log_debug('  * Feature #' . $new_fid . ' (' . ($feature_name ?: 'unknown') . '): will update to variant_id=' . $variant_id . ' (' . ($variant_name ?: 'unknown') . ')');
                    
                    // Обновляем или создаем feature value для всех языков
                    $lang_codes = db_get_fields("SELECT lang_code FROM ?:languages WHERE status = 'A'");
                    $total_updated = 0;
                    
                    foreach ($lang_codes as $lang) {
                        // Проверяем существование записи и текущее значение
                        $current = db_get_row(
                            "SELECT variant_id FROM ?:product_features_values WHERE product_id = ?i AND feature_id = ?i AND lang_code = ?s",
                            $pid, $new_fid, $lang
                        );
                        
                        if ($current) {
                            // Запись существует
                            if ($current['variant_id'] == $variant_id) {
                                // Значение уже правильное, пропускаем
                                continue;
                            }
                            // Обновляем существующую запись
                            $updated = db_query(
                                "UPDATE ?:product_features_values SET variant_id = ?i WHERE product_id = ?i AND feature_id = ?i AND lang_code = ?s",
                                $variant_id, $pid, $new_fid, $lang
                            );
                            if ($updated) {
                                $total_updated++;
                            }
                        } else {
                            // Создаем новую запись
                            $updated = db_query(
                                "INSERT INTO ?:product_features_values (product_id, feature_id, variant_id, lang_code) VALUES (?i, ?i, ?i, ?s)",
                                $pid, $new_fid, $variant_id, $lang
                            );
                            if ($updated) {
                                $total_updated++;
                            }
                        }
                    }
                    
                    if ($total_updated > 0) {
                        $features_fixed++;
                    }
                    fn_mwl_xlsx_log_debug('  → Updated/Inserted ' . $total_updated . ' rows in DB (' . count($lang_codes) . ' languages)');
                } else {
                    fn_mwl_xlsx_log_debug('  * Feature #' . $new_fid . ': NOT in product_features map');
                }
            }
            
            // Log brief info only when there are changes
            if ($features_fixed > 0) {
                $products_with_changes[$pid] = $features_fixed;
            }
        }
        
        // Log brief summary for products with changes
        if (!empty($products_with_changes)) {
            foreach ($products_with_changes as $pid => $count) {
                fn_mwl_xlsx_log_info('Product #' . $pid . ': fixed ' . $count . ' feature(s)');
            }
        }
        
        // Проверяем итоговое состояние feature values (debug only)
        $all_product_ids = array_keys($products_to_fix);
        fn_mwl_xlsx_log_debug('Verifying fixed feature values from DB (products: ' . implode(', ', $all_product_ids) . '):');
        $verification = db_get_array(
            "SELECT pfv.product_id, pfv.feature_id, pfv.variant_id, pfvd.variant " .
            "FROM ?:product_features_values pfv " .
            "LEFT JOIN ?:product_feature_variant_descriptions pfvd ON pfv.variant_id = pfvd.variant_id AND pfvd.lang_code = 'en' " .
            "WHERE pfv.product_id IN (?a) AND pfv.feature_id IN (?a) AND pfv.lang_code = 'en' " .
            "ORDER BY pfv.product_id, pfv.feature_id",
            $all_product_ids, $new_feature_ids
        );
        
        $by_prod = [];
        foreach ($verification as $row) {
            $by_prod[$row['product_id']][$row['feature_id']] = $row['variant'] . ' (vid:' . $row['variant_id'] . ')';
        }
        
        foreach ($by_prod as $pid => $feats) {
            fn_mwl_xlsx_log_debug('Product #' . $pid . ': ' . implode(', ', $feats));
        }
        
    }
    
    // Очищаем статическое хранилище после обработки
    fn_mwl_xlsx_get_groups_to_fix([]);
    
    fn_mwl_xlsx_log_debug('Post-import feature values fix completed');
    fn_mwl_xlsx_log_debug('========================================');
}

/**
 * Парсит строку features из CSV и возвращает массив feature_name => feature_type
 * 
 * Формат: "Genre: S[Interview]; URL: T[https://techbullion.com]; Author: S[Journalist's full name]; ..."
 * 
 * @param string $features_string Строка features из CSV
 * @return array<string, string> Массив ['feature_name' => 'feature_type'] (например, ['Genre' => 'S', 'URL' => 'T'])
 */

/**
 * Находит feature_id по названию feature
 * 
 * @param string $feature_name Название feature (например, "Genre")
 * @param string $lang_code Код языка (по умолчанию 'en')
 * @return int|null feature_id или null если не найдено
 */

/**
 * Синхронизирует features группы вариаций на основе данных из CSV
 * 
 * @param int $group_id ID группы вариаций
 * @param array<string, string> $csv_features Массив ['feature_name' => 'feature_type'] из CSV
 * @param bool $debug Флаг отладки (по умолчанию false)
 * @return array{added: array<int>, removed: array<int>, errors: array<string>, warnings: array<string>} Результат синхронизации
 */

/**
 * Hook handler: preserves SEO URL for default variation products during import
 * 
 * If a product with "Variation set as default = Y" has its SEO URL occupied by another product
 * from the same variation group, frees the URL by reassigning it to the occupying product.
 * 
 * @param int    $object_id         Object ID
 * @param string $object_type       Object type
 * @param string $object_name       Object name (by reference)
 * @param int    $index             Index
 * @param string $dispatch          Dispatch (for static object type)
 * @param int    $company_id        Company ID
 * @param string $lang_code         Two-letter language code
 * @param array  $params            Additional params
 * @param bool   $create_redirect   Creates 301 redirect if set to true
 * @param string $area              Current working area
 * @param bool   $changed           Object reformat indicator
 * @param string $input_object_name Entered object name
 */
function fn_mwl_xlsx_create_seo_name_pre(
    $object_id,
    $object_type,
    &$object_name,
    $index,
    $dispatch,
    $company_id,
    $lang_code,
    $params,
    $create_redirect,
    $area,
    $changed,
    $input_object_name
) {
    // Protection against recursion when freeing URL for occupying product
    static $processing_products = [];
    
    if (isset($processing_products[$object_id])) {
        // Already processing this product, skip to avoid recursion
        return;
    }
    
    // Only process products
    if ($object_type !== 'p' || empty($object_id) || $object_id <= 0) {
        return;
    }
    
    // Mark this product as being processed
    $processing_products[$object_id] = true;
    
    // Check if this product is a default variation
    // Default variation is determined by parent_product_id: if NULL or 0, it's the default
    $variation_info = db_get_row(
        "SELECT group_id, parent_product_id 
         FROM ?:product_variation_group_products 
         WHERE product_id = ?i AND (parent_product_id IS NULL OR parent_product_id = 0)",
        $object_id
    );
    
    if (empty($variation_info) || empty($variation_info['group_id'])) {
        // Not a default variation product, skip
        unset($processing_products[$object_id]);
        return;
    }
    
    $default_group_id = $variation_info['group_id'];
    
    // Normalize object name the same way fn_create_seo_name does
    $seo_settings = fn_get_seo_settings($company_id);
    $non_latin_symbols = $seo_settings['non_latin_symbols'];
    
    $normalized_name = fn_seo_normalize_object_name(
        fn_generate_name($object_name, '', 0, ($non_latin_symbols === YesNo::YES))
    );
    
    if (empty($normalized_name)) {
        $seo_var = fn_get_seo_vars($object_type);
        $normalized_name = fn_seo_normalize_object_name(
            $seo_var['description'] . '-' . $object_id
        );
    }
    
    // Check if this SEO name is occupied by another product
    $condition = fn_get_seo_company_condition('?:seo_names.company_id', $object_type, $company_id);
    
    $path_condition = '';
    $seo_var = fn_get_seo_vars($object_type);
    if (fn_check_seo_schema_option($seo_var, 'tree_options')) {
        $path_condition = db_quote(
            " AND path = ?s",
            fn_get_seo_parent_path($object_id, $object_type, $company_id, true)
        );
    }
    
    $occupied_by = db_get_row(
        "SELECT object_id, name 
         FROM ?:seo_names 
         WHERE name = ?s ?p 
           AND object_id != ?i 
           AND type = ?s 
           AND (dispatch = ?s OR dispatch = '') 
           AND lang_code = ?s ?p",
        $normalized_name,
        $path_condition,
        $object_id,
        $object_type,
        $dispatch,
        $lang_code,
        $condition
    );
    
    if (empty($occupied_by)) {
        // SEO URL is not occupied, nothing to do
        unset($processing_products[$object_id]);
        return;
    }
    
    $occupying_product_id = $occupied_by['object_id'];
    
    // Check if the occupying product is in the same variation group
    $occupying_variation_info = db_get_row(
        "SELECT group_id 
         FROM ?:product_variation_group_products 
         WHERE product_id = ?i",
        $occupying_product_id
    );
    
    if (empty($occupying_variation_info) || 
        $occupying_variation_info['group_id'] != $default_group_id) {
        // Occupying product is from a different group, use standard behavior (add lang_code)
        // Don't modify $object_name, let fn_create_seo_name handle it
        unset($processing_products[$object_id]);
        return;
    }
    
    // Occupying product is from the same group - free the URL
    // Mark occupying product as being processed to avoid recursion
    $processing_products[$occupying_product_id] = true;
    
    // Get product name for the occupying product to generate new SEO name
    $occupying_product_name = db_get_field(
        "SELECT product 
         FROM ?:product_descriptions 
         WHERE product_id = ?i AND lang_code = ?s",
        $occupying_product_id,
        $lang_code
    );
    
    if (empty($occupying_product_name)) {
        // Fallback to product_code if name is not available
        $occupying_product_name = db_get_field(
            "SELECT product_code FROM ?:products WHERE product_id = ?i",
            $occupying_product_id
        );
        
        if (empty($occupying_product_name)) {
            $occupying_product_name = 'product-' . $occupying_product_id;
        }
    }
    
    // Delete the old SEO name to free the URL
    $delete_condition = fn_get_seo_company_condition('?:seo_names.company_id', $object_type, $company_id);
    db_query(
        "DELETE FROM ?:seo_names 
         WHERE object_id = ?i 
           AND type = ?s 
           AND dispatch = ?s 
           AND name = ?s 
           AND lang_code = ?s ?p",
        $occupying_product_id,
        $object_type,
        $dispatch,
        $normalized_name,
        $lang_code,
        $delete_condition
    );
    
    // Generate new SEO name for the occupying product
    // Add suffix to make it unique
    $new_base_name = $occupying_product_name . '-variant';
    $new_seo_name = fn_create_seo_name(
        $occupying_product_id,
        $object_type,
        $new_base_name,
        0,
        $dispatch,
        $company_id,
        $lang_code,
        false,
        $area,
        $params,
        false,
        ''
    );
    
    if (!empty($new_seo_name)) {
        // Log the action for debugging
        if (defined('DEVELOPMENT') || Registry::get('runtime.advanced_import.in_progress')) {
            echo "[MWL_XLSX] SEO URL preservation: Freed URL '{$normalized_name}' from product #{$occupying_product_id} " .
                 "(same variation group #{$default_group_id}), assigned new URL '{$new_seo_name}'" . PHP_EOL;
        }
    }
    
    // Unmark both products from processing
    unset($processing_products[$object_id]);
    unset($processing_products[$occupying_product_id]);
}
