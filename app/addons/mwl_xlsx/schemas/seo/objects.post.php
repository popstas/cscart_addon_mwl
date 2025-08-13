<?php
$schema = $schema ?? [];

// $schema['/media-lists'] = [
//     'dispatch' => 'mwl_xlsx.manage',
// ];

/* $schema['mwl_xlsx'] = [
    // какой диспетчер отдаёт страницу объекта
    'dispatch' => 'mwl_xlsx.view',

    // имя GET-параметра ID
    'item'     => 'list_id',

    // базовый сегмент URL
    'path'     => 'media-lists',

    // откуда брать название для слага (можно опустить, тогда SEO имя сгенерится по умолчанию)
    'name'     => [
        'table' => '?:mwl_xlsx_lists',
        'field' => 'list_id',
    ],
]; */

return $schema;