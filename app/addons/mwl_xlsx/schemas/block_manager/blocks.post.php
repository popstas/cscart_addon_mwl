<?php
if (!defined('BOOTSTRAP')) { die('Access denied'); }

$schema['mwl_media_lists_counter'] = [
    'name' => 'mwl_xlsx.block_media_lists_counter',
    'content' => [
        'media_lists_count' => [
            'type' => 'function',
            'function' => ['fn_mwl_xlsx_get_media_lists_count'],
        ],
    ],
    'settings' => [
        'link_url' => [
            'type' => 'input',
            'default_value' => 'media-lists',
        ],
        'show_zero' => [
            'type' => 'checkbox',
            'default_value' => 'N',
        ],
    ],
    'templates' => 'addons/mwl_xlsx/blocks/media_lists_counter.tpl',
    'wrappers' => 'blocks/wrappers',
];

return $schema;
