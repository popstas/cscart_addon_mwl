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
    'get_product_features_post',
    'init_templater_post',
    'change_order_status_post',
    'exim_import_images_pre'
);

Tygh::$app['event.transports.mwl'] = static function ($app) {
    return new MwlTransport();
};

function fn_mwl_xlsx_get_product_features_post(&$data, $params, $has_ungroupped) {
    // unset feature with value_int = -1.00
    foreach ($data as $key => $feature) {
        if (isset($feature['value_int']) && $feature['value_int'] === '-1.00') {
            unset($data[$key]);
        }
    }
}

function fn_mwl_xlsx_before_dispatch(&$controller, &$mode, &$action, &$dispatch_extra, &$area)
{
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    if (!$path) {
        return;
    }

    if (preg_match('~/(?:[a-z]{2}/)?media-lists/(\d+)/?$~i', $path, $m)) {
        $_REQUEST['dispatch'] = 'mwl_xlsx.view';
        $_REQUEST['list_id']  = (int) $m[1];

        $controller = 'mwl_xlsx';
        $mode       = 'view';
    }
}

function fn_mwl_xlsx_get_current_filters_post(&$params, $filters, $selected_filters, $area, $lang_code, $variant_values, &$range_values, $field_variant_values, $field_range_values)
{
    // Iterate through range values and set min to '0.00' where min < 0
    if (!empty($range_values) && is_array($range_values)) {
        foreach ($range_values as $filter_id => &$range) {
            if (isset($range['min']) && (float)$range['min'] < 0) {
                $range['min'] = '0.00';
            }
        }
        unset($range); // Break the reference
    }

    // unset($range_values[123]);
}

/* function fn_mwl_xlsx_get_product_filters_post(&$filters, $params, $lang_code)
{
    var_dump('get_product_filters_post');
    var_dump($filters);
    var_dump($params);
    var_dump($lang_code);
} */

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
