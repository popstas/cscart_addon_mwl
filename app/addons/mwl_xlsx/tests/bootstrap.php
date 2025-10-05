<?php

require __DIR__ . '/../vendor/autoload.php';

if (!class_exists('Tygh\\Registry')) {
    class_alias(Tygh\Addons\MwlXlsx\Tests\Support\RegistryStub::class, 'Tygh\\Registry');
}

if (!class_exists('Tygh\\Tygh')) {
    class_alias(Tygh\Addons\MwlXlsx\Tests\Support\TyghStub::class, 'Tygh\\Tygh');
}

if (!isset(\Tygh\Tygh::$app)) {
    \Tygh\Tygh::$app = new \ArrayObject();
}

if (!defined('TIME')) {
    define('TIME', 1700000000);
}

if (!defined('CART_LANGUAGE')) {
    define('CART_LANGUAGE', 'en');
}

if (!function_exists('__')) {
    function __($key, array $params = [], $lang_code = null)
    {
        foreach ($params as $placeholder => $value) {
            $key = str_replace($placeholder, (string) $value, $key);
        }

        return $key;
    }
}

if (!function_exists('fn_url')) {
    function fn_url($url, $area = 'A', $protocol = 'current', $lang_code = CART_LANGUAGE, $get_relative = true)
    {
        return 'https://example.com/' . ltrim($url, '/');
    }
}

if (!function_exists('fn_get_order_info')) {
    function fn_get_order_info($order_id)
    {
        return [];
    }
}

if (!function_exists('fn_get_user_info')) {
    function fn_get_user_info($user_id)
    {
        return [];
    }
}

if (!function_exists('fn_change_order_status')) {
    function fn_change_order_status($order_id, $status_to, $status_from)
    {
        return true;
    }
}

if (!function_exists('db_get_field')) {
    function db_get_field($query, ...$params)
    {
        return '';
    }
}

if (!function_exists('db_query')) {
    function db_query($query, ...$params)
    {
        return 0;
    }
}

if (!function_exists('db_get_row')) {
    function db_get_row($query, ...$params)
    {
        return [];
    }
}

if (!function_exists('db_get_array')) {
    function db_get_array($query, ...$params)
    {
        return [];
    }
}

if (!function_exists('db_get_hash_array')) {
    function db_get_hash_array($query, ...$params)
    {
        return [];
    }
}

if (!function_exists('db_get_fields')) {
    function db_get_fields($query, ...$params)
    {
        return [];
    }
}

if (!function_exists('fn_format_order_id')) {
    function fn_format_order_id($order_id)
    {
        return (string) $order_id;
    }
}

if (!function_exists('fn_mwl_planfix_format_order_id')) {
    function fn_mwl_planfix_format_order_id($order_id)
    {
        return (string) $order_id;
    }
}
