<?php
$schema = $schema ?? [];

$schema['/media-lists'] = [
    'dispatch' => 'mwl_xlsx.manage',
];

return $schema;
