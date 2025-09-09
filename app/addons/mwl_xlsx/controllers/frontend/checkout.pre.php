<?php
if (!defined('BOOTSTRAP')) { die('Access denied'); }

// Валюта checkout'а — сделай настройкой, если нужно
$target_currency = 'USD';
fn_mwl_xlsx_switch_currency($target_currency, true);
