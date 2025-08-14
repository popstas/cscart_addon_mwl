<?php
if (!defined('BOOTSTRAP')) { die('Access denied'); }

// Добавляем наш шаблон в список шаблонов для блока "HTML с поддержкой Smarty" (smarty_block)
$schema['smarty_block']['addons/mwl_xlsx/blocks/media_lists_counter.tpl'] = [
    'name' => 'mwl_xlsx.block_media_lists_counter', // языковая переменная
    'settings' => [
        'link_url' => [
            'type' => 'input',
            'default_value' => 'media-lists', // можно указать dispatch или ЧПУ
        ],
        'show_zero' => [
            'type' => 'checkbox',
            'default_value' => 'N',
        ],
    ],
    'wrappers' => 'blocks/wrappers', // пусть будут стандартные обёртки
];

return $schema;
