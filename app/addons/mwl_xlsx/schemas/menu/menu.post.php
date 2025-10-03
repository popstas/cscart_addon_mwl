<?php
$schema['central']['mwl_xlsx'] = [
    'position' => 500,
    'items' => [
        'xlsx_templates 2' => [
            'href'        => 'mwl_xlsx_templates.manage',
            'position'    => 100,
            'permissions' => 'view_catalog',
        ],
        'invites' => [
            'href'        => 'mwl_xlsx_invites.invites',
            'position'    => 150,
            'permissions' => 'view_catalog',
        ],
        'settings' => [
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

