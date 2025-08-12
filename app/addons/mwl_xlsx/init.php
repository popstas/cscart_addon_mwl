<?php
if (!defined('BOOTSTRAP')) { die('Access denied'); }

fn_register_hooks(
    'auth_routines_post',
    'init_user_session_data_post'
);
