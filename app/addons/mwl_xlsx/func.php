<?php
use Tygh\Http;
use Tygh\Addons\MwlXlsx\Customer\StatusResolver;
use Tygh\Addons\MwlXlsx\MediaList\ListRepository;
use Tygh\Addons\MwlXlsx\MediaList\ListService;
use Tygh\Addons\MwlXlsx\Planfix\EventRepository;
use Tygh\Addons\MwlXlsx\Planfix\IntegrationSettings;
use Tygh\Addons\MwlXlsx\Planfix\LinkRepository;
use Tygh\Addons\MwlXlsx\Planfix\StatusMapRepository;
use Tygh\Addons\MwlXlsx\Planfix\McpClient;
use Tygh\Addons\MwlXlsx\Import\MwlImportProfiler;
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
    echo '[INFO] ' . $message . PHP_EOL;
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
        echo '[DEBUG] ' . $message . PHP_EOL;
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
    $log_dir = Registry::get('config.dir.root') . '/var/files/log';
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
    $profiler = MwlImportProfiler::instance();
    if ($profiler->isEnabled() && $object === 'product' && !empty($object_id)) {
        $profiler->startProduct($object_id);
        $profiler->stepStart('image_check');
    }

    // Allow skipping all image imports via CLI: --skip_images=1
    if (!empty($_REQUEST['skip_images'])) {
        $perform_import = false;
        if ($profiler->isEnabled()) {
            $profiler->increment('image_skipped');
            $profiler->stepEnd('image_check');
        }
        return;
    }

    if ($object !== 'product' || empty($object_id)) {
        return;
    }

    $main_pair = fn_get_image_pairs($object_id, 'product', 'M', true, true);
    if (!empty($main_pair)) {
        $perform_import = false;
        echo 'image_exists' . PHP_EOL;
        if ($profiler->isEnabled()) {
            $profiler->increment('image_exists');
            $profiler->stepEnd('image_check');
        }
        return;
    }

    if (!$detailed_file) {
        $perform_import = false;
        echo 'no_image' . PHP_EOL;
        if ($profiler->isEnabled()) {
            $profiler->increment('no_image');
            $profiler->stepEnd('image_check');
        }
        return;
    }

    echo 'image_import' . PHP_EOL;
    if ($profiler->isEnabled()) {
        $profiler->increment('image_import');
        $profiler->stepEnd('image_check');
        $profiler->stepStart('image_save');
    }
}

/**
 * Hook handler: tracks image save timing for import profiler.
 */
function fn_mwl_xlsx_update_image(&$image_data, $image_id, $image_type, $images_path, $_data, $mime_type, $is_clone)
{
    $profiler = MwlImportProfiler::instance();
    if ($profiler->isEnabled()) {
        $profiler->stepEnd('image_save');
        $profiler->stepStart('image_save');
    }
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
    if (empty($products) || !$group || !$group->getId()) {
        return;
    }

    $group_id = $group->getId();
    $import_prepare_done = \Tygh\Registry::get('mwl_xlsx.import_prepare_done');

    try {
        $new_product_ids = array_keys($products);
        $existing_product_ids = $group->getProductIds();

        // Get feature IDs for duplicate detection
        $feature_ids = $group->getFeatureIds();
        if (empty($feature_ids)) {
            $db_features = db_get_fields(
                "SELECT feature_id FROM ?:product_variation_group_features WHERE group_id = ?i",
                $group_id
            );
            $feature_ids = $db_features ?: [];
        }

        // When import_prepare already ran, skip feature sync — only do duplicate detection
        if (!$import_prepare_done) {
            // Full feature sync path (when called outside of import pipeline)
            $this_runs_full_sync = true;
            fn_mwl_xlsx_log_debug('Hook: full sync mode (import_prepare not run)');

            $product_repository = \Tygh\Addons\ProductVariations\ServiceProvider::getProductRepository();
            $group_repository = \Tygh\Addons\ProductVariations\ServiceProvider::getGroupRepository();
            $current_feature_ids = $feature_ids;
            $available_features = [];

            foreach ($new_product_ids as $check_product_id) {
                $product_features = $product_repository->findAvailableFeatures($check_product_id);
                foreach ($product_features as $feature_id => $feature) {
                    if (!isset($available_features[$feature_id])) {
                        $available_features[$feature_id] = $feature;
                    }
                }
            }

            if (empty($available_features) && !empty($existing_product_ids)) {
                $available_features = $product_repository->findAvailableFeatures(reset($existing_product_ids));
            }

            if (!empty($available_features)) {
                $new_feature_ids = array_keys($available_features);
                $removed_feature_ids = array_diff($current_feature_ids, $new_feature_ids);

                if (!empty($removed_feature_ids)) {
                    db_query("DELETE FROM ?:product_variation_group_features WHERE group_id = ?i", $group_id);
                    foreach ($available_features as $feature) {
                        db_query(
                            "INSERT INTO ?:product_variation_group_features (group_id, feature_id, purpose) VALUES (?i, ?i, ?s)",
                            $group_id, $feature['feature_id'], $feature['purpose']
                        );
                    }

                    $updated_group = $group_repository->findGroupById($group_id);
                    if ($updated_group) {
                        $reflection = new \ReflectionClass($group);
                        $features_property = $reflection->getProperty('features');
                        $features_property->setAccessible(true);
                        $features_property->setValue($group, $updated_group->getFeatures());
                    }
                }

                $feature_ids = $new_feature_ids;
            }
        }

        // --- Duplicate detection (always runs) ---
        if (empty($feature_ids)) {
            return;
        }

        // Build combinations for new products from import data
        $new_combinations = [];
        foreach ($new_product_ids as $new_pid) {
            if (!isset($products[$new_pid]['variation_features'])) {
                continue;
            }
            $combo = [];
            foreach ($feature_ids as $fid) {
                if (isset($products[$new_pid]['variation_features'][$fid]['variant_id'])) {
                    $combo[$fid] = $products[$new_pid]['variation_features'][$fid]['variant_id'];
                }
            }
            if (!empty($combo)) {
                ksort($combo);
                $new_combinations[$new_pid] = implode('_', $combo);
            }
        }

        // Check existing products for duplicate combinations
        $truly_existing_ids = array_diff($existing_product_ids, $new_product_ids);

        if (!empty($truly_existing_ids) && !empty($new_combinations)) {
            $existing_features_db = db_get_array(
                "SELECT product_id, feature_id, variant_id " .
                "FROM ?:product_features_values " .
                "WHERE product_id IN (?a) AND feature_id IN (?a) AND lang_code = 'en' " .
                "ORDER BY product_id, feature_id",
                $truly_existing_ids, $feature_ids
            );

            $existing_combinations = [];
            foreach ($existing_features_db as $row) {
                $existing_combinations[$row['product_id']][$row['feature_id']] = $row['variant_id'];
            }

            $new_combo_keys = array_flip($new_combinations);

            foreach ($existing_combinations as $existing_pid => $combo) {
                ksort($combo);
                $combo_key = implode('_', $combo);

                if (isset($new_combo_keys[$combo_key])) {
                    $group->detachProductById($existing_pid);
                    db_query("UPDATE ?:products SET status = 'D' WHERE product_id = ?i", $existing_pid);
                    fn_mwl_xlsx_log_debug('Detached duplicate product #' . $existing_pid . ' from group #' . $group_id);
                }
            }
        }

    } catch (\Exception $e) {
        echo '[MWL_XLSX] ERROR: ' . $e->getMessage() . PHP_EOL;
    }
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
                        fn_mwl_xlsx_log_info('     Feature changes: ' . json_encode($from_values) . ' → ' . json_encode($to_values));
                    }
                }
            }
        }
    }
    
    // Проверяем feature combinations всех продуктов в группе
    // Важно: проверяем ТОЛЬКО активные продукты (status = 'A')
    // Игнорируем отключенные продукты (status = 'D'), т.к. они могут быть дубликатами перед удалением
    fn_mwl_xlsx_log_debug('Checking feature combinations in saved group (from DB, active products only):');
    $product_ids = $group->getProductIds();
    if (!empty($product_ids)) {
        // Получаем только активные продукты с дополнительной информацией
        $active_products_info = db_get_array(
            "SELECT product_id, product_code, status, timestamp, updated_timestamp " .
            "FROM ?:products WHERE product_id IN (?a) AND status = 'A'",
            $product_ids
        );
        
        if (empty($active_products_info)) {
            fn_mwl_xlsx_log_debug('No active products in group, skipping duplicate check');
            fn_mwl_xlsx_log_debug('========================================');
            return;
        }
        
        $active_product_ids = array_column($active_products_info, 'product_id');
        $product_codes = [];
        $product_ages = []; // Для определения новых продуктов
        $current_time = TIME;
        foreach ($active_products_info as $info) {
            $product_codes[$info['product_id']] = $info['product_code'];
            // Продукт считается "новым" если создан менее 5 минут назад (во время импорта)
            $product_age = $current_time - max($info['timestamp'], $info['updated_timestamp'] ?: 0);
            $product_ages[$info['product_id']] = $product_age < 300 ? 'new' : 'existing';
        }
        
        $combinations_check = db_get_array(
            "SELECT pfv.product_id, pfv.feature_id, pfv.variant_id, pfv.value_int, pfvd.variant " .
            "FROM ?:product_features_values pfv " .
            "LEFT JOIN ?:product_feature_variant_descriptions pfvd ON pfv.variant_id = pfvd.variant_id AND pfvd.lang_code = 'en' " .
            "WHERE pfv.product_id IN (?a) AND pfv.feature_id IN (?a) AND pfv.lang_code = 'en' " .
            "ORDER BY pfv.product_id, pfv.feature_id",
            $active_product_ids, $group->getFeatureIds()
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
            $product_age = isset($product_ages[$pid]) ? $product_ages[$pid] : 'unknown';
            $feature_list = implode(', ', $features);
            
            // Показываем детали
            $details = [];
            foreach ($by_product_detailed[$pid] as $fid => $info) {
                $details[] = 'F' . $fid . '=' . $info['variant_id'] . '(' . ($info['variant'] ?? $info['value_int']) . ')';
            }
            fn_mwl_xlsx_log_debug('Product #' . $pid . ' [' . $product_code . '] (' . $product_age . '): ' . $feature_list . ' [' . implode(', ', $details) . ']');
        }
        
        // Проверяем дубликаты
        // Важно: сравниваем ТОЛЬКО variation features (те что в $group->getFeatureIds())
        // Не сравниваем shared features (URL, Type of media, Region и т.д.) - они одинаковые для всех продуктов
        $variation_feature_ids = $group->getFeatureIds();
        if (empty($variation_feature_ids)) {
            fn_mwl_xlsx_log_debug('No variation features in group, skipping duplicate check');
        } else {
            $combinations = [];
            foreach ($by_product as $pid => $features) {
                // Фильтруем только variation features для сравнения
                $variation_features_only = [];
                foreach ($variation_feature_ids as $fid) {
                    if (isset($features[$fid])) {
                        $variation_features_only[$fid] = $features[$fid];
                    }
                }
                
                if (empty($variation_features_only)) {
                    fn_mwl_xlsx_log_debug('Product #' . $pid . ' has no variation features, skipping');
                    continue;
                }
                
                ksort($variation_features_only); // Сортируем для консистентности
                $combo_key = implode('_', $variation_features_only);
                
                if (isset($combinations[$combo_key])) {
                    $existing_pid = $combinations[$combo_key];
                    $existing_code = isset($product_codes[$existing_pid]) ? $product_codes[$existing_pid] : 'unknown';
                    $existing_age = isset($product_ages[$existing_pid]) ? $product_ages[$existing_pid] : 'unknown';
                    $current_code = isset($product_codes[$pid]) ? $product_codes[$pid] : 'unknown';
                    $current_age = isset($product_ages[$pid]) ? $product_ages[$pid] : 'unknown';
                    
                    // Подавляем предупреждения для продуктов, которые оба "новые" (созданы во время импорта)
                    // Это временные дубликаты, которые будут разрешены логикой удаления дубликатов или валидацией CS-Cart
                    if ($current_age === 'new' && $existing_age === 'new') {
                        fn_mwl_xlsx_log_debug('Duplicate combination detected between new products #' . $pid . ' [' . $current_code . '] and #' . $existing_pid . ' [' . $existing_code . '] - suppressing warning (temporary during import)');
                        // Не добавляем в combinations, чтобы не создавать ложные предупреждения для следующих продуктов
                        continue;
                    }
                    
                    // Улучшенное логирование с контекстом для реальных проблем
                    fn_mwl_xlsx_log_info('⚠ WARNING: DUPLICATE COMBINATION detected in saved group!');
                    fn_mwl_xlsx_log_info('Product #' . $pid . ' [' . $current_code . '] (' . $current_age . ') has same combination as Product #' . $existing_pid . ' [' . $existing_code . '] (' . $existing_age . ')');
                    
                    // Показываем только variation features в сообщении
                    $feature_names = [];
                    foreach ($variation_features_only as $fid => $value) {
                        $feature_name = db_get_field(
                            "SELECT description FROM ?:product_features_descriptions WHERE feature_id = ?i AND lang_code = 'en'",
                            $fid
                        ) ?: "Feature #{$fid}";
                        $feature_names[] = $feature_name . '=' . $value;
                    }
                    fn_mwl_xlsx_log_info('Combination (variation features only): ' . implode(', ', $feature_names) . ' (key: ' . $combo_key . ')');
                    
                    // Дополнительная диагностика
                    if ($current_age === 'new' && $existing_age === 'existing') {
                        fn_mwl_xlsx_log_info('→ New product conflicts with existing - duplicate removal should have handled this');
                    } else {
                        fn_mwl_xlsx_log_info('→ This should NOT happen! Check why feature values not updated correctly');
                    }
                } else {
                    $combinations[$combo_key] = $pid;
                }
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
    // Flush remaining skipped product timestamps
    fn_mwl_exim_flush_skipped_timestamps();

    $profiler = MwlImportProfiler::instance();
    if ($profiler->isEnabled()) {
        $profiler->endProduct();
        $profiler->stepStart('post_processing');
    }

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
    
    // Fix SEO names for default products in variation groups
    // This is needed because during product creation, SEO names are assigned before
    // products are added to variation_group_products table, so the create_seo_name_pre
    // hook can't detect default products
    // IMPORTANT: Call this BEFORE the early return, so it runs even when there are no groups_to_fix
    fn_mwl_xlsx_fix_seo_names_after_import($import_data);
    
    if (empty($groups_to_fix)) {
        fn_mwl_xlsx_log_debug('No groups to fix found, skipping post-processing');
        fn_mwl_xlsx_log_debug('========================================');
        if ($profiler->isEnabled()) {
            $profiler->stepEnd('post_processing');
            $profiler->writeReport();
        }
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
                    
                    fn_mwl_xlsx_log_info('  * Feature #' . $new_fid . ' (' . ($feature_name ?: 'unknown') . '): will update to variant_id=' . $variant_id . ' (' . ($variant_name ?: 'unknown') . ')');
                    
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
                    fn_mwl_xlsx_log_info('  → Updated/Inserted ' . $total_updated . ' rows in DB (' . count($lang_codes) . ' languages)');
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

    if ($profiler->isEnabled()) {
        $profiler->stepEnd('post_processing');
        $profiler->writeReport();
    }
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
 * @return array{added: array<``int>, removed: array<int>, errors: array<string>, warnings: array<string>} Результат синхронизации
 */

/**
 * Fixes SEO names for default products in variation groups after import
 * 
 * During product creation, SEO names are assigned before products are added to
 * variation_group_products table, so the create_seo_name_pre hook can't detect
 * default products. This function fixes SEO names after import is complete.
 * 
 * @param array $import_data Import data from import_post hook
 */
function fn_mwl_xlsx_fix_seo_names_after_import($import_data)
{
    $is_import = Registry::get('runtime.advanced_import.in_progress');
    
    // Get all unique variation groups that were imported
    $group_codes = [];
    foreach ($import_data as $idx => $import_row) {
        $row = reset($import_row);
        $group_code = isset($row['variation_group_code']) ? $row['variation_group_code'] : (isset($row['Variation group code']) ? $row['Variation group code'] : null);
        
        if ($group_code) {
            $group_codes[$group_code] = true;
        }
    }
    
    if (empty($group_codes)) {
        return;
    }
    
    // Process each variation group
    foreach (array_keys($group_codes) as $group_code) {
        // Get group ID
        $group_id = db_get_field(
            "SELECT id FROM ?:product_variation_groups WHERE code = ?s",
            $group_code
        );
        
        if (!$group_id) {
            continue;
        }
        
        // Get default product for this group (parent_product_id = 0)
        // Use DESC to get the newest product (most recently created)
        $default_product = db_get_row(
            "SELECT product_id, parent_product_id 
             FROM ?:product_variation_group_products 
             WHERE group_id = ?i AND (parent_product_id IS NULL OR parent_product_id = 0)
             ORDER BY product_id DESC
             LIMIT 1",
            $group_id
        );
        
        if (empty($default_product)) {
            continue;
        }
        
        $default_product_id = $default_product['product_id'];
        
        // Expected SEO name is the variation group code
        $expected_seo_name = $group_code;
        
        // Get current SEO name
        $current_seo_name = db_get_field(
            "SELECT name FROM ?:seo_names WHERE object_id = ?i AND type = 'p' AND lang_code = 'en'",
            $default_product_id
        );
        
        if ($current_seo_name === $expected_seo_name) {
            // Already correct
            continue;
        }
        
        // Check if expected SEO name is occupied
        $occupied_by = db_get_row(
            "SELECT object_id, name 
             FROM ?:seo_names 
             WHERE name = ?s 
               AND object_id != ?i 
               AND type = 'p' 
               AND lang_code = 'en'",
            $expected_seo_name,
            $default_product_id
        );
        
        if (!empty($occupied_by)) {
            $occupying_product_id = $occupied_by['object_id'];
            
            // Check if occupying product is from the same variation group
            $occupying_variation_info = db_get_row(
                "SELECT group_id 
                 FROM ?:product_variation_group_products 
                 WHERE product_id = ?i",
                $occupying_product_id
            );
            
            if (!empty($occupying_variation_info) && $occupying_variation_info['group_id'] == $group_id) {
                // Free the URL from occupying product
                $occupying_product_name = db_get_field(
                    "SELECT product 
                     FROM ?:product_descriptions 
                     WHERE product_id = ?i AND lang_code = 'en'",
                    $occupying_product_id
                );
                
                if (empty($occupying_product_name)) {
                    $occupying_product_name = db_get_field(
                        "SELECT product_code FROM ?:products WHERE product_id = ?i",
                        $occupying_product_id
                    );
                    
                    if (empty($occupying_product_name)) {
                        $occupying_product_name = 'product-' . $occupying_product_id;
                    }
                }
                
                // Delete old SEO name
                db_query(
                    "DELETE FROM ?:seo_names 
                     WHERE object_id = ?i 
                       AND type = 'p' 
                       AND name = ?s 
                       AND lang_code = 'en'",
                    $occupying_product_id,
                    $expected_seo_name
                );
                
                // Generate new SEO name for occupying product
                $new_base_name = $occupying_product_name . '-variant';
                $new_seo_name = fn_create_seo_name(
                    $occupying_product_id,
                    'p',
                    $new_base_name,
                    0,
                    '',
                    0,
                    'en',
                    false,
                    'C',
                    [],
                    false,
                    ''
                );
                
                if ($is_import) {
                    fn_mwl_xlsx_log_info("Post-import SEO fix: Freed URL '{$expected_seo_name}' from product #{$occupying_product_id} " .
                         "(same variation group #{$group_id}), assigned new URL '{$new_seo_name}'");
                }
            }
        }
        
        // Update default product SEO name
        if ($current_seo_name !== $expected_seo_name) {
            // Delete current SEO name
            db_query(
                "DELETE FROM ?:seo_names 
                 WHERE object_id = ?i 
                   AND type = 'p' 
                   AND lang_code = 'en'",
                $default_product_id
            );
            
            // Create new SEO name using the group code directly
            $new_seo_name = fn_create_seo_name(
                $default_product_id,
                'p',
                $expected_seo_name,
                0,
                '',
                0,
                'en',
                false,
                'C',
                [],
                false,
                ''
            );
            
            if ($is_import) {
                fn_mwl_xlsx_log_info("Post-import SEO fix: Updated SEO name for default product #{$default_product_id} " .
                     "from '{$current_seo_name}' to '{$new_seo_name}' (group #{$group_id}, expected: '{$expected_seo_name}')");
            }
        }
    }
}

/**
 * Get full product variation name with feature names
 * Format: "Product Name (Feature1: Variant1, Feature2: Variant2, ...)"
 *
 * @param int $product_id Product ID
 * @param string $lang_code Language code (optional, defaults to CART_LANGUAGE)
 * @return string Full variation name or base product name if no variations
 */
function fn_mwl_xlsx_get_product_variations_name_full($product_id, $lang_code = CART_LANGUAGE)
{
    // Check if product_variations addon is available and active
    if (!fn_check_addon_exists('product_variations')) {
        return fn_get_product_name($product_id, $lang_code);
    }

    // Check if addon is active
    $addon_status = db_get_field('SELECT status FROM ?:addons WHERE addon = ?s', 'product_variations');
    if ($addon_status !== 'A') {
        return fn_get_product_name($product_id, $lang_code);
    }

    try {
        $product_repository = \Tygh\Addons\ProductVariations\ServiceProvider::getProductRepository();
        $product_repository->setLangCode($lang_code);

        // Find product
        $product = $product_repository->findProduct($product_id);
        if (empty($product)) {
            return fn_get_product_name($product_id, $lang_code);
        }

        // Load group info and features
        $product = $product_repository->loadProductGroupInfo($product);
        $product = $product_repository->loadProductFeatures($product);

        // If no variation features, return base product name
        if (empty($product['variation_features'])) {
            return $product['product'];
        }

        // Build variation name with feature_name: variant_name format
        $formatted_variants = [];

        foreach ($product['variation_features'] as $feature) {
            // Only include features with purpose 'group_variation_catalog_item'
            // This matches the logic in product_variations addon's loadProductsVariationName
            if (\Tygh\Addons\ProductVariations\Product\FeaturePurposes::isCreateVariationOfCatalogItem($feature['purpose'])) {
                $feature_name = !empty($feature['description']) ? $feature['description'] : (!empty($feature['internal_name']) ? $feature['internal_name'] : '');
                $variant_name = !empty($feature['variant']) ? $feature['variant'] : '';

                if (!empty($feature_name) && !empty($variant_name)) {
                    $formatted_variants[] = $feature_name . ': ' . $variant_name;
                } elseif (!empty($variant_name)) {
                    // Fallback: if no feature name, just use variant name
                    $formatted_variants[] = $variant_name;
                }
            }
        }

        if (!empty($formatted_variants)) {
            return $product['product'] . ' (' . implode(', ', $formatted_variants) . ')';
        }

        return $product['product'];

    } catch (\Exception $e) {
        // Fallback to base product name if any error occurs
        return fn_get_product_name($product_id, $lang_code);
    }
}

/**
 * Resolve a relative URL against a base URL.
 *
 * @param string $relative Relative or absolute URL
 * @param string $base_url Base URL to resolve against
 * @return string Resolved absolute URL
 */
function fn_mwl_xlsx_resolve_url($relative, $base_url)
{
    // Already absolute
    if (preg_match('~^(https?://|//)~i', $relative)) {
        return $relative;
    }

    $base = parse_url($base_url);
    $scheme = !empty($base['scheme']) ? $base['scheme'] : 'https';
    $host = !empty($base['host']) ? $base['host'] : '';
    $base_path = !empty($base['path']) ? $base['path'] : '/';

    if (!$host) {
        return $relative;
    }

    // Absolute path
    if (strpos($relative, '/') === 0) {
        return $scheme . '://' . $host . $relative;
    }

    // Relative path — resolve against base directory
    $dir = rtrim(dirname($base_path), '/') . '/';
    $path = $dir . $relative;

    // Normalize . and ..
    $parts = [];
    foreach (explode('/', $path) as $seg) {
        if ($seg === '..') {
            array_pop($parts);
        } elseif ($seg !== '' && $seg !== '.') {
            $parts[] = $seg;
        }
    }

    return $scheme . '://' . $host . '/' . implode('/', $parts);
}

/**
 * Get the mainpage replace storage directory path.
 *
 * @param string $lang_code Language code (e.g. 'en', 'ru'). If provided, returns per-language subdirectory.
 * @return string Absolute directory path with trailing slash
 */
function fn_mwl_xlsx_mainpage_replace_dir($lang_code = '')
{
    $dir = Registry::get('config.dir.root') . '/files/mainpage_replace/';
    return $lang_code ? $dir . $lang_code . '/' : $dir;
}

/**
 * Proxy /-/x-api/ requests to the mainpage source domain.
 * This avoids CORS issues since the browser sees a same-origin request.
 */
function fn_mwl_xlsx_proxy_api_request($path)
{
    $lang = defined('CART_LANGUAGE') ? CART_LANGUAGE : 'en';
    $url = trim((string) Registry::get('addons.mwl_xlsx.mainpage_replace_url_' . $lang));
    if (empty($url)) {
        header('HTTP/1.1 502 Bad Gateway');
        exit;
    }

    $base = parse_url($url);
    $origin = (!empty($base['scheme']) ? $base['scheme'] : 'https') . '://' . $base['host'];
    $query = $_SERVER['QUERY_STRING'] ?? '';
    $target = $origin . $path . ($query ? '?' . $query : '');

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $body = ($method === 'POST') ? file_get_contents('php://input') : '';
    $content_type = $_SERVER['CONTENT_TYPE'] ?? 'application/x-www-form-urlencoded';

    $ch = curl_init($target);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: ' . $content_type,
            'Referer: ' . $origin . '/',
        ],
    ]);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $response_ct = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    header('HTTP/1.1 ' . $http_code);
    if ($response_ct) {
        header('Content-Type: ' . $response_ct);
    }
    echo $response;
    exit;
}

/**
 * Get the web-accessible path prefix for mainpage replace assets.
 *
 * @param string $lang_code Language code (e.g. 'en', 'ru'). If provided, returns per-language subpath.
 * @return string Path like "/files/mainpage_replace/" or "/files/mainpage_replace/en/"
 */
function fn_mwl_xlsx_mainpage_replace_web_path($lang_code = '')
{
    $path = '/files/mainpage_replace/';
    return $lang_code ? $path . $lang_code . '/' : $path;
}

/**
 * Download a page from URL and save it with relative assets.
 *
 * @param string $url The URL to download
 * @param string $lang_code Language code for per-language storage (e.g. 'en', 'ru')
 * @return array{success: bool, message: string}
 */
function fn_mwl_xlsx_download_mainpage($url, $lang_code = '', $fast = false)
{
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return ['success' => false, 'message' => 'Invalid URL'];
    }

    $dir = fn_mwl_xlsx_mainpage_replace_dir($lang_code);
    fn_mkdir($dir);

    // Download the HTML (always re-download)
    $html = Http::get($url);
    if (empty($html)) {
        return ['success' => false, 'message' => 'Failed to download page'];
    }

    // Parse HTML for relative resources
    $assets = fn_mwl_xlsx_extract_assets($html, $url);

    // Download assets
    $downloaded = 0;
    $skipped = 0;
    $css_sub_assets = 0;
    $webpack_chunks = 0;
    $base = parse_url($url);
    $base_origin = (!empty($base['scheme']) ? $base['scheme'] : 'https') . '://' . $base['host'];
    $base_host = $base['host'];
    $web_path = fn_mwl_xlsx_mainpage_replace_web_path($lang_code);

    foreach ($assets as $asset_url => $local_path) {
        $asset_dir = dirname($dir . $local_path);
        fn_mkdir($asset_dir);

        // In fast mode, skip static files that already exist (always re-download CSS/JS for processing)
        $ext = strtolower(pathinfo($local_path, PATHINFO_EXTENSION));
        if ($fast && file_exists($dir . $local_path) && !in_array($ext, ['css', 'js'])) {
            $skipped++;
            continue;
        }

        $content = Http::get($asset_url, [], ['execution_timeout' => 10]);
        if (!empty($content)) {
            // For CSS files, download url() referenced assets and rewrite paths
            if ($ext === 'css') {
                $before = $downloaded;
                $content = fn_mwl_xlsx_process_css_assets($content, $asset_url, $base_host, $base_origin, $dir, $web_path, $downloaded, $fast);
                $css_sub_assets += $downloaded - $before;
            }

            // For JS files, download webpack chunks and rewrite chunk paths
            if ($ext === 'js') {
                $before = $downloaded;
                fn_mwl_xlsx_download_webpack_chunks($content, $asset_url, $base_origin, $dir, $downloaded);
                $webpack_chunks += $downloaded - $before;
                $content = fn_mwl_xlsx_rewrite_js_chunk_paths($content, $web_path);
            }

            file_put_contents($dir . $local_path, $content);
            $downloaded++;
        }
    }

    // Save original HTML
    file_put_contents($dir . 'index.html', $html);

    $msg = "Downloaded page + $downloaded assets (CSS sub-assets: $css_sub_assets, webpack chunks: $webpack_chunks, skipped: $skipped)";

    return ['success' => true, 'message' => $msg];
}

/**
 * Extract relative asset URLs from HTML.
 *
 * @param string $html HTML content
 * @param string $base_url Base URL for resolving relative paths
 * @return array Map of absolute_url => local_relative_path
 */
function fn_mwl_xlsx_extract_assets($html, $base_url)
{
    $assets = [];

    libxml_use_internal_errors(true);
    $doc = new \DOMDocument();
    $doc->loadHTML($html, LIBXML_NOWARNING | LIBXML_NOERROR);
    libxml_clear_errors();

    $base = parse_url($base_url);
    $base_host = !empty($base['host']) ? $base['host'] : '';

    // <link href>, <script src>, <img src>, <source src>, <video src>
    $tag_attrs = [
        'link' => 'href',
        'script' => 'src',
        'img' => 'src',
        'source' => 'src',
        'video' => 'src',
    ];

    foreach ($tag_attrs as $tag => $attr) {
        $elements = $doc->getElementsByTagName($tag);
        foreach ($elements as $el) {
            $val = $el->getAttribute($attr);
            if (empty($val)) {
                continue;
            }
            fn_mwl_xlsx_collect_asset($val, $base_url, $base_host, $assets);
        }
    }

    // srcset attributes (img, source)
    foreach (['img', 'source'] as $tag) {
        $elements = $doc->getElementsByTagName($tag);
        foreach ($elements as $el) {
            $srcset = $el->getAttribute('srcset');
            if (empty($srcset)) {
                continue;
            }
            // srcset: "url1 1x, url2 2x" or "url1 300w, url2 600w"
            foreach (explode(',', $srcset) as $part) {
                $part = trim($part);
                $src = preg_split('/\s+/', $part)[0] ?? '';
                if (!empty($src)) {
                    fn_mwl_xlsx_collect_asset($src, $base_url, $base_host, $assets);
                }
            }
        }
    }

    // Inline <style> url() references
    $style_elements = $doc->getElementsByTagName('style');
    foreach ($style_elements as $el) {
        $css = $el->textContent;
        if (preg_match_all('~url\(\s*[\'"]?(?!data:|https?://|//)([^\'")\s]+)[\'"]?\s*\)~', $css, $m)) {
            foreach ($m[1] as $ref) {
                fn_mwl_xlsx_collect_asset($ref, $base_url, $base_host, $assets);
            }
        }
    }

    return $assets;
}

/**
 * Add a URL to the assets map if it's a same-host or relative resource.
 */
function fn_mwl_xlsx_collect_asset($val, $base_url, $base_host, &$assets)
{
    // Skip data URIs and protocol-relative to other hosts
    if (strpos($val, 'data:') === 0) {
        return;
    }

    // Resolve the URL
    $absolute = fn_mwl_xlsx_resolve_url($val, $base_url);

    // Only download same-host resources
    $parsed = parse_url($absolute);
    $host = !empty($parsed['host']) ? $parsed['host'] : '';
    if ($host && $host !== $base_host) {
        return;
    }

    // Derive local path from URL path
    $path = !empty($parsed['path']) ? ltrim($parsed['path'], '/') : '';
    if (empty($path) || $path === '/' || substr($path, -1) === '/') {
        return;
    }

    // Skip HTML pages (no extension or .html/.htm)
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if (empty($ext) || in_array($ext, ['html', 'htm', 'php'])) {
        return;
    }

    $assets[$absolute] = $path;
}

/**
 * Download webpack dynamic chunks referenced in a JS file.
 * Parses patterns like: "path/prefix/do." + {0:"tt_form", 1:"tt_slider", ...}[t] + ".js"
 *
 * @param string $js_content JS file content
 * @param string $js_url Absolute URL of the JS file
 * @param string $base_origin Scheme + host
 * @param string $dir Local directory to save assets to
 * @param int &$downloaded Counter
 */
function fn_mwl_xlsx_download_webpack_chunks($js_content, $js_url, $base_origin, $dir, &$downloaded)
{
    // Match webpack chunk mapping: "some/path/prefix." + {0:"name1", 1:"name2", ...}
    if (!preg_match('~"([^"]+)"\s*\+\s*\{(\d+:"[^"]+?"(?:,\d+:"[^"]+?")*)\}~', $js_content, $m)) {
        return;
    }

    $path_prefix = $m[1]; // e.g. "g/s3/mosaic/js/do/redesign/do."
    $chunk_map_str = $m[2]; // e.g. '0:"tt_form",1:"tt_slider",...'

    // Parse chunk names
    preg_match_all('~\d+:"([^"]+)"~', $chunk_map_str, $names);
    if (empty($names[1])) {
        return;
    }

    foreach ($names[1] as $chunk_name) {
        $chunk_path = $path_prefix . $chunk_name . '.js';
        $chunk_url = $base_origin . '/' . $chunk_path;

        $local_file = $dir . $chunk_path;
        if (file_exists($local_file)) {
            continue;
        }

        $chunk_dir = dirname($local_file);
        fn_mkdir($chunk_dir);

        $content = Http::get($chunk_url, [], ['execution_timeout' => 10]);
        if (!empty($content)) {
            file_put_contents($local_file, $content);
            $downloaded++;
        }
    }
}

/**
 * Rewrite webpack chunk paths in a JS file to point to local copies.
 *
 * @param string $js_content JS content
 * @param string $web_path Local web path prefix (e.g. "/var/files/mainpage_replace/en/")
 * @return string JS with rewritten chunk paths
 */
function fn_mwl_xlsx_rewrite_js_chunk_paths($js_content, $web_path)
{
    // Set webpack publicPath so chunks load from the local directory
    // s.p="" => s.p="/var/files/mainpage_replace/en/"
    $js_content = str_replace('s.p=""', 's.p="' . $web_path . '"', $js_content);
    // Disable runtime publicPath override (module 7772 sets r.p="/" on non-localhost)
    $js_content = str_replace(':r.p="/"', ':0', $js_content);
    $js_content = str_replace(':r.p="./"', ':0', $js_content);
    return $js_content;
}

/**
 * Download assets referenced via url() in a CSS file and rewrite paths to local copies.
 *
 * @param string $css CSS content
 * @param string $css_url Absolute URL of the CSS file (for resolving relative refs)
 * @param string $base_host Host to match for same-host check
 * @param string $base_origin Scheme + host (e.g. "https://welcome.pressfinity.com")
 * @param string $dir Local directory to save assets to
 * @param string $web_path Web-accessible path prefix (e.g. "/var/files/mainpage_replace/en/")
 * @param int &$downloaded Counter of downloaded assets
 * @return string CSS with rewritten url() paths
 */
function fn_mwl_xlsx_process_css_assets($css, $css_url, $base_host, $base_origin, $dir, $web_path, &$downloaded, $fast = false)
{
    // Match url() references, skip data: URIs
    if (!preg_match_all('~url\(\s*[\'"]?(?!data:)([^\'")\s]+)[\'"]?\s*\)~', $css, $matches)) {
        return $css;
    }

    $replacements = [];
    foreach ($matches[1] as $ref) {
        // Resolve to absolute URL
        $absolute = fn_mwl_xlsx_resolve_url($ref, $css_url);
        $parsed = parse_url($absolute);
        $host = !empty($parsed['host']) ? $parsed['host'] : '';

        // Only same-host resources
        if ($host && $host !== $base_host) {
            continue;
        }

        $path = !empty($parsed['path']) ? ltrim($parsed['path'], '/') : '';
        if (empty($path) || substr($path, -1) === '/') {
            continue;
        }

        // Download the asset (skip if already exists)
        $local = $web_path . $path;
        $replacements[$ref] = $local;

        if (file_exists($dir . $path)) {
            continue;
        }

        $asset_dir = dirname($dir . $path);
        fn_mkdir($asset_dir);
        $content = Http::get($absolute, [], ['timeout' => 10]);
        if (!empty($content)) {
            file_put_contents($dir . $path, $content);
            $downloaded++;
        }
    }

    // Apply replacements in CSS (longest first)
    uksort($replacements, function ($a, $b) {
        return strlen($b) - strlen($a);
    });

    foreach ($replacements as $original => $local) {
        $css = str_replace($original, $local, $css);
    }

    return $css;
}

/**
 * Rewrite asset paths in HTML to point to local files at runtime.
 *
 * @param string $html Original HTML
 * @param string $base_url Source URL the page was downloaded from
 * @param string $lang_code Language code for per-language asset paths
 * @return string HTML with paths rewritten to /var/files/mainpage_replace/{lang}/
 */
function fn_mwl_xlsx_rewrite_mainpage_paths($html, $base_url, $lang_code = '')
{
    $base = parse_url($base_url);
    $base_host = !empty($base['host']) ? $base['host'] : '';
    $scheme = !empty($base['scheme']) ? $base['scheme'] : 'https';
    $web_path = fn_mwl_xlsx_mainpage_replace_web_path($lang_code);

    // Collect all assets to build replacement map
    $assets = fn_mwl_xlsx_extract_assets($html, $base_url);

    // Build replacement map: original_attr_value => local_path
    $replacements = [];
    foreach ($assets as $absolute_url => $local_path) {
        $local = $web_path . $local_path;

        // Add the absolute URL form
        $replacements[$absolute_url] = $local;

        // Also add common variants the HTML might use
        $parsed = parse_url($absolute_url);
        $url_path = !empty($parsed['path']) ? $parsed['path'] : '';

        // Absolute path form: /path/to/file
        if ($url_path && !isset($replacements[$url_path])) {
            $replacements[$url_path] = $local;
        }

        // Protocol-relative form: //host/path
        if ($base_host && $url_path) {
            $proto_relative = '//' . $base_host . $url_path;
            if (!isset($replacements[$proto_relative])) {
                $replacements[$proto_relative] = $local;
            }
        }

        // Full URL with scheme
        if ($url_path) {
            $full = $scheme . '://' . $base_host . $url_path;
            if (!isset($replacements[$full])) {
                $replacements[$full] = $local;
            }
        }
    }

    // Sort by key length descending to avoid partial replacements
    uksort($replacements, function ($a, $b) {
        return strlen($b) - strlen($a);
    });

    // Apply replacements
    if (!empty($replacements)) {
        $html = str_replace(array_keys($replacements), array_values($replacements), $html);
    }

    return $html;
}
