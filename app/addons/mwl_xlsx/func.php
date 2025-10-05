<?php
use Tygh\Addons\MwlXlsx\Planfix\EventRepository;
use Tygh\Addons\MwlXlsx\Planfix\IntegrationSettings;
use Tygh\Addons\MwlXlsx\Planfix\LinkRepository;
use Tygh\Addons\MwlXlsx\Planfix\StatusMapRepository;
use Tygh\Addons\MwlXlsx\Planfix\McpClient;
use Tygh\Addons\MwlXlsx\Service\SettingsBackup;
use Tygh\Http;
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
    $planfix_object_id = isset($link['planfix_object_id']) ? (string) $link['planfix_object_id'] : '';

    if ($planfix_object_id === '') {
        return '';
    }

    if ($origin === null) {
        $origin = (string) Registry::get('addons.mwl_xlsx.planfix_origin');
    }

    $origin = trim($origin);

    if ($origin === '') {
        return '';
    }

    $origin = rtrim($origin, '/');
    $type = '';

    if (!empty($link['planfix_object_type'])) {
        $type = trim((string) $link['planfix_object_type']);
    }

    if ($type !== '') {
        $origin .= '/' . ltrim($type, '/');
    }

    return $origin . '/' . rawurlencode($planfix_object_id);
}

function fn_mwl_planfix_integration_settings(bool $force_reload = false): IntegrationSettings
{
    static $settings;

    if ($force_reload || $settings === null) {
        $settings = IntegrationSettings::fromRegistry();
    }

    return $settings;
}

function fn_mwl_planfix_get_integration_settings(bool $force_reload = false): array
{
    return fn_mwl_planfix_integration_settings($force_reload)->toArray();
}

function fn_mwl_planfix_get_mcp_endpoint(): string
{
    return fn_mwl_planfix_integration_settings()->getMcpEndpoint();
}

function fn_mwl_planfix_get_mcp_auth_token(): string
{
    return fn_mwl_planfix_integration_settings()->getMcpAuthToken();
}

function fn_mwl_planfix_get_webhook_basic_login(): string
{
    return fn_mwl_planfix_integration_settings()->getWebhookBasicLogin();
}

function fn_mwl_planfix_get_webhook_basic_password(): string
{
    return fn_mwl_planfix_integration_settings()->getWebhookBasicPassword();
}

function fn_mwl_planfix_get_webhook_basic_auth_credentials(): array
{
    return fn_mwl_planfix_integration_settings()->getWebhookBasicAuthCredentials();
}

function fn_mwl_planfix_get_direction_default(): string
{
    return fn_mwl_planfix_integration_settings()->getDirectionDefault();
}

function fn_mwl_planfix_get_auto_task_statuses(): array
{
    return fn_mwl_planfix_integration_settings()->getAutoTaskStatuses();
}

function fn_mwl_planfix_format_order_id($order_id): string
{
    if (function_exists('fn_format_order_id')) {
        return (string) fn_format_order_id($order_id);
    }

    return (string) $order_id;
}

function fn_mwl_planfix_should_sync_comments(): bool
{
    return fn_mwl_planfix_integration_settings()->shouldSyncComments();
}

function fn_mwl_planfix_should_sync_payments(): bool
{
    return fn_mwl_planfix_integration_settings()->shouldSyncPayments();
}

function fn_mwl_planfix_get_webhook_allowlist_ips(): array
{
    return fn_mwl_planfix_integration_settings()->getWebhookAllowlistIps();
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

function fn_mwl_planfix_mcp_client(bool $force_reload = false): McpClient
{
    static $client;
    static $cache_key;

    $endpoint = fn_mwl_planfix_get_mcp_endpoint();
    $token = fn_mwl_planfix_get_mcp_auth_token();
    $key = md5($endpoint . '|' . $token);

    if ($force_reload || $client === null || $cache_key !== $key) {
        $client = new McpClient($endpoint, $token);
        $cache_key = $key;
    }

    return $client;
}

function fn_mwl_planfix_decode_link_extra($extra): array
{
    if (is_array($extra)) {
        return $extra;
    }

    if (is_string($extra) && $extra !== '') {
        $decoded = json_decode($extra, true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }

    return [];
}

function fn_mwl_planfix_update_link_extra(array $link, array $extra_updates, array $additional_fields = []): void
{
    if (empty($link['link_id'])) {
        return;
    }

    $extra = fn_mwl_planfix_decode_link_extra($link['extra'] ?? null);

    foreach ($extra_updates as $key => $value) {
        if ($value === null) {
            unset($extra[$key]);
        } else {
            $extra[$key] = $value;
        }
    }

    $data = ['extra' => $extra];

    if ($additional_fields) {
        $data = array_merge($data, $additional_fields);
    }

    fn_mwl_planfix_link_repository()->update((int) $link['link_id'], $data);
}

function fn_mwl_planfix_validate_basic_auth(): bool
{
    [$expected_login, $expected_password] = fn_mwl_planfix_get_webhook_basic_auth_credentials();

    $expected_login = (string) $expected_login;
    $expected_password = (string) $expected_password;

    if ($expected_login === '' && $expected_password === '') {
        return true;
    }

    $login = null;
    $password = null;

    if (isset($_SERVER['PHP_AUTH_USER'])) {
        $login = (string) $_SERVER['PHP_AUTH_USER'];
        $password = isset($_SERVER['PHP_AUTH_PW']) ? (string) $_SERVER['PHP_AUTH_PW'] : '';
    } else {
        $headers = [];

        foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $header) {
            if (!empty($_SERVER[$header])) {
                $headers[] = (string) $_SERVER[$header];
            }
        }

        foreach ($headers as $header) {
            if (stripos($header, 'Basic ') === 0) {
                $decoded = base64_decode(substr($header, 6), true);
                if ($decoded !== false) {
                    $parts = explode(':', $decoded, 2);
                    if (count($parts) === 2) {
                        $login = (string) $parts[0];
                        $password = (string) $parts[1];
                        break;
                    }
                }
            }
        }
    }

    if ($login === null) {
        return false;
    }

    if (!function_exists('hash_equals')) {
        return $expected_login === $login && $expected_password === $password;
    }

    return hash_equals($expected_login, $login) && hash_equals($expected_password, $password);
}

function fn_mwl_planfix_is_ip_allowlisted(): bool
{
    $allowlist = fn_mwl_planfix_get_webhook_allowlist_ips();
    if (!$allowlist) {
        return true;
    }

    $remote_ip = isset($_SERVER['REMOTE_ADDR']) ? trim((string) $_SERVER['REMOTE_ADDR']) : '';
    if ($remote_ip === '') {
        return false;
    }

    $allowlist = array_map(static function ($ip) {
        return trim((string) $ip);
    }, $allowlist);

    return in_array($remote_ip, $allowlist, true);
}

function fn_mwl_planfix_get_raw_body(): string
{
    static $body;

    if ($body === null) {
        $body = file_get_contents('php://input');
    }

    return is_string($body) ? $body : '';
}

function fn_mwl_planfix_get_json_body(): array
{
    $raw = fn_mwl_planfix_get_raw_body();

    if ($raw === '') {
        return [];
    }

    $decoded = json_decode($raw, true);

    return is_array($decoded) ? $decoded : [];
}

function fn_mwl_planfix_output_json(int $status_code, array $data): void
{
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8', true, $status_code);
    }

    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function fn_mwl_planfix_handle_planfix_status_webhook(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        fn_mwl_planfix_output_json(405, [
            'success' => false,
            'message' => 'Method Not Allowed',
        ]);
    }

    if (!fn_mwl_planfix_is_ip_allowlisted()) {
        fn_mwl_planfix_output_json(403, [
            'success' => false,
            'message' => 'IP is not allowed',
        ]);
    }

    if (!fn_mwl_planfix_validate_basic_auth()) {
        header('WWW-Authenticate: Basic realm="Planfix webhook"');
        fn_mwl_planfix_output_json(401, [
            'success' => false,
            'message' => 'Unauthorized',
        ]);
    }

    $payload = fn_mwl_planfix_get_json_body();

    if (!$payload) {
        $payload = $_REQUEST;
    }

    $planfix_task_id = '';

    foreach (['planfix_task_id', 'task_id', 'id'] as $key) {
        if (!empty($payload[$key])) {
            $planfix_task_id = (string) $payload[$key];
            break;
        }
    }

    if ($planfix_task_id === '') {
        fn_mwl_planfix_output_json(400, [
            'success' => false,
            'message' => 'planfix_task_id is required',
        ]);
    }

    $status_id = isset($payload['status_id']) ? (string) $payload['status_id'] : '';

    $link_repository = fn_mwl_planfix_link_repository();
    $link = $link_repository->findByPlanfix('task', $planfix_task_id);

    if (!$link) {
        fn_mwl_planfix_output_json(404, [
            'success' => false,
            'message' => 'Link not found',
        ]);
    }

    $extra_updates = [
        'planfix_meta' => [
            'status_id'   => $status_id,
            'updated_at'  => TIME,
        ],
        'last_incoming_status' => [
            'status_id'   => $status_id,
            'received_at' => TIME,
        ],
        'last_planfix_payload_in' => [
            'received_at' => TIME,
            'payload'     => $payload,
        ],
    ];

    $target_status = null;

    if ($status_id !== '') {
        $status_map = fn_mwl_planfix_status_map_repository()->findLocalStatus(
            (int) $link['company_id'],
            (string) $link['entity_type'],
            $status_id
        );

        if ($status_map && !empty($status_map['entity_status'])) {
            $target_status = (string) $status_map['entity_status'];
            $extra_updates['planfix_meta']['mapped_status'] = $target_status;
        }
    }

    $message_details = [];

    if ($status_id !== '') {
        $planfix_status_details = [];

        if ($status_id !== '') {
            $planfix_status_details[] = "id {$status_id}";
        }

        $planfix_status_message = 'Planfix status ' . implode(', ', $planfix_status_details);

        if ($target_status !== null && $status_id !== '') {
            $planfix_status_message .= " -> entity status {$target_status}";
        }

        $message_details[] = $planfix_status_message;
    }

    if ($target_status !== null && $status_id === '') {
        $message_details[] = "Mapped entity status {$target_status}";
    }

    $message_details[] = "status_id: " . $status_id;
    $message_details[] = "target_status: " . $target_status;

    $message = 'Link metadata updated';
    $success = true;

    if ($target_status && $link['entity_type'] === 'order') {
        $order_id = (int) $link['entity_id'];
        if ($order_id) {
            $current_status = db_get_field('SELECT status FROM ?:orders WHERE order_id = ?i', $order_id);

            if ($current_status !== $target_status) {
                fn_mwl_planfix_record_status_skip($order_id);
                $changed = fn_change_order_status($order_id, $target_status, (string) $current_status);

                if ($changed !== false) {
                    $message = 'Order status updated';
                } else {
                    $message = 'Failed to update order status';
                    $success = false;
                }
            } else {
                $message = 'Order status already up to date';
            }
        }
    }

    if ($message_details) {
        $message .= ': ' . implode('; ', $message_details);
    }

    fn_mwl_planfix_update_link_extra($link, $extra_updates);

    fn_mwl_planfix_output_json($success ? 200 : 500, [
        'success' => $success,
        'message' => $message,
    ]);
}

function fn_mwl_planfix_build_sell_task_payload(array $order_info): array
{
    $order_id = isset($order_info['order_id']) ? (int) $order_info['order_id'] : 0;
    $company_id = isset($order_info['company_id']) ? (int) $order_info['company_id'] : 0;
    $primary_currency = isset($order_info['primary_currency'])
        ? (string) $order_info['primary_currency']
        : (defined('CART_PRIMARY_CURRENCY') ? CART_PRIMARY_CURRENCY : 'RUB');
    $secondary_currency = isset($order_info['secondary_currency']) ? (string) $order_info['secondary_currency'] : '';
    $currency_code = $secondary_currency !== '' ? $secondary_currency : ((string) ($order_info['currency'] ?? $primary_currency));

    $products = isset($order_info['products']) && is_array($order_info['products'])
        ? $order_info['products']
        : [];

    $first_product_name = '';
    foreach ($products as $item) {
        if (!empty($item['product'])) {
            $first_product_name = (string) $item['product'];
            break;
        }
    }

    if ($first_product_name === '') {
        $first_product_name = $order_id
            ? sprintf('#%s', fn_mwl_planfix_format_order_id($order_id))
            : __('order');
    }

    $task_name = sprintf('Продажа %s на pressfinity.com', $first_product_name);

    $lines = [];
    foreach ($products as $item) {
        $product_name = (string) ($item['product'] ?? '');
        if ($product_name === '' && !empty($item['product_id'])) {
            $product_name = sprintf('#%d', (int) $item['product_id']);
        }

        $amount = isset($item['amount']) ? (int) $item['amount'] : 0;
        if ($amount <= 0) {
            $amount = 1;
        }

        $subtotal = isset($item['subtotal']) ? (float) $item['subtotal'] : 0.0;
        $price = number_format($subtotal, 2, '.', ' ');
        $price = rtrim(rtrim($price, '0'), '.');
        if ($price === '') {
            $price = '0';
        }

        $line = sprintf('- %s ×%d, %s', $product_name, $amount, $price);

        if ($currency_code !== '') {
            $line .= ' ' . $currency_code;
        }

        $lines[] = $line;
    }

    $description = implode("\n", $lines);

    $order_url = $order_id
        ? fn_url('orders.details?order_id=' . $order_id, 'A', 'current', CART_LANGUAGE, true)
        : '';

    if ($order_url !== '') {
        if ($description !== '') {
            $description .= "\n\n";
        }

        $description .= sprintf('Ссылка на заказ: %s', $order_url);
    }

    if ($description === '' && $order_id) {
        $description = sprintf('Заказ #%s', fn_mwl_planfix_format_order_id($order_id));
    }

    $customer = [
        'name'  => trim(((string) ($order_info['firstname'] ?? '')) . ' ' . ((string) ($order_info['lastname'] ?? ''))),
        'email' => (string) ($order_info['email'] ?? ''),
        'phone' => (string) ($order_info['phone'] ?? ''),
    ];

    $customer = array_filter($customer, static function ($value) {
        return $value !== '';
    });

    $order_number = $order_id ? fn_mwl_planfix_format_order_id($order_id) : '';
    $order_url = $order_id
        ? fn_url('orders.details?order_id=' . $order_id, 'A', 'current', CART_LANGUAGE, true)
        : '';

    $user_data = fn_get_user_info($order_info['user_id']);


    $payload = [
        'name'          => $task_name,
        'agency'        => $user_data['company'],
        'email'         => $order_info['email'],
        'employee_name' => trim($user_data['firstname'] . ' ' . $user_data['lastname']),
        'telegram'      => fn_mwl_xlsx_get_user_telegram((int) ($order_info['user_id'] ?? 0), $user_data),
        'description'   => $description,
        'order_id'      => $order_id ? (string) $order_id : '',
        'order_number'  => (string) $order_number,
        'order_url'     => $order_url,
        'company_id'    => $company_id,
        'direction'     => fn_mwl_planfix_get_direction_default(),
        'status'        => (string) ($order_info['status'] ?? ''),
        'total'         => isset($order_info['total']) ? (float) $order_info['total'] : 0.0,
        'currency'      => $currency_code,
    ];

    if ($customer) {
        $payload['customer'] = $customer;
    }

    return $payload;
}

function fn_mwl_planfix_create_task_for_order(int $order_id, array $order_info = []): array
{
    if (!$order_info) {
        $order_info = fn_get_order_info($order_id, false, true, true, false);
    }

    if (!$order_info) {
        return [
            'success' => false,
            'message' => __('mwl_xlsx.planfix_error_order_not_found'),
        ];
    }

    $company_id = isset($order_info['company_id']) ? (int) $order_info['company_id'] : 0;
    $payload = fn_mwl_planfix_build_sell_task_payload($order_info);

    $client = fn_mwl_planfix_mcp_client();
    $response = $client->createTask($payload);

    if (empty($response['success'])) {
        return [
            'success' => false,
            'message' => __('mwl_xlsx.planfix_error_mcp_request'),
            'response' => $response,
        ];
    }

    $data = isset($response['data']) && is_array($response['data']) ? $response['data'] : [];
    $planfix_task_id = (string) ($data['taskId'] ?? '');
    $planfix_task_url = (string) ($data['url'] ?? '');

    if ($planfix_task_id === '') {
        return [
            'success' => false,
            'message' => __('mwl_xlsx.planfix_error_task_id_missing'),
            'response' => $response,
        ];
    }

    $planfix_object_type = (string) ($data['planfix_object_type'] ?? 'task');

    $extra = [
        'planfix_meta' => [
            'status_id'   => isset($data['status_id']) ? (string) $data['status_id'] : '',
            'direction'   => isset($data['direction']) ? (string) $data['direction'] : $payload['direction'],
        ],
        'created_via' => 'planfix_create_sell_task',
        'created_at'  => TIME,
    ];

    $link_repository = fn_mwl_planfix_link_repository();
    $link_repository->upsert($company_id, 'order', $order_id, $planfix_object_type, $planfix_task_id, $extra);

    $link = $link_repository->findByEntity($company_id, 'order', $order_id);

    if ($link) {
        fn_mwl_planfix_update_link_extra(
            $link,
            [],
            [
                'last_push_at'     => TIME,
                'last_payload_out' => json_encode(['planfix_create_sell_task' => $payload], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]
        );

        $link = $link_repository->findByEntity($company_id, 'order', $order_id);

        if (is_array($link)) {
            $planfix_origin = (string) Registry::get('addons.mwl_xlsx.planfix_origin');
            $link['planfix_url'] = fn_mwl_planfix_build_object_url($link, $planfix_origin);
        }
    }

    return [
        'success'  => true,
        'message'  => __('mwl_xlsx.planfix_task_created', ['[id]' => $planfix_task_id]),
        'link'     => $link,
        'response' => $response,
    ];
}

function fn_mwl_planfix_bind_task_to_order(int $order_id, int $company_id, string $planfix_task_id, string $planfix_object_type = 'task', array $meta = []): array
{
    $planfix_task_id = trim($planfix_task_id);

    if ($planfix_task_id === '') {
        return [
            'success' => false,
            'message' => __('mwl_xlsx.planfix_error_task_id_missing'),
        ];
    }

    $planfix_object_type = $planfix_object_type !== '' ? $planfix_object_type : 'task';

    $link_repository = fn_mwl_planfix_link_repository();
    $existing = $link_repository->findByPlanfix($planfix_object_type, $planfix_task_id, $company_id ?: null);

    if ($existing && (int) $existing['entity_id'] !== $order_id) {
        return [
            'success' => false,
            'message' => __('mwl_xlsx.planfix_task_already_bound', ['[order_id]' => (int) $existing['entity_id']]),
        ];
    }

    $extra = [
        'bound_manually' => TIME,
    ];

    if (isset($meta['planfix_meta']) && is_array($meta['planfix_meta'])) {
        $extra['planfix_meta'] = $meta['planfix_meta'];
        unset($meta['planfix_meta']);
    }

    $extra = array_merge($extra, $meta);

    $link_repository->upsert($company_id, 'order', $order_id, $planfix_object_type, $planfix_task_id, $extra);
    $link = $link_repository->findByEntity($company_id, 'order', $order_id);

    return [
        'success' => true,
        'message' => __('mwl_xlsx.planfix_task_bound', ['[id]' => $planfix_task_id]),
        'link'    => $link,
    ];
}

function fn_mwl_planfix_record_status_skip(int $order_id): void
{
    $skip_orders = Registry::get('mwl_xlsx.planfix_status.skip_orders');
    if (!is_array($skip_orders)) {
        $skip_orders = [];
    }

    $skip_orders[$order_id] = TIME;
    Registry::set('mwl_xlsx.planfix_status.skip_orders', $skip_orders);
}

function fn_mwl_planfix_should_skip_status_push(int $order_id): bool
{
    $skip_orders = Registry::get('mwl_xlsx.planfix_status.skip_orders');
    if (!is_array($skip_orders)) {
        return false;
    }

    if (!isset($skip_orders[$order_id])) {
        return false;
    }

    unset($skip_orders[$order_id]);
    Registry::set('mwl_xlsx.planfix_status.skip_orders', $skip_orders);

    return true;
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

    if (fn_mwl_planfix_should_skip_status_push($order_id)) {
        return;
    }

    $company_id = isset($order_info['company_id']) ? (int) $order_info['company_id'] : 0;

    $link_repository = fn_mwl_planfix_link_repository();
    $link = $link_repository->findByEntity($company_id, 'order', $order_id);

    if (!$link || empty($link['planfix_object_id'])) {
        $creation_result = fn_mwl_planfix_create_task_for_order($order_id);

        if (empty($creation_result['success'])) {
            return;
        }

        $link = $creation_result['link'] ?? $link_repository->findByEntity($company_id, 'order', $order_id);

        if (!$link || empty($link['planfix_object_id'])) {
            return;
        }
    }

    $planfix_object_id = (string) $link['planfix_object_id'];
    $planfix_object_type = isset($link['planfix_object_type']) && $link['planfix_object_type'] !== ''
        ? (string) $link['planfix_object_type']
        : 'task';

    $client = fn_mwl_planfix_mcp_client();
    $payloads = [];

    $status_payload = [
        'order_id'            => $order_id,
        'company_id'          => $company_id,
        'status_to'           => (string) $status_to,
        'status_from'         => (string) $status_from,
        'planfix_object_id'   => $planfix_object_id,
        'planfix_object_type' => $planfix_object_type,
    ];

    if (isset($order_info['total'])) {
        $status_payload['total'] = (float) $order_info['total'];
    }

    $payloads['update_sale_status'] = $status_payload;
    $client->updateSaleStatus($status_payload);

    if (fn_mwl_planfix_should_sync_comments()) {
        $comment = '';

        if (!empty($order_info['details'])) {
            $comment = (string) $order_info['details'];
        } elseif (!empty($order_info['notes'])) {
            $comment = (string) $order_info['notes'];
        }

        if ($comment !== '') {
            $comment_payload = [
                'order_id'            => $order_id,
                'company_id'          => $company_id,
                'planfix_object_id'   => $planfix_object_id,
                'planfix_object_type' => $planfix_object_type,
                'comment'             => $comment,
            ];

            $payloads['append_sale_comment'] = $comment_payload;
            $client->appendSaleComment($comment_payload);
        }
    }

    if (fn_mwl_planfix_should_sync_payments()) {
        $amount = null;

        if (isset($order_info['total_paid'])) {
            $amount = (float) $order_info['total_paid'];
        } elseif (isset($order_info['total'])) {
            $amount = (float) $order_info['total'];
        }

        if ($amount !== null) {
            $payment_payload = [
                'order_id'            => $order_id,
                'company_id'          => $company_id,
                'planfix_object_id'   => $planfix_object_id,
                'planfix_object_type' => $planfix_object_type,
                'amount'              => $amount,
            ];

            if (!empty($order_info['secondary_currency'])) {
                $payment_payload['currency'] = (string) $order_info['secondary_currency'];
            } elseif (!empty($order_info['primary_currency'])) {
                $payment_payload['currency'] = (string) $order_info['primary_currency'];
            } elseif (!empty($order_info['currency'])) {
                $payment_payload['currency'] = (string) $order_info['currency'];
            }

            $payloads['register_sale_payment'] = $payment_payload;
            $client->registerSalePayment($payment_payload);
        }
    }

    $summary_extra = [
        'last_outgoing_status' => [
            'status_to'   => (string) $status_to,
            'status_from' => (string) $status_from,
            'pushed_at'   => TIME,
        ],
    ];

    fn_mwl_planfix_update_link_extra(
        $link,
        $summary_extra,
        [
            'last_push_at'     => TIME,
            'last_payload_out' => json_encode($payloads, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]
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

/**
 * Returns the Telegram contact for a user based on profile data.
 */
function fn_mwl_xlsx_get_user_telegram(int $user_id = 0, array $user_info = null): string
{
    if ($user_info === null) {
        if ($user_id <= 0) {
            return '';
        }

        $user_info = fn_get_user_info($user_id);
    }

    if (!$user_info || !is_array($user_info)) {
        return '';
    }

    $value = fn_mwl_xlsx_extract_telegram($user_info);
    if ($value !== '') {
        return $value;
    }

    $fields = isset($user_info['fields']) && is_array($user_info['fields']) ? $user_info['fields'] : [];
    if ($fields) {
        $value = fn_mwl_xlsx_extract_telegram($fields);
        if ($value !== '') {
            return $value;
        }

        static $telegram_field_ids;

        if ($telegram_field_ids === null) {
            $telegram_field_ids = fn_mwl_xlsx_find_telegram_field_ids();
        }

        foreach ($telegram_field_ids as $field_id) {
            if (!isset($fields[$field_id])) {
                continue;
            }

            $value = $fields[$field_id];

            if (is_array($value)) {
                $value = reset($value);
            }

            if ($value === null) {
                continue;
            }

            $value = trim((string) $value);

            if ($value !== '') {
                return $value;
            }
        }
    }

    return '';
}

/**
 * Extracts the first meaningful Telegram value from the provided array.
 */
function fn_mwl_xlsx_extract_telegram(array $data): string
{
    foreach (['telegram', 'telegram_id', 'telegram_handle', 'tg', 'telegram_username'] as $key) {
        if (!isset($data[$key])) {
            continue;
        }

        $value = $data[$key];

        if (is_array($value)) {
            $value = reset($value);
        }

        if ($value === null) {
            continue;
        }

        $value = trim((string) $value);

        if ($value !== '') {
            return $value;
        }
    }

    return '';
}

/**
 * Detects profile field ids that likely store Telegram handles.
 *
 * @return int[]
 */
function fn_mwl_xlsx_find_telegram_field_ids(): array
{
    $profile_fields = fn_get_profile_fields('ALL');
    $ids = [];

    foreach ($profile_fields as $section_fields) {
        if (!is_array($section_fields)) {
            continue;
        }

        foreach ($section_fields as $field) {
            if (empty($field['field_id'])) {
                continue;
            }

            $field_id = (int) $field['field_id'];
            $field_name = isset($field['field_name']) ? fn_strtolower(trim((string) $field['field_name'])) : '';
            $description = isset($field['description']) ? fn_strtolower(trim((string) $field['description'])) : '';

            if (in_array($field_name, ['telegram', 'telegram_id', 'telegram_handle', 'tg', 'telegram_username'], true)) {
                $ids[] = $field_id;
                continue;
            }

            if ($description !== '' && strpos($description, 'telegram') !== false) {
                $ids[] = $field_id;
            }
        }
    }

    return array_values(array_unique($ids));
}

function fn_mwl_xlsx_normalize_telegram_chat_id(string $chat_id): string
{
    $chat_id = trim($chat_id);

    if ($chat_id === '') {
        return '';
    }

    if ($chat_id[0] === '@' || $chat_id[0] === '-' || ctype_digit($chat_id)) {
        return $chat_id;
    }

    return '@' . ltrim($chat_id, '@');
}

function fn_mwl_xlsx_get_chat_id_by_username(string $token, string $username): string
{
    $token = trim($token);
    $username = trim($username);

    if ($token === '' || $username === '') {
        return '';
    }

    $normalized_username = ltrim($username, '@');

    if ($normalized_username === '') {
        return '';
    }

    $username_with_at = '@' . $normalized_username;
    $base_url = "https://api.telegram.org/bot{$token}";
    $options_base = [
        'timeout'    => 10,
        'log_result' => true,
    ];

    $get_chat_response = Http::get(
        $base_url . '/getChat',
        ['chat_id' => $username_with_at],
        array_merge($options_base, ['log_pre' => 'mwl_xlsx.telegram_get_chat'])
    );

    if ($get_chat_response) {
        $decoded = json_decode($get_chat_response, true);

        if (!empty($decoded['ok']) && isset($decoded['result']['id'])) {
            return (string) $decoded['result']['id'];
        }
    }

    $updates_response = Http::get(
        $base_url . '/getUpdates',
        [],
        array_merge($options_base, ['log_pre' => 'mwl_xlsx.telegram_get_updates'])
    );

    if (!$updates_response) {
        return '';
    }

    $updates = json_decode($updates_response, true);

    if (empty($updates['ok']) || empty($updates['result']) || !is_array($updates['result'])) {
        return '';
    }

    $needle = fn_strtolower($normalized_username);

    foreach ($updates['result'] as $update) {
        if (!is_array($update)) {
            continue;
        }

        $messages = [];

        foreach (['message', 'edited_message', 'channel_post', 'edited_channel_post'] as $key) {
            if (isset($update[$key]) && is_array($update[$key])) {
                $messages[] = $update[$key];
            }
        }

        if (isset($update['callback_query']) && is_array($update['callback_query'])) {
            $callback_query = $update['callback_query'];

            if (isset($callback_query['message']) && is_array($callback_query['message'])) {
                $messages[] = $callback_query['message'];
            }

            if (isset($callback_query['from']) && is_array($callback_query['from'])) {
                $messages[] = ['from' => $callback_query['from']];
            }
        }

        foreach ($messages as $message) {
            if (!is_array($message)) {
                continue;
            }

            if (isset($message['from']) && is_array($message['from'])) {
                $from = $message['from'];

                if (isset($from['username']) && fn_strtolower((string) $from['username']) === $needle && isset($from['id'])) {
                    return (string) $from['id'];
                }
            }

            if (isset($message['chat']) && is_array($message['chat'])) {
                $chat = $message['chat'];

                if (isset($chat['username']) && fn_strtolower((string) $chat['username']) === $needle && isset($chat['id'])) {
                    return (string) $chat['id'];
                }
            }
        }
    }

    return '';
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

function fn_mwl_xlsx_install()
{
    SettingsBackup::restore();
}

function fn_mwl_xlsx_uninstall()
{
    SettingsBackup::backup();
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
    fn_mwl_xlsx_load_vendor_autoloader();
    
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
    $message_author_text = htmlspecialchars((string) $message_author, ENT_QUOTES, 'UTF-8');
    $last_message_html = nl2br(htmlspecialchars((string) $last_message, ENT_QUOTES, 'UTF-8'), false);
    $message_author_plain = htmlspecialchars_decode($message_author_text, ENT_QUOTES);
    $message_body_plain = str_replace(["\r\n", "\r"], "\n", (string) $last_message);
    $message_body_plain = str_replace(["\r\n", "\r"], "\n", $message_body_plain);

    $http_host = htmlspecialchars((string) Registry::get('config.http_host'), ENT_QUOTES, 'UTF-8');
    $admin_url = fn_url($action_url, 'A', 'current', CART_LANGUAGE, true);
    $admin_url_html = htmlspecialchars($admin_url, ENT_QUOTES, 'UTF-8');

    $order_line_plain = 'Новое сообщение по заказу ' . (string) $order_id;
    $order_line_html = 'Новое сообщение по заказу ' . htmlspecialchars((string) $order_id, ENT_QUOTES, 'UTF-8');
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
                    CART_LANGUAGE
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

        $order_url = fn_url('orders.details?order_id=' . $order_id_int, 'C', 'current', CART_LANGUAGE, true);
        $order_url_html = htmlspecialchars($order_url, ENT_QUOTES, 'UTF-8');
    }

    $planfix_details = [
        $order_line_html,
        '- Кто написал: ' . htmlspecialchars($is_admin ? 'Администратор' : 'Клиент', ENT_QUOTES, 'UTF-8'),
        'Для ответа перейдите в админку ' . $http_host . ': <a href="' . $admin_url_html . '">URL</a>',
    ];

    $token = trim((string) Registry::get('addons.mwl_xlsx.telegram_bot_token'));
    $chat_id = trim((string) Registry::get('addons.mwl_xlsx.telegram_chat_id'));

    $message_author_telegram = fn_mwl_xlsx_get_user_telegram((int) ($last_message_user_id ?? 0));
    $customer_telegram_display = '';

    if ($message_author_telegram !== '') {
        $normalized_handle = ltrim($message_author_telegram, '@');
        $customer_telegram_display = '@' . $normalized_handle;
        $planfix_details[] = 'Telegram: ' . htmlspecialchars($customer_telegram_display, ENT_QUOTES, 'UTF-8');
    }

    if ($customer_email !== null && $customer_email !== '') {
        $planfix_details[] = 'Email: ' . htmlspecialchars((string) $customer_email, ENT_QUOTES, 'UTF-8');
    }

    if ($company !== null) {
        if (is_array($company)) {
            if (!empty($company['company'])) {
                $planfix_details[] = 'Компания: ' . htmlspecialchars((string) $company['company'], ENT_QUOTES, 'UTF-8');
            }
            if (!empty($company['email'])) {
                $planfix_details[] = 'Email компании: ' . htmlspecialchars((string) $company['email'], ENT_QUOTES, 'UTF-8');
            }
            if (!empty($company['phone'])) {
                $planfix_details[] = 'Телефон компании: ' . htmlspecialchars((string) $company['phone'], ENT_QUOTES, 'UTF-8');
            }
        } elseif (is_string($company) && $company !== '') {
            $planfix_details[] = 'Компания: ' . htmlspecialchars($company, ENT_QUOTES, 'UTF-8');
        }
    }

    $planfix_details = array_values(array_filter($planfix_details, static function ($line) {
        return $line !== '';
    }));

    $customer_message_intro_plain = $message_author_plain . ': ' . $message_body_plain;
    $customer_message_lines = [];
    $customer_message_lines[] = nl2br(htmlspecialchars($customer_message_intro_plain, ENT_QUOTES, 'UTF-8'), false);

    $admin_message_intro_html = $message_author_text . ': ' . $last_message_html;
    $admin_details_html = [];

    if ($order_line_html !== '') {
        $admin_details_html[] = $order_line_html;
    }

    $admin_details_html[] = 'Для ответа перейдите на <a href="' . $admin_url_html . '">страницу заказа ' . $http_host . '</a>';

    if ($customer_telegram_display !== '') {
        $admin_details_html[] = 'Telegram покупателя: ' . htmlspecialchars($customer_telegram_display, ENT_QUOTES, 'UTF-8');
    }

    $admin_message_parts_html = [$admin_message_intro_html];

    if ($admin_details_html) {
        $admin_message_parts_html[] = implode("\n", $admin_details_html);
    }

    $text_telegram = implode("\n\n", $admin_message_parts_html);

    $customer_details_lines = [];

    if ($order_url !== '') {
        $customer_details_lines[] = $order_line_html;
        $customer_details_lines[] = 'Для ответа перейдите на <a href="' . $order_url_html . '">страницу заказа ' . $http_host . '</a>';
    }

    if ($customer_details_lines) {
        $customer_message_lines[] = implode("\n", $customer_details_lines);
    }

    $customer_text_telegram = implode("\n\n", array_values(array_filter($customer_message_lines, static function ($line) {
        return $line !== '';
    })));

    $planfix_parts_html = [$admin_message_intro_html];

    if ($planfix_details) {
        $planfix_parts_html[] = '<span style="color:#888888;font-size:smaller;">' . implode('<br>', $planfix_details) . '</span>';
    }

    $planfix_message = implode('<br><br>', $planfix_parts_html);

    $customer_chat_id = '';

    if ($is_admin && $message_author_telegram !== '' && $token !== '') {
        $customer_chat_id = fn_mwl_xlsx_get_chat_id_by_username($token, $message_author_telegram);
    }

    $error_message = null;
    $url = '';

    if (!$is_admin) {
        if ($token !== '' && $chat_id !== '') {
            $url = "https://api.telegram.org/bot{$token}/sendMessage";
            $message_payload = [
                'chat_id'    => $chat_id,
                'text'       => $text_telegram,
                'parse_mode' => 'HTML',
            ];

            if ($admin_url !== '') {
                $message_payload['reply_markup'] = json_encode([
                    'inline_keyboard' => [
                        [
                            [
                                'text' => 'Ответить',
                                'url'  => $admin_url,
                            ],
                        ],
                    ],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }

            $resp_raw = Http::post($url, $message_payload, [
                'timeout'    => 10,
                'headers'    => [
                    'Content-Type' => 'application/x-www-form-urlencoded; charset=UTF-8',
                ],
                'log_pre'    => 'mwl_xlsx.telegram_vc_request',
                'log_result' => true,
            ]);

            $resp = $resp_raw ? json_decode($resp_raw, true) : null;
            if (empty($resp['ok'])) {
                $error_message = 'Failed to send Telegram notification for VC event: ' . print_r($resp, true);
                error_log($error_message);
            }
        } else {
            $error_message = 'Telegram bot token or chat_id not configured for VC notifications';
            error_log($error_message);
        }
    } elseif ($token !== '') {
        $url = "https://api.telegram.org/bot{$token}/sendMessage";
    }

    if ($error_message === null && $token !== '' && $customer_chat_id !== '' && $customer_chat_id !== $chat_id) {
        $customer_payload = [
            'chat_id'    => $customer_chat_id,
            'text'       => $customer_text_telegram !== '' ? $customer_text_telegram : $text_telegram,
            'parse_mode' => 'HTML',
        ];

        if ($order_url !== '') {
            $customer_payload['reply_markup'] = json_encode([
                'inline_keyboard' => [
                    [
                        [
                            'text' => 'Ответить',
                            'url'  => $order_url,
                        ],
                    ],
                ],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $customer_resp_raw = Http::post($url, $customer_payload, [
            'timeout'    => 10,
            'headers'    => [
                'Content-Type' => 'application/x-www-form-urlencoded; charset=UTF-8',
            ],
            'log_pre'    => 'mwl_xlsx.telegram_vc_customer',
            'log_result' => true,
        ]);

        $customer_resp = $customer_resp_raw ? json_decode($customer_resp_raw, true) : null;

        if (empty($customer_resp['ok'])) {
            error_log('Failed to send Telegram notification to customer: ' . print_r($customer_resp, true));
        }
    }

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