<?php
if (!defined('BOOTSTRAP')) { die('Access denied'); }

$vendor = __DIR__ . '/vendor/autoload.php';
if (file_exists($vendor)) {
    require_once $vendor;
}

fn_register_hooks(
    'auth_routines_post',
    'init_user_session_data_post'
);
