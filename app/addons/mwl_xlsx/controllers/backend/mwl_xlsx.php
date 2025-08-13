<?php
use Tygh\Registry;

if (!defined('BOOTSTRAP')) { die('Access denied'); }

if ($mode === 'dev_reload_langs') {
    // импортирует var/langs/*/addons/mwl_xlsx.po в БД
    fn_install_addon_languages('mwl_xlsx', false);
    fn_clear_cache(); // чтобы сразу увидеть обновления
    return [CONTROLLER_STATUS_OK, 'addons.update?addon=mwl_xlsx'];
}
