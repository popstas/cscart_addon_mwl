<?php
use Tygh\Enum\UserTypes;

defined('BOOTSTRAP') or die('Access denied!');

/** @var array $schema */
$schema['items']['actions']['items']['mwl_xlsx.send_recover_links'] = [
    'name'     => ['template' => 'mwl_xlsx.send_recover_links'],
    'dispatch' => 'mwl_xlsx.send_recover_to_users',
    'permission_callback' => static function ($request, $auth, $runtime) {
        // показываем действие только для списка покупателей (user_type=C)
        return UserTypes::isCustomer($request['user_type'] ?? UserTypes::CUSTOMER);
    },
    'position' => 33,
];

return $schema;
