<?php
use Tygh\Registry;
use Tygh\Addons\MwlXlsx\Notifications\Transports\MwlTransport;

if (!defined('BOOTSTRAP')) { die('Access denied'); }

// Подключаем Composer autoloader с подавлением warnings от HTMLPurifier
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    $old_error_reporting = error_reporting();
    error_reporting($old_error_reporting & ~E_USER_WARNING);
    require_once __DIR__ . '/vendor/autoload.php';

    if (class_exists('HTMLPurifier_ConfigSchema')) {
        $schema = HTMLPurifier_ConfigSchema::instance();
        if ($schema && is_array($schema->info)) {
            $directive_key = 'Core.RemoveBlanks';
            if (!isset($schema->info[$directive_key])) {
                $schema->add($directive_key, false, 'bool', false);
            }
        }
    }

    error_reporting($old_error_reporting);
}

fn_register_hooks(
    // 'auth_routines_post',
    // 'init_user_session_data_post',
    'before_dispatch',
    'get_product_filter_fields',
    // 'get_product_filters_post',
    'get_current_filters_post',
    'get_product_features',
    'get_product_features_post',
    'dispatch_assign_template',
    'dispatch_before_display',
    'init_templater_post',
    'change_order_status_post',
    'exim_import_images_pre',
    'update_image',
    'variation_group_add_products_to_group',
    'variation_group_save_group',
    'update_product_features_value_pre',
    'import_post',
    'get_order_items_info_post',
    'update_profile'
);

Tygh::$app['event.transports.mwl'] = static function ($app) {
    return new MwlTransport();
};

function fn_mwl_xlsx_dispatch_assign_template($controller, $mode, $area, $controllers_cascade)
{
    if ($area !== 'C' || $controller !== 'index' || $mode !== 'index') {
        return;
    }

    // Logged-in users should see the normal storefront, not the static landing page
    if (!empty(Tygh::$app['session']['auth']['user_id'])) {
        return;
    }

    $lang = defined('CART_LANGUAGE') ? CART_LANGUAGE : 'en';
    $url = trim((string) Registry::get('addons.mwl_xlsx.mainpage_replace_url_' . $lang));
    if (empty($url)) {
        return;
    }

    $replace_mode = (string) Registry::get('addons.mwl_xlsx.mainpage_replace_mode');
    if ($replace_mode === 'mainpage_redirect') {
        header('Location: ' . $url, true, 302);
        exit;
    }

    $mainpage_file = fn_mwl_xlsx_mainpage_replace_dir($lang) . 'index.html';
    if (!file_exists($mainpage_file)) {
        return;
    }

    // Output directly bypassing Smarty (which strips <script> tags)
    $html = file_get_contents($mainpage_file);
    $html = fn_mwl_xlsx_rewrite_mainpage_paths($html, $url, $lang);
    // Cache-bust local assets using file modification time
    $html = str_replace('?rnd=', '?rnd=' . filemtime($mainpage_file) . '_', $html);
    echo $html;
    exit;
}

function fn_mwl_xlsx_get_product_features_post(&$data, $params, $has_ungroupped) {
    // unset feature with value_int = -1.00 at product page
    foreach ($data as $key => $feature) {
        if (isset($feature['value_int']) && $feature['value_int'] === '-1.00') {
            unset($data[$key]);
        }
    }
}

// Helper function to replace variant = '-1' with '?' in variation_features_variants
function fn_mwl_xlsx_replace_variant_minus_one(&$product)
{
    if (empty($product) || empty($product['variation_features_variants'])) {
        return;
    }

    $hide_features = fn_mwl_xlsx_should_hide_features();
    $hidden_feature_ids = fn_mwl_xlsx_get_hidden_feature_ids();

    // Replace variant = '-1' with '?' in variation_features_variants
    foreach ($product['variation_features_variants'] as $feature_id => &$feature) {
        if (empty($feature['variants'])) {
            continue;
        }

        // Hide feature if it is related to hidden features
        if ($hide_features && in_array($feature_id, $hidden_feature_ids)) {
            $feature['variants'] = [];
            continue;
        }

        foreach ($feature['variants'] as $variant_id => &$variant) {
            // Replace variant if its value is '-1'
            if (isset($variant['variant']) && $variant['variant'] === '-1') {
                $variant['variant'] = '?';
            }
        }
    }
    unset($feature);
}

// replace variant = '-1' with '?' in variations select at product page
// For cart page, replacement is done in template: product_options.pre.tpl
function fn_mwl_xlsx_dispatch_before_display()
{
    // Backoffice: on profiles add, pass usergroups so the form can show a select
    if (defined('AREA') && AREA === 'A'
        && Registry::get('runtime.controller') === 'profiles'
        && Registry::get('runtime.mode') === 'add'
    ) {
        $user_type = !empty($_REQUEST['user_type']) ? $_REQUEST['user_type'] : 'C';
        $usergroups = fn_get_available_usergroups($user_type);
        Tygh::$app['view']->assign('usergroups', $usergroups);
        return;
    }

    // Only process product pages in frontend
    if (Registry::get('runtime.controller') !== 'products' || Registry::get('runtime.mode') !== 'view') {
        return;
    }

    /** @var \Tygh\SmartyEngine\Core $view */
    $view = Tygh::$app['view'];
    
    $product = $view->getTemplateVars('product');
    
    if (empty($product) || empty($product['variation_features_variants'])) {
        return;
    }

    fn_mwl_xlsx_replace_variant_minus_one($product);

    // Update the product variable in Smarty
    $view->assign('product', $product);
}

function fn_mwl_xlsx_before_dispatch(&$controller, &$mode, &$action, &$dispatch_extra, &$area)
{
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    if (!$path) {
        return;
    }

    // After login, redirect to /expertizeme/ instead of the storefront index
    if ($area === 'C' && $controller === 'auth' && $mode === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (empty($_REQUEST['return_url']) || strpos($_REQUEST['return_url'], 'auth.login_form') !== false) {
            $_REQUEST['return_url'] = '/expertizeme/';
        }
    }

    // Proxy /-/x-api/ requests to the mainpage source domain (form submissions, captcha, etc.)
    if (strpos($path, '/-/x-api/') === 0) {
        fn_mwl_xlsx_proxy_api_request($path);
        // never returns
    }

    if (preg_match('~/(?:[a-z]{2}/)?media-lists/(\d+)/?$~i', $path, $m)) {
        $_REQUEST['dispatch'] = 'mwl_xlsx.view';
        $_REQUEST['list_id']  = (int) $m[1];

        $controller = 'mwl_xlsx';
        $mode       = 'view';
    }
}

function fn_mwl_xlsx_get_current_filters_post($params, $filters, $selected_filters, $area, $lang_code, &$variant_values, &$range_values, $field_variant_values, $field_range_values)
{
    // Hide filters related to hidden features, clear their variant_values
    if (fn_mwl_xlsx_should_hide_features()) {
        $feature_ids = fn_mwl_xlsx_get_hidden_feature_ids();
        if (!empty($feature_ids) && is_array($variant_values)) {
            foreach ($filters as $filter_id => $filter_data) {
                if (isset($filter_data['feature_id']) && in_array($filter_data['feature_id'], $feature_ids)) {
                    $variant_values[$filter_id] = [];
                }
            }
        }
    }

    // Iterate through range values and set min to '0.00' where min < 0
    if (!empty($range_values) && is_array($range_values)) {
        foreach ($range_values as $filter_id => &$range) {
            if (isset($range['min']) && (float)$range['min'] < 0) {
                $range['min'] = '0.00';
            }
        }
        unset($range); // Break the reference
    }
}

// Remove price filter if user can't access prices, removes from results, not from form
function fn_mwl_xlsx_get_product_filter_fields(&$filters)
{
    // return if user can access prices
    $auth = Tygh::$app['session']['auth'] ?? [];
    if (fn_mwl_xlsx_access_service()->canViewPrice($auth)) {
        return;
    }

    unset($filters['P']);
}

function fn_mwl_xlsx_init_templater_post(&$view)
{
    // Регистрируем {mwl_media_lists_count} как безопасную smarty-функцию
    $view->registerPlugin('function', 'mwl_media_lists_count', 'fn_mwl_xlsx_smarty_media_lists_count');

    // Регистрируем модификатор shortnum для компактного отображения чисел
    $view->registerPlugin('modifier', 'shortnum', 'fn_mwl_xlsx_smarty_modifier_shortnum');

    // override date format for RU
    $lang = defined('CART_LANGUAGE') ? CART_LANGUAGE : (Registry::get('runtime.language') ?: 'en');
    if ($lang === 'ru') {
        Registry::set('settings.Appearance.date_format', '%d.%m.%Y');
        $view->assign('settings', Registry::get('settings'));
    }
}

/**
 * Smarty modifier for compact number display
 * 275435920 -> "275 млн." (ru) / "275 M" (en)
 */
function fn_mwl_xlsx_smarty_modifier_shortnum($n) {
    $n = floatval($n);
    $t = __('mwl_xlsx.shortnum_trillion'); // " трлн." / " T"
    $b = __('mwl_xlsx.shortnum_billion');  // " млрд." / " B"
    $m = __('mwl_xlsx.shortnum_million');  // " млн." / " M"
    $k = __('mwl_xlsx.shortnum_thousand'); // " тыс." / " K"

    if (abs($n) >= 1e12) return floor($n / 1e12) . $t;
    if (abs($n) >= 1e9)  return floor($n / 1e9)  . $b;
    if (abs($n) >= 1e6)  return floor($n / 1e6)  . $m;
    if (abs($n) >= 1e3)  return floor($n / 1e3)  . $k;
    return (string) intval($n);
}

function fn_mwl_xlsx_get_order_items_info_post(&$order, $v, $k)
{
    // Replace product name with full variation name using feature names
    if (empty($order['products'][$k]['product_id'])) {
        return;
    }

    $product_id = $order['products'][$k]['product_id'];
    $lang_code = !empty($order['lang_code']) ? $order['lang_code'] : CART_LANGUAGE;
    
    // Get full variation name with feature names
    $full_name = fn_mwl_xlsx_get_product_variations_name_full($product_id, $lang_code);
    
    if (!empty($full_name)) {
        $order['products'][$k]['product'] = $full_name;
    }
}

/**
 * After profile create: assign selected usergroup and/or send password setup link.
 */
function fn_mwl_xlsx_update_profile($action, $user_data, $current_user_data)
{
    if ($action !== 'add' || empty($user_data['user_id'])) {
        return;
    }

    $user_id = (int) $user_data['user_id'];

    // Assign usergroup if one was selected on add form
    $usergroup_id = isset($_REQUEST['user_data']['usergroup_id']) ? (int) $_REQUEST['user_data']['usergroup_id'] : 0;
    if ($usergroup_id > 0) {
        fn_change_usergroup_status('A', $user_id, $usergroup_id, []);
    }

    // Send password setup link if checkbox was checked (7 days TTL, same as bulk invite)
    if (!empty($_REQUEST['send_password_setup_link']) && !empty($user_data['email'])) {
        $ttl = defined('SECONDS_IN_DAY') ? 7 * SECONDS_IN_DAY : 7 * 24 * 60 * 60;
        $ekey = fn_generate_ekey($user_id, RECOVERY_PASSWORD_EKEY_TYPE, $ttl);
        if ($ekey) {
            /** @var \Tygh\Notifications\EventDispatcher $event_dispatcher */
            $event_dispatcher = Tygh::$app['event.dispatcher'];
            /** @var \Tygh\Storefront\Storefront $storefront */
            $storefront = Tygh::$app['storefront'];

            $event_dispatcher->dispatch(
                'profile.password_recover.' . strtolower($user_data['user_type']),
                [
                    'user_data'     => $user_data,
                    'ekey'          => $ekey,
                    'storefront_id' => $storefront->storefront_id,
                ]
            );
        }
    }
}
