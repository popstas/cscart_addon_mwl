<?php
if (!defined('BOOTSTRAP')) { die('Access denied'); }

fn_register_hooks(
    'auth_routines_post',
    'init_user_session_data_post',
    'before_dispatch',
    'init_templater_post'
);

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

/** Регистрируем {mwl_media_lists_count} как безопасную smarty-функцию */
function fn_mwl_xlsx_init_templater_post(&$view)
{
    $view->registerPlugin('function', 'mwl_media_lists_count', 'fn_mwl_xlsx_smarty_media_lists_count');
}