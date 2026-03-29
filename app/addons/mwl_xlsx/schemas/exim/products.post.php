<?php

defined('BOOTSTRAP') or die('Access denied');

include_once __DIR__ . '/products.functions.php';

/**
 * @var array $schema
 */
$schema['import_process_data']['mwl_skip_unchanged_products'] = [
    'function'    => 'fn_mwl_exim_skip_unchanged_products',
    'args'        => ['$primary_object_id', '$object', '$pattern', '$options', '$processed_data', '$skip_record'],
    'import_only' => true,
];

return $schema;
