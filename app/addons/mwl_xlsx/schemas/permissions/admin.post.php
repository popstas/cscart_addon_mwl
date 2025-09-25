<?php
$schema['mwl_xlsx'] = [
    'permissions' => [
        'POST' => 'manage_users',
        'GET'  => 'view_users',
    ],
];
$schema['mwl_xlsx_templates'] = [
    'permissions' => ['GET' => 'view_catalog', 'POST' => 'manage_catalog'],
];
return $schema;

