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
use Google\Service\Sheets;
use Google\Service\Sheets\ValueRange;
use Google\Service\Sheets\BatchUpdateSpreadsheetRequest;

if (!defined('BOOTSTRAP')) { die('Access denied'); }

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
function fn_mwl_xlsx_read_filters_csv(string $path): array
{
    $rows = [];
    $errors = [];

    if (!file_exists($path)) {
        return [
            'rows' => $rows,
            'errors' => [__(
                'mwl_xlsx.filters_sync_error_not_found',
                ['[path]' => $path]
            )],
        ];
    }

    if (!is_readable($path)) {
        return [
            'rows' => $rows,
            'errors' => [__(
                'mwl_xlsx.filters_sync_error_not_readable',
                ['[path]' => $path]
            )],
        ];
    }

    $handle = fopen($path, 'rb');

    if ($handle === false) {
        return [
            'rows' => $rows,
            'errors' => [__(
                'mwl_xlsx.filters_sync_error_open_failed',
                ['[path]' => $path]
            )],
        ];
    }

    $first_line = fgets($handle);

    if ($first_line === false) {
        fclose($handle);

        return [
            'rows' => $rows,
            'errors' => [__('mwl_xlsx.filters_sync_error_empty')],
        ];
    }

    $delimiter = fn_mwl_xlsx_detect_csv_delimiter($first_line);
    rewind($handle);

    $header = fgetcsv($handle, 0, $delimiter);

    if ($header === false) {
        fclose($handle);

        return [
            'rows' => $rows,
            'errors' => [__('mwl_xlsx.filters_sync_error_header')],
        ];
    }

    $normalized_header = [];

    foreach ($header as $index => $column) {
        $normalized_header[$index] = fn_mwl_xlsx_normalize_csv_header_value((string) $column, $index === 0);
    }

    $header_map = array_flip($normalized_header);
    $name_column = null;

    if (isset($header_map['name'])) {
        $name_column = 'name';
    } elseif (isset($header_map['filter'])) {
        $name_column = 'filter';
    }

    if ($name_column === null) {
        fclose($handle);

        return [
            'rows' => [],
            'errors' => [__(
                'mwl_xlsx.filters_sync_error_missing_column',
                ['[column]' => 'name']
            )],
        ];
    }

    $required_columns = ['position', 'round_to', 'display'];

    if (!isset($header_map['name_ru'])) {
        fclose($handle);

        return [
            'rows' => [],
            'errors' => [__(
                'mwl_xlsx.filters_sync_error_missing_column',
                ['[column]' => 'name_ru']
            )],
        ];
    }

    if (!isset($header_map['feature_id'])) {
        fclose($handle);

        return [
            'rows' => [],
            'errors' => [__(
                'mwl_xlsx.filters_sync_error_missing_column',
                ['[column]' => 'feature_id']
            )],
        ];
    }

    $required_columns[] = $name_column;
    $required_columns[] = 'name_ru';
    $required_columns[] = 'feature_id';

    foreach ($required_columns as $required) {
        if (!in_array($required, $normalized_header, true)) {
            fclose($handle);

            return [
                'rows' => [],
                'errors' => [__(
                    'mwl_xlsx.filters_sync_error_missing_column',
                    ['[column]' => $required]
                )],
            ];
        }
    }

    while (($data = fgetcsv($handle, 0, $delimiter)) !== false) {
        if (count(array_filter($data, static function ($value) {
            return $value !== null && $value !== '';
        })) === 0) {
            continue;
        }

        $row = [];

        foreach ($normalized_header as $index => $column) {
            $row[$column] = $data[$index] ?? null;
        }

        if ($name_column === 'filter' && isset($row['filter']) && !isset($row['name'])) {
            $row['name'] = $row['filter'];
        }

        if (isset($row['filter_ru']) && !isset($row['name_ru'])) {
            $row['name_ru'] = $row['filter_ru'];
        }

        $rows[] = $row;
    }

    fclose($handle);

    return [
        'rows' => $rows,
        'errors' => $errors,
    ];
}

function fn_mwl_xlsx_publish_down_service(): ProductPublishDownService
{
    static $service;

    if ($service === null) {
        $service = new ProductPublishDownService(Tygh::$app['db']);
    }

    return $service;
}

function fn_mwl_xlsx_detect_csv_delimiter(string $line): string
{
    $comma_count = substr_count($line, ',');
    $semicolon_count = substr_count($line, ';');

    if ($semicolon_count > $comma_count) {
        return ';';
    }

    return ',';
}

function fn_mwl_xlsx_normalize_csv_header_value(string $column, bool $is_first_column = false): string
{
    if ($is_first_column && strncmp($column, "\xEF\xBB\xBF", 3) === 0) {
        $column = substr($column, 3);
    }

    $column = str_replace(["\xEF\xBB\xBF", "\u{FEFF}"], '', $column);

    return mb_strtolower(trim($column), 'UTF-8');
}

/**
 * Read products CSV file for publish_down_missing_products_csv mode.
 * Expected columns: "Variation group code", "Product code"
 *
 * @param string $path Path to CSV file
 * @return array{rows: array<array{variation_group_code: string, product_code: string}>, errors: array<string>}
 */
function fn_mwl_xlsx_read_products_csv(string $path): array
{
    $rows = [];
    $errors = [];

    if (!file_exists($path)) {
        return [
            'rows' => $rows,
            'errors' => ["File not found: {$path}"],
        ];
    }

    if (!is_readable($path)) {
        return [
            'rows' => $rows,
            'errors' => ["File not readable: {$path}"],
        ];
    }

    $handle = fopen($path, 'rb');

    if ($handle === false) {
        return [
            'rows' => $rows,
            'errors' => ["Failed to open file: {$path}"],
        ];
    }

    $first_line = fgets($handle);

    if ($first_line === false) {
        fclose($handle);

        return [
            'rows' => $rows,
            'errors' => ['CSV file is empty'],
        ];
    }

    $delimiter = fn_mwl_xlsx_detect_csv_delimiter($first_line);
    rewind($handle);

    $header = fgetcsv($handle, 0, $delimiter);

    if ($header === false) {
        fclose($handle);

        return [
            'rows' => $rows,
            'errors' => ['Failed to read CSV header'],
        ];
    }

    $normalized_header = [];

    foreach ($header as $index => $column) {
        $normalized_header[$index] = fn_mwl_xlsx_normalize_csv_header_value((string) $column, $index === 0);
    }

    $header_map = array_flip($normalized_header);

    // Check for required columns
    $required_columns = ['variation group code', 'product code'];
    $missing_columns = [];

    foreach ($required_columns as $required_column) {
        if (!isset($header_map[$required_column])) {
            $missing_columns[] = $required_column;
        }
    }

    if ($missing_columns) {
        fclose($handle);

        return [
            'rows' => [],
            'errors' => ['Missing required columns: ' . implode(', ', $missing_columns)],
        ];
    }

    $variation_group_code_index = $header_map['variation group code'];
    $product_code_index = $header_map['product code'];

    $line_number = 1;

    while (($data = fgetcsv($handle, 0, $delimiter)) !== false) {
        $line_number++;

        if (count($data) < max($variation_group_code_index, $product_code_index) + 1) {
            $errors[] = "Line {$line_number}: insufficient columns";
            continue;
        }

        $variation_group_code = trim((string) ($data[$variation_group_code_index] ?? ''));
        $product_code = trim((string) ($data[$product_code_index] ?? ''));

        if ($variation_group_code === '' || $product_code === '') {
            // Skip empty rows
            continue;
        }

        $rows[] = [
            'variation_group_code' => $variation_group_code,
            'product_code' => $product_code,
        ];
    }

    fclose($handle);

    return [
        'rows' => $rows,
        'errors' => $errors,
    ];
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
    echo '[MWL_XLSX] ========================================' . PHP_EOL;
    echo '[MWL_XLSX] Hook variation_group_add_products_to_group called (call #' . $current_call . ' for group #' . $group_id_key . ')' . PHP_EOL;
    echo '  - Products count: ' . count($products) . PHP_EOL;
    echo '  - Has group: ' . ($group ? 'yes' : 'no') . PHP_EOL;
    echo '  - Group ID: ' . ($group ? $group->getId() : 'null') . PHP_EOL;

    if (empty($products) || !$group || !$group->getId()) {
        echo '[MWL_XLSX] Hook skipped: empty products or invalid group' . PHP_EOL;
        return;
    }

    try {
        echo '[MWL_XLSX] Starting group features update check' . PHP_EOL;
        echo '  - Group ID: ' . $group->getId() . PHP_EOL;
        echo '  - Group code: ' . $group->getCode() . PHP_EOL;
        
        $current_feature_ids = $group->getFeatureIds();
        echo '  - Current feature IDs (from object): ' . (empty($current_feature_ids) ? 'EMPTY!' : implode(', ', $current_feature_ids)) . PHP_EOL;
        
        // ЗАЩИТА: Если объект группы пустой, загружаем features из БД
        if (empty($current_feature_ids)) {
            $db_features_check = db_get_array(
                "SELECT feature_id FROM ?:product_variation_group_features WHERE group_id = ?i",
                $group->getId()
            );
            if (!empty($db_features_check)) {
                echo '  ⚠ Group object has no features, but DB has: ' . implode(', ', array_column($db_features_check, 'feature_id')) . PHP_EOL;
                echo '  → Group object not fully loaded, reloading from DB...' . PHP_EOL;
                
                $fresh_group = $group_repository->findGroupById($group->getId());
                if ($fresh_group) {
                    $current_feature_ids = $fresh_group->getFeatureIds();
                    echo '  → Reloaded features: ' . implode(', ', $current_feature_ids) . PHP_EOL;
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
            echo '[MWL_XLSX] Hook skipped: no product IDs found' . PHP_EOL;
            return;
        }
        
        echo '[MWL_XLSX] Product IDs collected' . PHP_EOL;
        echo '  - New product IDs: ' . implode(', ', $new_product_ids) . PHP_EOL;
        echo '  - Existing product IDs: ' . implode(', ', $existing_product_ids) . PHP_EOL;
        echo '  - Total products: ' . count($all_product_ids) . PHP_EOL;
        
        // Debug: проверяем ВСЕ продукты в группе из БД с кодами
        $all_group_products_db = db_get_array(
            "SELECT gp.product_id, gp.parent_product_id, p.product_code " .
            "FROM ?:product_variation_group_products gp " .
            "LEFT JOIN ?:products p ON gp.product_id = p.product_id " .
            "WHERE gp.group_id = ?i",
            $group->getId()
        );
        echo '[MWL_XLSX] Products in DB for group #' . $group->getId() . ':' . PHP_EOL;
        foreach ($all_group_products_db as $gp) {
            echo '  - Product #' . $gp['product_id'] . ' [' . $gp['product_code'] . '] (parent: ' . ($gp['parent_product_id'] ?: 'none') . ')' . PHP_EOL;
        }
        
        // КРИТИЧЕСКИ ВАЖНО: Собираем features со ВСЕХ новых продуктов, а не только базового
        // Это гарантирует что новые features из импорта будут добавлены в группу
        echo '[MWL_XLSX] Collecting available features from ALL new products...' . PHP_EOL;
        $available_features = [];
        
        foreach ($new_product_ids as $check_product_id) {
            $product_features = $product_repository->findAvailableFeatures($check_product_id);
            echo '  - Product #' . $check_product_id . ' has ' . count($product_features) . ' variation features' . PHP_EOL;
            
            // Объединяем features (избегаем дубликатов через array_key merge)
            foreach ($product_features as $feature_id => $feature) {
                if (!isset($available_features[$feature_id])) {
                    $available_features[$feature_id] = $feature;
                    echo '    * Added feature #' . $feature_id . ': ' . ($feature['description'] ?? 'unknown') . PHP_EOL;
                }
            }
        }
        
        // Если новых продуктов нет или нет новых features, проверяем существующие
        if (empty($available_features)) {
            echo '[MWL_XLSX] No features from new products, checking existing...' . PHP_EOL;
            $base_product_id = reset($all_product_ids);
            $available_features = $product_repository->findAvailableFeatures($base_product_id);
            echo '  - Base product ID: ' . $base_product_id . PHP_EOL;
        }
        
        echo '[MWL_XLSX] Available features found: ' . count($available_features) . PHP_EOL;
        foreach ($available_features as $feature) {
            $name = $feature['description'] ?? $feature['internal_name'] ?? 'unknown';
            $purpose = $feature['purpose'] ?? 'unknown';
            echo '  - Feature #' . $feature['feature_id'] . ': ' . $name . ' (purpose: ' . $purpose . ')' . PHP_EOL;
        }
        
        // Debug: проверяем feature values ИМПОРТИРУЕМЫХ продуктов
        echo '[MWL_XLSX] Checking feature values of new products...' . PHP_EOL;
        foreach ($new_product_ids as $pid) {
            if (isset($products[$pid]['variation_features'])) {
                echo '  - Product #' . $pid . ' features from import data:' . PHP_EOL;
                foreach ($products[$pid]['variation_features'] as $fid => $feature) {
                    $variant = $feature['variant'] ?? 'null';
                    echo '    * Feature #' . $fid . ': ' . $variant . PHP_EOL;
                }
            } else {
                echo '  - Product #' . $pid . ': NO variation_features in import data' . PHP_EOL;
            }
            
            // Проверяем feature values из БД для этого продукта
            $db_features = db_get_array(
                "SELECT feature_id, variant_id, value_int " .
                "FROM ?:product_features_values " .
                "WHERE product_id = ?i AND lang_code = 'en' AND feature_id IN (?a)",
                $pid, array_keys($available_features)
            );
            
            if ($db_features) {
                echo '  - Product #' . $pid . ' features from DB:' . PHP_EOL;
                foreach ($db_features as $db_feat) {
                    echo '    * Feature #' . $db_feat['feature_id'] . ': variant_id=' . $db_feat['variant_id'] . ', value_int=' . $db_feat['value_int'] . PHP_EOL;
                }
            } else {
                echo '  - Product #' . $pid . ': NO features in DB!' . PHP_EOL;
            }
        }
        
        if (empty($available_features)) {
            echo '[MWL_XLSX] Hook skipped: no available features for product #' . $base_product_id . PHP_EOL;
            return;
        }
        
        // Получаем новые feature IDs
        $new_feature_ids = array_keys($available_features);
        
        // Проверяем, есть ли новые или удаленные features
        $added_feature_ids = array_diff($new_feature_ids, $current_feature_ids);
        $removed_feature_ids = array_diff($current_feature_ids, $new_feature_ids);
        
        echo '[MWL_XLSX] Features comparison:' . PHP_EOL;
        echo '  - Current features: ' . implode(', ', $current_feature_ids) . PHP_EOL;
        echo '  - Available features: ' . implode(', ', $new_feature_ids) . PHP_EOL;
        echo '  - Features to add: ' . (empty($added_feature_ids) ? 'none' : implode(', ', $added_feature_ids)) . PHP_EOL;
        echo '  - Features to remove: ' . (empty($removed_feature_ids) ? 'none' : implode(', ', $removed_feature_ids)) . PHP_EOL;
        
        // КРИТИЧЕСКОЕ РЕШЕНИЕ: Обновляем features только при УДАЛЕНИИ, не при добавлении
        // Причина: CS-Cart фильтрует variation features при сохранении через ProductsHookHandler
        // Когда мы добавляем новую feature, CS-Cart не сохраняет её values → ошибка "required features"
        if (!empty($added_feature_ids)) {
            echo '[MWL_XLSX] ⚠ New features detected: ' . implode(', ', $added_feature_ids) . PHP_EOL;
            echo '  → Auto-adding features DISABLED due to CS-Cart import filtering' . PHP_EOL;
            echo '  → Will fix feature values in import_post hook' . PHP_EOL;
            echo '  → New features: ' . implode(', ', array_map(function($fid) use ($available_features) {
                $name = isset($available_features[$fid]['description']) ? $available_features[$fid]['description'] : 'Feature #' . $fid;
                return $name . ' (ID: ' . $fid . ')';
            }, $added_feature_ids)) . PHP_EOL;
            
            // Сохраняем в Registry для обработки в import_post
            \Tygh\Registry::set('mwl_xlsx.group_' . $group_id_key . '_new_feature_ids', $added_feature_ids);
            echo '  → Saved to Registry for post-processing' . PHP_EOL;
        }
        
        // Обновляем features только при удалении (безопасно)
        $has_feature_changes = !empty($removed_feature_ids);
        
        // Проверяем текущее состояние features в БД
        $current_db_features = db_get_array(
            "SELECT feature_id FROM ?:product_variation_group_features WHERE group_id = ?i ORDER BY feature_id",
            $group->getId()
        );
        $current_db_feature_ids = empty($current_db_features) ? [] : array_column($current_db_features, 'feature_id');
        
        echo '[MWL_XLSX] Current features in DB: ' . (empty($current_db_features) ? 'none (table is EMPTY!)' : implode(', ', $current_db_feature_ids)) . PHP_EOL;
        
        // ВАЖНО: Проверяем features которые в БД но НЕ были в объекте группы
        // Это значит feature была добавлена вручную ПЕРЕД импортом
        $manually_added_features = array_diff($current_db_feature_ids, $current_feature_ids);
        if (!empty($manually_added_features)) {
            echo '[MWL_XLSX] Features added manually before import: ' . implode(', ', $manually_added_features) . PHP_EOL;
            echo '  → Will fix their values in import_post hook' . PHP_EOL;
            
            // Сохраняем в статическое хранилище для import_post
            fn_mwl_xlsx_set_groups_to_fix($group_id_key, $manually_added_features, false);
            
            // Сохраняем product_features для manually added features тоже
            $products_features_map = [];
            foreach ($new_product_ids as $pid) {
                if (isset($products[$pid]['variation_features'])) {
                    $products_features_map[$pid] = $products[$pid]['variation_features'];
                }
            }
            fn_mwl_xlsx_set_products_features($group_id_key, $products_features_map);
            
            echo '  → Saved to static storage for post-processing' . PHP_EOL;
        }
        
        // ДОПОЛНИТЕЛЬНО: Если это ОБНОВЛЕНИЕ существующих продуктов (а не создание новых),
        // всегда фиксим все variation features в import_post чтобы предотвратить некорректную синхронизацию
        $truly_new_ids = array_diff($new_product_ids, $existing_product_ids);
        if (empty($truly_new_ids) && !empty($current_db_feature_ids)) {
            // Все продукты обновляются (нет новых) → это update scenario
            echo '[MWL_XLSX] Update scenario detected (no new products, all are being updated)' . PHP_EOL;
            echo '  → Will verify/fix ALL variation feature values in import_post' . PHP_EOL;
            
            // Сохраняем ВСЕ variation features для проверки
            $all_variation_features = array_unique(array_merge(
                isset($manually_added_features) ? $manually_added_features : [],
                $current_db_feature_ids
            ));
            
            echo '  → Saving to STATIC variable for import_post:' . PHP_EOL;
            echo '    * Group ID: ' . $group_id_key . PHP_EOL;
            echo '    * Features to check: [' . implode(', ', $all_variation_features) . ']' . PHP_EOL;
            
            // Используем статическую переменную вместо Registry
            // потому что Registry сбрасывается между хуками
            fn_mwl_xlsx_set_groups_to_fix($group_id_key, $all_variation_features, true);
            
            // Сохраняем также product_features из импорта для import_post
            $products_features_map = [];
            foreach ($new_product_ids as $pid) {
                if (isset($products[$pid]['variation_features'])) {
                    $products_features_map[$pid] = $products[$pid]['variation_features'];
                }
            }
            fn_mwl_xlsx_set_products_features($group_id_key, $products_features_map);
            echo '  → Saved products features map for ' . count($products_features_map) . ' products' . PHP_EOL;
        }
        
        // ЗАЩИТА: Если features в БД пусты, а в группе есть - кто-то уже удалил!
        if (empty($current_db_features) && !empty($current_feature_ids)) {
            echo '[MWL_XLSX] ⚠ WARNING: Group object has features but DB is empty!' . PHP_EOL;
            echo '  - Someone deleted features from DB before us' . PHP_EOL;
            echo '  - Will restore features to DB' . PHP_EOL;
            $has_feature_changes = true; // Принудительно обновляем
        }
        
        // ЗАЩИТА: Если мы уже обновляли features для этой группы в этом запросе - пропускаем
        if (isset($features_updated[$group_id_key])) {
            $prev_features = $features_updated[$group_id_key];
            sort($prev_features);
            $new_features_sorted = $new_feature_ids;
            sort($new_features_sorted);
            
            if ($prev_features == $new_features_sorted) {
                echo '[MWL_XLSX] Features already updated in this request (call #' . $current_call . '), skipping' . PHP_EOL;
                echo '  - Previously updated to: ' . implode(', ', $prev_features) . PHP_EOL;
                $has_feature_changes = false;
            } else {
                echo '[MWL_XLSX] Features changed since last update in this request' . PHP_EOL;
                echo '  - Previous: ' . implode(', ', $prev_features) . PHP_EOL;
                echo '  - New: ' . implode(', ', $new_features_sorted) . PHP_EOL;
            }
        }
        
        if (!$has_feature_changes) {
            echo '[MWL_XLSX] No changes in features list' . PHP_EOL;
        } else {
            // КРИТИЧЕСКИ ВАЖНО: Обновляем features в БД СРАЗУ, до удаления дубликатов!
            // Если обновить после detach, группа может пересохраниться и features потеряются
            echo '[MWL_XLSX] Will update group features BEFORE removing duplicates' . PHP_EOL;
            echo '  - Adding features: ' . (!empty($added_feature_ids) ? implode(', ', $added_feature_ids) : 'none') . PHP_EOL;
            echo '  - Removing features: ' . (!empty($removed_feature_ids) ? implode(', ', $removed_feature_ids) : 'none') . PHP_EOL;
            
            echo '[MWL_XLSX] Updating DB: deleting old features (if any)...' . PHP_EOL;
            $deleted_rows = db_query("DELETE FROM ?:product_variation_group_features WHERE group_id = ?i", $group->getId());
            echo '  - Deleted rows: ' . ($deleted_rows ? $deleted_rows : '0') . PHP_EOL;
            
            $insert_data = [];
            foreach ($available_features as $feature) {
                $insert_data[] = [
                    'group_id' => $group->getId(),
                    'feature_id' => $feature['feature_id'],
                    'purpose' => $feature['purpose']
                ];
            }
            
            echo '[MWL_XLSX] Prepared ' . count($insert_data) . ' features for insertion:' . PHP_EOL;
            foreach ($insert_data as $idx => $feat_data) {
                echo '  ' . ($idx+1) . '. Feature #' . $feat_data['feature_id'] . ' (purpose: ' . $feat_data['purpose'] . ')' . PHP_EOL;
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
                    echo '[MWL_XLSX] ✓ Features inserted to DB (inserted ' . count($insert_data) . ' records)' . PHP_EOL;
                    
                    // Проверяем что features действительно в БД
                    $check_features = db_get_array(
                        "SELECT feature_id, purpose FROM ?:product_variation_group_features WHERE group_id = ?i ORDER BY feature_id",
                        $group->getId()
                    );
                    echo '[MWL_XLSX] Verification: ' . count($check_features) . ' features in DB after insert:' . PHP_EOL;
                    if (empty($check_features)) {
                        echo '  ✗ ERROR: Features not found in DB after INSERT!' . PHP_EOL;
                        echo '  → INSERT may have failed silently or transaction rolled back' . PHP_EOL;
                    } else {
                        foreach ($check_features as $cf) {
                            echo '  - Feature #' . $cf['feature_id'] . ' (purpose: ' . $cf['purpose'] . ')' . PHP_EOL;
                        }
                        // Запоминаем что обновили features для этой группы
                        $features_updated[$group_id_key] = $new_feature_ids;
                        echo '[MWL_XLSX] Marked group #' . $group_id_key . ' as features-updated' . PHP_EOL;
                    }
                } catch (\Exception $db_error) {
                    echo '[MWL_XLSX] ✗ ERROR inserting features to DB: ' . $db_error->getMessage() . PHP_EOL;
                    echo '  Stack trace: ' . $db_error->getTraceAsString() . PHP_EOL;
                }
            } else {
                echo '[MWL_XLSX] ⚠ No features to insert!' . PHP_EOL;
            }
            
            // Перезагружаем группу с обновленными features
            echo '[MWL_XLSX] Reloading group from DB...' . PHP_EOL;
            $updated_group = $group_repository->findGroupById($group->getId());
            
            if ($updated_group) {
                echo '[MWL_XLSX] Group reloaded, features: ' . implode(', ', $updated_group->getFeatureIds()) . PHP_EOL;
                
                try {
                    $reflection = new \ReflectionClass($group);
                    $features_property = $reflection->getProperty('features');
                    $features_property->setAccessible(true);
                    $features_property->setValue($group, $updated_group->getFeatures());
                    
                    echo '[MWL_XLSX] ✓ Successfully updated group features via Reflection' . PHP_EOL;
                    echo '  - Group features after reflection: ' . implode(', ', $group->getFeatureIds()) . PHP_EOL;
                    
                    // ВАЖНО: Если были добавлены новые features, нужно отключить автосинхронизацию для них
                    // чтобы CS-Cart не скопировал значения между вариациями
                    if (!empty($added_feature_ids)) {
                        echo '[MWL_XLSX] Added features: ' . implode(', ', $added_feature_ids) . PHP_EOL;
                        echo '  → These features should NOT be auto-synced between variations' . PHP_EOL;
                        echo '  → CS-Cart sync service will run later and may overwrite values!' . PHP_EOL;
                    }
                    
                } catch (\ReflectionException $e) {
                    echo '[MWL_XLSX] ✗ Reflection ERROR: ' . $e->getMessage() . PHP_EOL;
                }
            } else {
                echo '[MWL_XLSX] ✗ ERROR: Failed to reload group from DB' . PHP_EOL;
            }
        }
        
        // ТЕПЕРЬ проверяем и удаляем дубликаты (ПОСЛЕ обновления features)
        echo '[MWL_XLSX] Checking for duplicate combinations...' . PHP_EOL;
        
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
                    echo '  - New product #' . $new_pid . ' combination: ' . $combo_key . PHP_EOL;
                }
            } else {
                echo '  - New product #' . $new_pid . ': NO variation_features in import data' . PHP_EOL;
            }
        }
        
        // Получаем combinations существующих продуктов из БД
        // Используем только те existing_product_ids которые НЕ в new_product_ids (избегаем сравнения с собой)
        $truly_existing_ids = array_diff($existing_product_ids, $new_product_ids);
        
        if (!empty($truly_existing_ids) && !empty($new_feature_ids)) {
            echo '[MWL_XLSX] Checking existing products (excluding new): ' . implode(', ', $truly_existing_ids) . PHP_EOL;
            
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
                echo '  - Existing product #' . $existing_pid . ' combination: ' . $combo_key . PHP_EOL;
                
                // Сравниваем с новыми продуктами
                foreach ($new_combinations as $new_pid => $new_combo_data) {
                    if ($combo_key === $new_combo_data['key']) {
                        $products_to_remove[] = $existing_pid;
                        echo '  ⚠ Product #' . $existing_pid . ' has SAME combination as new product #' . $new_pid . PHP_EOL;
                        echo '    → Will remove existing product #' . $existing_pid . ' from group' . PHP_EOL;
                    }
                }
            }
            
            // Удаляем дубликаты
            if (!empty($products_to_remove)) {
                echo '[MWL_XLSX] Removing ' . count($products_to_remove) . ' existing products with duplicate combinations...' . PHP_EOL;
                foreach ($products_to_remove as $pid_to_remove) {
                    $group->detachProductById($pid_to_remove);
                    echo '  - Detached product #' . $pid_to_remove . ' from group' . PHP_EOL;
                    
                    // ВАЖНО: Отключаем продукт после удаления из группы
                    // чтобы он не остался "осиротевшим" активным продуктом
                    db_query("UPDATE ?:products SET status = 'D' WHERE product_id = ?i", $pid_to_remove);
                    echo '  - Disabled product #' . $pid_to_remove . ' (status=D)' . PHP_EOL;
                }
            } else {
                echo '  - No duplicate combinations with existing products' . PHP_EOL;
            }
        } else {
            echo '  - No existing products to check (or all are being updated)' . PHP_EOL;
        }
        
        // Проверяем дубликаты ВНУТРИ импортируемой партии
        $seen_combinations = [];
        foreach ($new_combinations as $pid => $combo_data) {
            $combo_key = $combo_data['key'];
            if (isset($seen_combinations[$combo_key])) {
                $duplicate_pid = $seen_combinations[$combo_key];
                echo '  ⚠ WARNING: Duplicate in import batch!' . PHP_EOL;
                echo '    - Product #' . $duplicate_pid . ' and #' . $pid . ' both have: ' . $combo_key . PHP_EOL;
                echo '    → CS-Cart will reject one of them' . PHP_EOL;
            } else {
                $seen_combinations[$combo_key] = $pid;
            }
        }
        
        if (count($new_combinations) === count($seen_combinations)) {
            echo '  ✓ All new products have unique combinations within import batch' . PHP_EOL;
        }
        
        echo '[MWL_XLSX] Duplicate check completed' . PHP_EOL;
        
    } catch (\Exception $e) {
        // Логируем ошибку, но не прерываем процесс импорта
        echo '[MWL_XLSX] ✗ EXCEPTION: ' . $e->getMessage() . PHP_EOL;
        echo '  Trace: ' . $e->getTraceAsString() . PHP_EOL;
    }
    
    echo '[MWL_XLSX] Hook variation_group_add_products_to_group finished (call #' . $current_call . ')' . PHP_EOL;
    echo '[MWL_XLSX] ========================================' . PHP_EOL;
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
    
    echo '[MWL_XLSX] ========================================' . PHP_EOL;
    echo '[MWL_XLSX] Group saved #' . $save_num . ': "' . $group->getCode() . '" (ID:' . $group->getId() . ')' . PHP_EOL;
    echo '  - Products in group: ' . implode(', ', $group->getProductIds()) . PHP_EOL;
    echo '  - Features in group (from object): ' . implode(', ', $group->getFeatureIds()) . PHP_EOL;
    echo '  - Events count: ' . count($events) . PHP_EOL;
    
    // Проверяем features в БД
    $db_features = db_get_array(
        "SELECT feature_id FROM ?:product_variation_group_features WHERE group_id = ?i ORDER BY feature_id",
        $group_id
    );
    echo '  - Features in DB: ' . (empty($db_features) ? 'NONE (table is empty!)' : implode(', ', array_column($db_features, 'feature_id'))) . PHP_EOL;
    
    // Debug: показываем events
    if (!empty($events)) {
        echo '[MWL_XLSX] Events triggered:' . PHP_EOL;
        foreach ($events as $i => $event) {
            $event_class = get_class($event);
            $event_type = basename(str_replace('\\', '/', $event_class));
            echo '  ' . ($i+1) . '. ' . $event_type . PHP_EOL;
            
            // Показываем детали для ProductUpdatedEvent
            if (method_exists($event, 'getTo') && method_exists($event, 'getFrom')) {
                $from = $event->getFrom();
                $to = $event->getTo();
                echo '     From: Product #' . $from->getProductId() . PHP_EOL;
                echo '     To: Product #' . $to->getProductId() . PHP_EOL;
                
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
                        echo '     Feature changes: ' . json_encode($from_values) . ' → ' . json_encode($to_values) . PHP_EOL;
                    }
                }
            }
        }
    }
    
    // Проверяем feature combinations всех продуктов в группе
    echo '[MWL_XLSX] Checking feature combinations in saved group (from DB):' . PHP_EOL;
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
            echo '  - Product #' . $pid . ' [' . $product_code . ']: ' . implode(', ', $features);
            
            // Показываем детали
            $details = [];
            foreach ($by_product_detailed[$pid] as $fid => $info) {
                $details[] = 'F' . $fid . '=' . $info['variant_id'] . '(' . ($info['variant'] ?? $info['value_int']) . ')';
            }
            echo ' [' . implode(', ', $details) . ']' . PHP_EOL;
        }
        
        // Проверяем дубликаты
        $combinations = [];
        foreach ($by_product as $pid => $features) {
            ksort($features); // Сортируем для консистентности
            $combo_key = implode('_', $features);
            if (isset($combinations[$combo_key])) {
                echo '[MWL_XLSX] ⚠ DUPLICATE COMBINATION detected in saved group!' . PHP_EOL;
                echo '  - Product #' . $pid . ' has same combination as Product #' . $combinations[$combo_key] . PHP_EOL;
                echo '  - Combination: ' . implode(', ', $features) . ' (key: ' . $combo_key . ')' . PHP_EOL;
                echo '  → This should NOT happen! Check why feature values not updated correctly' . PHP_EOL;
            } else {
                $combinations[$combo_key] = $pid;
            }
        }
        
        if (count($by_product) === count($combinations)) {
            echo '  ✓ All products in group have unique combinations' . PHP_EOL;
        }
    } else {
        echo '  - No products in group' . PHP_EOL;
    }
    
    echo '[MWL_XLSX] ========================================' . PHP_EOL;
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
    
    // Проверяем обновляли ли мы features этой группы
    $registry_key = 'mwl_xlsx.group_' . $group_id . '_features_updated';
    $was_updated = \Tygh\Registry::get($registry_key);
    
    if (!$was_updated) {
        return; // Мы не обновляли features этой группы
    }
    
    $new_feature_ids = \Tygh\Registry::get('mwl_xlsx.group_' . $group_id . '_new_feature_ids');
    
    if (empty($new_feature_ids)) {
        return; // Нет новых features
    }
    
    echo '[MWL_XLSX] Hook update_product_features_value_pre for product #' . $product_id . PHP_EOL;
    echo '  - Group #' . $group_id . ' was updated with new features: ' . implode(', ', $new_feature_ids) . PHP_EOL;
    echo '  - Current product_features to save: ' . implode(', ', array_keys($product_features)) . PHP_EOL;
    
    // Проверяем какие из новых features есть в product_features
    $new_features_to_save = array_intersect($new_feature_ids, array_keys($product_features));
    
    if (!empty($new_features_to_save)) {
        echo '  - New variation features in save list: ' . implode(', ', $new_features_to_save) . PHP_EOL;
        echo '  → Marking these features to NOT be filtered by ProductsHookHandler' . PHP_EOL;
        
        // Сохраняем в Registry для использования в ProductsHookHandler
        \Tygh\Registry::set('runtime.mwl_xlsx_allow_variation_features', true);
        \Tygh\Registry::set('runtime.mwl_xlsx_allow_variation_feature_ids_for_product_' . $product_id, $new_features_to_save);
    }
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
    echo '[MWL_XLSX] ========================================' . PHP_EOL;
    echo '[MWL_XLSX] Hook import_post CALLED!' . PHP_EOL;
    echo '  - Pattern section: ' . (isset($pattern['section']) ? $pattern['section'] : 'NOT SET') . PHP_EOL;
    echo '  - Pattern pattern_id: ' . (isset($pattern['pattern_id']) ? $pattern['pattern_id'] : 'NOT SET') . PHP_EOL;
    echo '  - Import data rows: ' . (is_array($import_data) ? count($import_data) : 'NOT ARRAY') . PHP_EOL;
    
    // Проверяем что это импорт продуктов
    if (empty($pattern['pattern_id']) || $pattern['pattern_id'] !== 'products') {
        echo '  - Not a products import, skipping (pattern_id=' . (isset($pattern['pattern_id']) ? $pattern['pattern_id'] : 'NULL') . ')' . PHP_EOL;
        echo '[MWL_XLSX] ========================================' . PHP_EOL;
        return;
    }
    
    echo '[MWL_XLSX] Products import confirmed, processing...' . PHP_EOL;
    
    // Получаем список групп из статического хранилища
    $groups_to_fix = fn_mwl_xlsx_get_groups_to_fix();
    
    echo '  - Groups in static storage: ' . count($groups_to_fix) . PHP_EOL;
    if (!empty($groups_to_fix)) {
        foreach ($groups_to_fix as $gid => $data) {
            echo '    * Group #' . $gid . ': features=[' . implode(',', $data['feature_ids']) . '], update_scenario=' . ($data['is_update_scenario'] ? 'true' : 'false') . PHP_EOL;
        }
    }
    
    if (empty($groups_to_fix)) {
        echo '[MWL_XLSX] No groups to fix found, skipping post-processing' . PHP_EOL;
        echo '[MWL_XLSX] ========================================' . PHP_EOL;
        return;
    }
    
    echo '[MWL_XLSX] Found ' . count($groups_to_fix) . ' groups to process' . PHP_EOL;
    
    $group_repository = \Tygh\Addons\ProductVariations\ServiceProvider::getGroupRepository();
    
    // ВАЖНО: Для групп с новыми features нужно принудительно обновить feature values
    // потому что ProductsHookHandler отфильтровал их при сохранении
    foreach ($groups_to_fix as $group_id => $group_data) {
        $new_feature_ids = $group_data['feature_ids'];
        $is_update_scenario = $group_data['is_update_scenario'];
        
        if ($is_update_scenario) {
            echo '[MWL_XLSX] Processing group #' . $group_id . ' in UPDATE SCENARIO mode' . PHP_EOL;
            echo '  → Will verify/fix ALL variation feature values: ' . implode(', ', $new_feature_ids) . PHP_EOL;
        } else {
            echo '[MWL_XLSX] Processing group #' . $group_id . ' with new features: ' . implode(', ', $new_feature_ids) . PHP_EOL;
        }
        
        $group = $group_repository->findGroupById($group_id);
        if (!$group) {
            echo '  - Group not found, skipping' . PHP_EOL;
            continue;
        }
        
        $product_ids = $group->getProductIds();
        echo '  - Products in group: ' . implode(', ', $product_ids) . PHP_EOL;
        
        if (empty($product_ids)) {
            echo '  - No products in group, skipping' . PHP_EOL;
            continue;
        }
        
        // Ищем эти продукты в import_data чтобы получить правильные feature values из CSV
        echo '  - Searching for products in import_data (total rows: ' . count($import_data) . ')...' . PHP_EOL;
        
        // DEBUG: Показываем структуру первой строки
        if (!empty($import_data)) {
            $first_row = reset($import_data);
            echo '  - DEBUG: import_data structure:' . PHP_EOL;
            echo '    * First row keys: ' . implode(', ', array_keys($first_row)) . PHP_EOL;
            $first_inner = reset($first_row);
            if (is_array($first_inner)) {
                echo '    * Inner keys: ' . implode(', ', array_keys($first_inner)) . PHP_EOL;
                if (isset($first_inner['product_id'])) {
                    echo '    * product_id value: ' . ($first_inner['product_id'] ?: 'EMPTY') . PHP_EOL;
                }
                if (isset($first_inner['product_code'])) {
                    echo '    * product_code value: ' . $first_inner['product_code'] . PHP_EOL;
                }
            }
        }
        
        // Получаем product_code для всех продуктов группы
        $product_codes_map = db_get_hash_single_array(
            "SELECT product_id, product_code FROM ?:products WHERE product_id IN (?a)",
            ['product_id', 'product_code'],
            $product_ids
        );
        echo '  - Product codes in group: ' . implode(', ', array_map(function($pid, $code) {
            return '#' . $pid . '=' . $code;
        }, array_keys($product_codes_map), $product_codes_map)) . PHP_EOL;
        
        $products_to_fix = [];
        foreach ($import_data as $idx => $import_row) {
            $row = reset($import_row);
            $row_product_code = isset($row['product_code']) ? $row['product_code'] : null;
            
            if ($row_product_code) {
                // Ищем product_id по product_code
                $found_pid = array_search($row_product_code, $product_codes_map);
                if ($found_pid !== false) {
                    $products_to_fix[$found_pid] = $row;
                    echo '    * Found product #' . $found_pid . ' (code: ' . $row_product_code . ') at import_data[' . $idx . ']' . PHP_EOL;
                }
            }
        }
        
        if (empty($products_to_fix)) {
            echo '  - No products from this group in import data' . PHP_EOL;
            continue;
        }
        
        echo '  - Found ' . count($products_to_fix) . ' products in import data to fix' . PHP_EOL;
        
        // Получаем product_features из статического хранилища
        $products_features_map = fn_mwl_xlsx_get_products_features($group_id);
        if (empty($products_features_map)) {
            echo '  - No products features map found in static storage for group #' . $group_id . PHP_EOL;
            continue;
        }
        echo '  - Products features map contains ' . count($products_features_map) . ' products' . PHP_EOL;
        
        // Для каждого продукта обновляем feature values для новых features
        foreach ($products_to_fix as $pid => $import_row) {
            if (!isset($products_features_map[$pid])) {
                echo '  - Product #' . $pid . ': NO product_features in static storage' . PHP_EOL;
                continue;
            }
            
            $product_features = $products_features_map[$pid];
            if (!is_array($product_features)) {
                echo '  - Product #' . $pid . ': product_features is not array: ' . gettype($product_features) . PHP_EOL;
                continue;
            }
            
            echo '  - Fixing product #' . $pid . ' feature values...' . PHP_EOL;
            echo '    Available product_features: ' . implode(', ', array_keys($product_features)) . PHP_EOL;
            
            // Проверяем какие из новых features есть в импортируемых данных
            foreach ($new_feature_ids as $new_fid) {
                if (isset($product_features[$new_fid])) {
                    $feature_data = $product_features[$new_fid];
                    $variant_id = isset($feature_data['variant_id']) ? $feature_data['variant_id'] : (isset($feature_data['value']) ? $feature_data['value'] : null);
                    
                    if (!$variant_id) {
                        echo '    * Feature #' . $new_fid . ': NO variant_id found' . PHP_EOL;
                        continue;
                    }
                    
                    // Получаем название feature и variant для логирования
                    $feature_name = db_get_field("SELECT description FROM ?:product_features_descriptions WHERE feature_id = ?i AND lang_code = 'en'", $new_fid);
                    $variant_name = db_get_field("SELECT variant FROM ?:product_feature_variant_descriptions WHERE variant_id = ?i AND lang_code = 'en'", $variant_id);
                    
                    echo '    * Feature #' . $new_fid . ' (' . ($feature_name ?: 'unknown') . '): will update to variant_id=' . $variant_id . ' (' . ($variant_name ?: 'unknown') . ')' . PHP_EOL;
                    
                    // Обновляем feature value для всех языков
                    $updated = db_query(
                        "UPDATE ?:product_features_values " .
                        "SET variant_id = ?i " .
                        "WHERE product_id = ?i AND feature_id = ?i",
                        $variant_id, $pid, $new_fid
                    );
                    
                    echo '      → Updated ' . ($updated ? $updated : '0') . ' rows in DB' . PHP_EOL;
                } else {
                    echo '    * Feature #' . $new_fid . ': NOT in product_features map' . PHP_EOL;
                }
            }
        }
        
        // Проверяем итоговое состояние feature values
        echo '  - Verifying fixed feature values from DB:' . PHP_EOL;
        $verification = db_get_array(
            "SELECT pfv.product_id, pfv.feature_id, pfv.variant_id, pfvd.variant " .
            "FROM ?:product_features_values pfv " .
            "LEFT JOIN ?:product_feature_variant_descriptions pfvd ON pfv.variant_id = pfvd.variant_id AND pfvd.lang_code = 'en' " .
            "WHERE pfv.product_id IN (?a) AND pfv.feature_id IN (?a) AND pfv.lang_code = 'en' " .
            "ORDER BY pfv.product_id, pfv.feature_id",
            $product_ids, $new_feature_ids
        );
        
        $by_prod = [];
        foreach ($verification as $row) {
            $by_prod[$row['product_id']][$row['feature_id']] = $row['variant'] . ' (vid:' . $row['variant_id'] . ')';
        }
        
        foreach ($by_prod as $pid => $feats) {
            echo '    - Product #' . $pid . ': ' . implode(', ', $feats) . PHP_EOL;
        }
        
    }
    
    // Очищаем статическое хранилище после обработки
    fn_mwl_xlsx_get_groups_to_fix([]);
    
    echo '[MWL_XLSX] Post-import feature values fix completed' . PHP_EOL;
    echo '[MWL_XLSX] ========================================' . PHP_EOL;
}
