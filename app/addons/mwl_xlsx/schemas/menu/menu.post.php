<?php
$schema['central']['mwl_xlsx'] = [
    'position' => 500,
    'items' => [
        'xlsx_templates' => [
            'href'        => 'mwl_xlsx_templates.manage',
            'position'    => 100,
            'permissions' => 'view_catalog',
        ],
    ],
];
return $schema;

