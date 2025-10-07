<?php
$schema['central']['mwl_xlsx'] = [
    'position' => 500,
    'items' => [
        'mwl_xlsx.xlsx_templates' => [
            'href'        => 'mwl_xlsx_templates.manage',
            'position'    => 100,
            'permissions' => 'view_catalog',
        ],
        'mwl_xlsx.status_map' => [
            'href'        => 'mwl_xlsx_status_map.manage',
            'position'    => 110,
            'permissions' => 'view_catalog',
        ],
        'mwl_xlsx.invites' => [
            'href'        => 'mwl_xlsx_invites.invites',
            'position'    => 150,
            'permissions' => 'view_catalog',
        ],
        'mwl_xlsx.settings' => [
            'href'        => 'mwl_xlsx.settings',
            'position'    => 200,
            'permissions' => 'view_catalog',
        ],
        'mwl_xlsx.settings_backup' => [
            'href'        => 'mwl_xlsx.backup_settings',
            'position'    => 250,
            'permissions' => 'manage_addons',
        ],
    ],
];
return $schema;

