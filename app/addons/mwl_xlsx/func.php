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
use Tygh\Addons\MwlXlsx\Service\SettingsBackup;
use Tygh\Addons\MwlXlsx\Telegram\TelegramService;
use Tygh\Registry;
use Tygh\Storage;
use Tygh\Tygh;
use Google\Service\Sheets;
use Google\Service\Sheets\ValueRange;
use Google\Service\Sheets\BatchUpdateSpreadsheetRequest;
use Stripe\Checkout\Session as StripeCheckoutSession;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Stripe;
use Stripe\Webhook;

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

function fn_mwl_wallet_get_balance(int $user_id): array
{
    $user_id = (int) $user_id;

    if ($user_id <= 0) {
        return [
            'balance'  => 0.0,
            'currency' => (string) CART_PRIMARY_CURRENCY,
        ];
    }

    $db = Tygh::$app['db'];
    $row = $db->getRow('SELECT balance, currency FROM ?:mwl_wallets WHERE user_id = ?i', $user_id);

    if (!$row) {
        return [
            'balance'  => 0.0,
            'currency' => (string) CART_PRIMARY_CURRENCY,
        ];
    }

    return [
        'balance'  => (float) $row['balance'],
        'currency' => (string) ($row['currency'] ?: CART_PRIMARY_CURRENCY),
    ];
}

function fn_mwl_wallet_get_transaction(int $txn_id): ?array
{
    $txn_id = (int) $txn_id;

    if ($txn_id <= 0) {
        return null;
    }

    $row = Tygh::$app['db']->getRow('SELECT * FROM ?:mwl_wallet_transactions WHERE txn_id = ?i', $txn_id);

    if (!$row) {
        return null;
    }

    $row['amount'] = (float) $row['amount'];
    $row['meta'] = fn_mwl_wallet_decode_meta($row['meta']);

    return $row;
}

function fn_mwl_wallet_get_transactions(int $user_id, array $params = []): array
{
    $user_id = (int) $user_id;

    if ($user_id <= 0) {
        return [];
    }

    $limit = isset($params['limit']) ? max(1, (int) $params['limit']) : 20;
    $offset = isset($params['offset']) ? max(0, (int) $params['offset']) : 0;

    $rows = Tygh::$app['db']->getArray(
        'SELECT txn_id, user_id, type, amount, currency, status, source, external_id, meta, created_at, updated_at'
        . ' FROM ?:mwl_wallet_transactions WHERE user_id = ?i ORDER BY created_at DESC, txn_id DESC LIMIT ?i OFFSET ?i',
        $user_id,
        $limit,
        $offset
    );

    foreach ($rows as &$row) {
        $row['amount'] = (float) $row['amount'];
        $row['meta'] = fn_mwl_wallet_decode_meta($row['meta']);
    }

    unset($row);

    return $rows;
}

function fn_mwl_wallet_decode_meta($meta): array
{
    if (is_array($meta)) {
        return $meta;
    }

    if ($meta === null || $meta === '') {
        return [];
    }

    $decoded = json_decode((string) $meta, true);

    return is_array($decoded) ? $decoded : [];
}

function fn_mwl_wallet_encode_meta(?array $meta): ?string
{
    if (empty($meta)) {
        return null;
    }

    return json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function fn_mwl_wallet_get_allowed_currencies(): array
{
    $setting = (string) Registry::get('addons.mwl_xlsx.allowed_currencies');
    $codes = [];

    if ($setting !== '') {
        $parts = preg_split('/[\s,]+/', $setting, -1, PREG_SPLIT_NO_EMPTY);

        foreach ($parts as $part) {
            $codes[] = strtoupper($part);
        }
    }

    if (!$codes) {
        $codes[] = (string) CART_PRIMARY_CURRENCY;
    }

    $available = Registry::get('currencies');

    $codes = array_values(array_filter(array_unique($codes), static function ($code) use ($available) {
        return isset($available[$code]);
    }));

    if (!$codes) {
        $codes[] = (string) CART_PRIMARY_CURRENCY;
    }

    return $codes;
}

function fn_mwl_wallet_get_limits(): array
{
    $min = (float) Registry::get('addons.mwl_xlsx.topup_min');
    $max = (float) Registry::get('addons.mwl_xlsx.topup_max');

    if ($min < 0) {
        $min = 0.0;
    }

    if ($max > 0 && $max < $min) {
        $max = $min;
    }

    return [
        'min' => round($min, 2),
        'max' => $max > 0 ? round($max, 2) : 0.0,
    ];
}

function fn_mwl_wallet_calculate_fee(float $amount): array
{
    $percent = (float) Registry::get('addons.mwl_xlsx.fee_percent');
    $fixed = (float) Registry::get('addons.mwl_xlsx.fee_fixed');

    $percent_fee = $amount * ($percent / 100);
    $fee = $percent_fee + $fixed;

    if ($fee < 0) {
        $fee = 0.0;
    }

    $fee = round($fee, 2);
    $net = round($amount - $fee, 2);

    if ($net < 0) {
        $net = 0.0;
    }

    return [
        'fee' => $fee,
        'net' => $net,
    ];
}

function fn_mwl_wallet_normalize_currency(string $currency): string
{
    $currency = strtoupper(trim($currency));

    if ($currency === '') {
        $currency = (string) CART_PRIMARY_CURRENCY;
    }

    return $currency;
}

function fn_mwl_wallet_change_balance(int $user_id, float $delta, string $currency, array $txn_data): int
{
    $user_id = (int) $user_id;

    if ($user_id <= 0) {
        return 0;
    }

    $currency = fn_mwl_wallet_normalize_currency($currency);
    $delta = round($delta, 2);
    $now = TIME;
    $db = Tygh::$app['db'];

    if ($delta !== 0.0) {
        $db->query(
            'INSERT INTO ?:mwl_wallets (user_id, currency, balance, updated_at) VALUES (?i, ?s, ?d, ?i)'
            . ' ON DUPLICATE KEY UPDATE balance = balance + ?d, currency = ?s, updated_at = ?i',
            $user_id,
            $currency,
            $delta,
            $now,
            $delta,
            $currency,
            $now
        );
    } else {
        $db->query(
            'INSERT INTO ?:mwl_wallets (user_id, currency, balance, updated_at) VALUES (?i, ?s, 0, ?i)'
            . ' ON DUPLICATE KEY UPDATE currency = VALUES(currency), updated_at = VALUES(updated_at)',
            $user_id,
            $currency,
            $now
        );
    }

    $meta = isset($txn_data['meta']) && is_array($txn_data['meta']) ? $txn_data['meta'] : [];

    if (!empty($txn_data['txn_id'])) {
        $existing = fn_mwl_wallet_get_transaction((int) $txn_data['txn_id']);
        if ($existing && !empty($existing['meta'])) {
            $meta = array_merge($existing['meta'], $meta);
        }
    }

    $encoded_meta = fn_mwl_wallet_encode_meta($meta);

    $record = [
        'type'        => (string) ($txn_data['type'] ?? 'adjust'),
        'amount'      => $delta,
        'currency'    => $currency,
        'status'      => (string) ($txn_data['status'] ?? 'pending'),
        'source'      => (string) ($txn_data['source'] ?? 'manual'),
        'external_id' => isset($txn_data['external_id']) ? (string) $txn_data['external_id'] : null,
        'meta'        => $encoded_meta,
        'updated_at'  => $now,
    ];

    if (!empty($txn_data['txn_id'])) {
        $txn_id = (int) $txn_data['txn_id'];
        $db->query('UPDATE ?:mwl_wallet_transactions SET ?u WHERE txn_id = ?i', $record, $txn_id);
    } else {
        $record['user_id'] = $user_id;
        $record['created_at'] = $now;
        $db->query('INSERT INTO ?:mwl_wallet_transactions ?e', $record);
        $txn_id = (int) $db->insertId();
    }

    return $txn_id;
}

function fn_mwl_wallet_update_transaction(int $txn_id, array $fields): void
{
    $txn_id = (int) $txn_id;

    if ($txn_id <= 0 || !$fields) {
        return;
    }

    if (isset($fields['meta']) && is_array($fields['meta'])) {
        $current = fn_mwl_wallet_get_transaction($txn_id);
        $meta = $current['meta'] ?? [];
        $fields['meta'] = fn_mwl_wallet_encode_meta(array_merge($meta, $fields['meta']));
    }

    $fields['updated_at'] = TIME;

    Tygh::$app['db']->query('UPDATE ?:mwl_wallet_transactions SET ?u WHERE txn_id = ?i', $fields, $txn_id);
}

function fn_mwl_wallet_require_balance(int $user_id, float $amount, string $currency): bool
{
    $balance = fn_mwl_wallet_get_balance($user_id);
    $wallet_currency = $balance['currency'];
    $amount_in_wallet_currency = fn_mwl_wallet_convert_amount($amount, $currency, $wallet_currency);

    return $balance['balance'] >= $amount_in_wallet_currency;
}

function fn_mwl_wallet_convert_amount(float $amount, string $from_currency, string $to_currency): float
{
    $from_currency = fn_mwl_wallet_normalize_currency($from_currency);
    $to_currency = fn_mwl_wallet_normalize_currency($to_currency);

    if ($from_currency === $to_currency) {
        return round($amount, 2);
    }

    $currencies = Registry::get('currencies');

    if (!isset($currencies[$from_currency], $currencies[$to_currency])) {
        return round($amount, 2);
    }

    $from = $currencies[$from_currency];
    $to = $currencies[$to_currency];

    $from_coeff = (float) ($from['coefficient'] ?? 1.0);
    $to_coeff = (float) ($to['coefficient'] ?? 1.0);

    if ($from_coeff == 0.0) {
        $from_coeff = 1.0;
    }

    $base_amount = $amount / $from_coeff;
    $converted = $base_amount * $to_coeff;

    return round($converted, 2);
}

function fn_mwl_wallet_validate_topup_amount(float $amount, string $currency, ?string &$error = null): bool
{
    $amount = round($amount, 2);

    if ($amount <= 0) {
        $error = __('mwl_wallet.error_invalid_amount');
        return false;
    }

    $currency = fn_mwl_wallet_normalize_currency($currency);
    $limits = fn_mwl_wallet_get_limits();

    if ($amount < $limits['min']) {
        $error = __('mwl_wallet.error_min_amount', [
            '[amount]'   => fn_format_price($limits['min'], $currency, null, false),
            '[currency]' => $currency,
        ]);
        return false;
    }

    if ($limits['max'] > 0 && $amount > $limits['max']) {
        $error = __('mwl_wallet.error_max_amount', [
            '[amount]'   => fn_format_price($limits['max'], $currency, null, false),
            '[currency]' => $currency,
        ]);
        return false;
    }

    $allowed = fn_mwl_wallet_get_allowed_currencies();

    if (!in_array($currency, $allowed, true)) {
        $error = __('mwl_wallet.error_currency_not_allowed', [
            '[currency]' => $currency,
        ]);
        return false;
    }

    $error = null;

    return true;
}

function fn_mwl_wallet_create_checkout_session(int $user_id, float $amount, string $currency): string
{
    $user_id = (int) $user_id;

    if ($user_id <= 0) {
        throw new \InvalidArgumentException('User ID is required');
    }

    $currency = fn_mwl_wallet_normalize_currency($currency);
    $amount = round($amount, 2);

    $secret_key = (string) Registry::get('addons.mwl_xlsx.stripe_secret_key');

    if ($secret_key === '') {
        throw new \RuntimeException(__('mwl_wallet.error_stripe_not_configured'));
    }

    $fees = fn_mwl_wallet_calculate_fee($amount);

    if ($fees['net'] <= 0.0) {
        throw new \RuntimeException(__('mwl_wallet.error_amount_after_fees'));
    }

    fn_mwl_xlsx_load_vendor_autoloader();
    Stripe::setApiKey($secret_key);

    $db = Tygh::$app['db'];
    $now = TIME;
    $meta = [
        'gross_amount' => $amount,
        'fee_amount'   => $fees['fee'],
        'net_amount'   => $fees['net'],
        'stripe_mode'  => (string) Registry::get('addons.mwl_xlsx.stripe_mode'),
    ];

    $db->query(
        'INSERT INTO ?:mwl_wallet_transactions (user_id, type, amount, currency, status, source, external_id, meta, created_at, updated_at)'
        . ' VALUES (?i, ?s, ?d, ?s, ?s, ?s, ?s, ?s, ?i, ?i)',
        $user_id,
        'topup',
        $fees['net'],
        $currency,
        'pending',
        'stripe',
        '',
        fn_mwl_wallet_encode_meta($meta),
        $now,
        $now
    );

    $txn_id = (int) $db->insertId();

    $success_url = fn_url('mwl_wallet.success?txn_id=' . $txn_id, 'C', 'http');
    $cancel_url = fn_url('mwl_wallet.cancel?txn_id=' . $txn_id, 'C', 'http');

    try {
        $session = StripeCheckoutSession::create([
            'mode' => 'payment',
            'payment_method_types' => ['card'],
            'client_reference_id' => $user_id . ':' . $txn_id,
            'line_items' => [[
                'price_data' => [
                    'currency' => strtolower($currency),
                    'product_data' => ['name' => __('mwl_wallet.checkout_product_name')],
                    'unit_amount' => (int) round($amount * 100),
                ],
                'quantity' => 1,
            ]],
            'metadata' => [
                'user_id' => (string) $user_id,
                'txn_id'  => (string) $txn_id,
            ],
            'success_url' => $success_url,
            'cancel_url'  => $cancel_url,
        ]);
    } catch (ApiErrorException $exception) {
        fn_mwl_wallet_update_transaction($txn_id, [
            'status' => 'failed',
            'meta'   => ['error' => $exception->getMessage()],
        ]);

        throw $exception;
    }

    fn_mwl_wallet_update_transaction($txn_id, [
        'external_id' => $session->id,
        'meta'        => ['stripe_session_id' => $session->id],
    ]);

    return $session->url;
}

function fn_mwl_wallet_get_transaction_by_external(string $source, string $external_id): ?array
{
    $row = Tygh::$app['db']->getRow(
        'SELECT * FROM ?:mwl_wallet_transactions WHERE source = ?s AND external_id = ?s ORDER BY txn_id ASC LIMIT 1',
        $source,
        $external_id
    );

    if (!$row) {
        return null;
    }

    $row['amount'] = (float) $row['amount'];
    $row['meta'] = fn_mwl_wallet_decode_meta($row['meta']);

    return $row;
}

function fn_mwl_wallet_handle_webhook(): void
{
    fn_mwl_xlsx_load_vendor_autoloader();

    $payload = file_get_contents('php://input');
    $signature = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
    $secret = (string) Registry::get('addons.mwl_xlsx.stripe_webhook_secret');

    if ($secret === '') {
        http_response_code(400);
        echo 'Webhook secret not configured';
        exit;
    }

    try {
        $event = Webhook::constructEvent($payload, $signature, $secret);
    } catch (SignatureVerificationException $exception) {
        http_response_code(400);
        echo 'Invalid signature';
        exit;
    } catch (\UnexpectedValueException $exception) {
        http_response_code(400);
        echo 'Invalid payload';
        exit;
    }

    $type = (string) $event->type;

    switch ($type) {
        case 'checkout.session.completed':
            fn_mwl_wallet_process_checkout_completed($event);
            break;

        case 'checkout.session.async_payment_failed':
        case 'checkout.session.expired':
            fn_mwl_wallet_process_checkout_failed($event);
            break;

        case 'charge.refunded':
            fn_mwl_wallet_process_charge_refunded($event);
            break;

        case 'charge.dispute.funds_withdrawn':
            fn_mwl_wallet_process_dispute_withdrawn($event);
            break;

        default:
            // ignore other events
            break;
    }

    http_response_code(200);
    echo 'ok';
    exit;
}

function fn_mwl_wallet_process_checkout_completed($event): void
{
    $session = $event->data->object;
    $metadata = $session->metadata ?? new stdClass();
    $txn_id = isset($metadata->txn_id) ? (int) $metadata->txn_id : 0;
    $user_id = isset($metadata->user_id) ? (int) $metadata->user_id : 0;

    if (!$txn_id || !$user_id || $session->payment_status !== 'paid') {
        return;
    }

    $transaction = fn_mwl_wallet_get_transaction($txn_id);

    if (!$transaction || (int) $transaction['user_id'] !== $user_id) {
        return;
    }

    if ($transaction['status'] === 'succeeded') {
        return;
    }

    $meta = $transaction['meta'];
    $meta['stripe_session_id'] = (string) $session->id;
    if (!empty($session->payment_intent)) {
        $meta['payment_intent'] = (string) $session->payment_intent;
    }
    if (!empty($session->customer)) {
        $meta['customer'] = (string) $session->customer;
    }
    $meta['event'] = (string) $event->type;

    $external_id = !empty($session->payment_intent) ? (string) $session->payment_intent : (string) $session->id;

    fn_mwl_wallet_change_balance($user_id, (float) $transaction['amount'], $transaction['currency'], [
        'txn_id'      => $txn_id,
        'type'        => 'topup',
        'status'      => 'succeeded',
        'source'      => 'stripe',
        'external_id' => $external_id,
        'meta'        => $meta,
    ]);
}

function fn_mwl_wallet_process_checkout_failed($event): void
{
    $session = $event->data->object;
    $metadata = $session->metadata ?? new stdClass();
    $txn_id = isset($metadata->txn_id) ? (int) $metadata->txn_id : 0;

    if (!$txn_id) {
        return;
    }

    $transaction = fn_mwl_wallet_get_transaction($txn_id);

    if (!$transaction || $transaction['status'] !== 'pending') {
        return;
    }

    $meta = $transaction['meta'];
    $meta['event'] = (string) $event->type;
    $meta['stripe_session_id'] = (string) $session->id;

    fn_mwl_wallet_update_transaction($txn_id, [
        'status'      => 'failed',
        'external_id' => $transaction['external_id'] ?: (string) $session->id,
        'meta'        => $meta,
    ]);
}

function fn_mwl_wallet_process_charge_refunded($event): void
{
    $charge = $event->data->object;
    $payment_intent = isset($charge->payment_intent) ? (string) $charge->payment_intent : '';

    if ($payment_intent === '') {
        return;
    }

    $transaction = fn_mwl_wallet_get_transaction_by_external('stripe', $payment_intent);

    if (!$transaction || $transaction['status'] !== 'succeeded') {
        return;
    }

    $refunds = $charge->refunds->data ?? [];

    foreach ($refunds as $refund) {
        $refund_id = isset($refund->id) ? (string) $refund->id : '';

        if ($refund_id === '') {
            continue;
        }

        if (fn_mwl_wallet_get_transaction_by_external('stripe', $refund_id)) {
            continue;
        }

        $status = isset($refund->status) ? (string) $refund->status : 'succeeded';

        if ($status !== 'succeeded') {
            continue;
        }

        $amount_cents = isset($refund->amount) ? (int) $refund->amount : 0;

        if ($amount_cents <= 0) {
            continue;
        }

        $amount = round($amount_cents / 100, 2);

        fn_mwl_wallet_change_balance((int) $transaction['user_id'], -$amount, $transaction['currency'], [
            'type'        => 'refund',
            'status'      => 'succeeded',
            'source'      => 'stripe',
            'external_id' => $refund_id,
            'meta'        => [
                'event'          => (string) $event->type,
                'payment_intent' => $payment_intent,
                'charge_id'      => (string) $charge->id,
                'reason'         => isset($refund->reason) ? (string) $refund->reason : '',
            ],
        ]);
    }
}

function fn_mwl_wallet_process_dispute_withdrawn($event): void
{
    $dispute = $event->data->object;
    $payment_intent = isset($dispute->payment_intent) ? (string) $dispute->payment_intent : '';

    if ($payment_intent === '') {
        return;
    }

    $transaction = fn_mwl_wallet_get_transaction_by_external('stripe', $payment_intent);

    if (!$transaction) {
        return;
    }

    $amount_cents = isset($dispute->amount) ? (int) $dispute->amount : 0;

    if ($amount_cents <= 0) {
        return;
    }

    $amount = round($amount_cents / 100, 2);

    $external_id = isset($dispute->id) ? (string) $dispute->id : 'dispute-' . $payment_intent;

    if (fn_mwl_wallet_get_transaction_by_external('stripe', $external_id)) {
        return;
    }

    fn_mwl_wallet_change_balance((int) $transaction['user_id'], -$amount, $transaction['currency'], [
        'type'        => 'refund',
        'status'      => 'reversed',
        'source'      => 'stripe',
        'external_id' => $external_id,
        'meta'        => [
            'event'          => (string) $event->type,
            'payment_intent' => $payment_intent,
            'reason'         => isset($dispute->reason) ? (string) $dispute->reason : '',
        ],
    ]);
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