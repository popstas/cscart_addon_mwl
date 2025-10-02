<?php

defined('BOOTSTRAP') or die('Access denied!');

/** @var array $schema */

// Расширяем события vendor_communication для перехвата сообщений
$vendor_communication_events = [
    'vendor_communication.message_received',
    'vendor_communication.vendor_to_admin_message_received', 
    'vendor_communication.order_message_received'
];

foreach ($vendor_communication_events as $event_name) {
    if (!isset($schema[$event_name])) {
        $schema[$event_name] = [];
    }
    
    if (!isset($schema[$event_name]['receivers'])) {
        $schema[$event_name]['receivers'] = [];
    }
    
    // Добавляем наш транспорт для перехвата сообщений
    $schema[$event_name]['receivers']['mwl_xlsx_message_interceptor'] = [
        'transport' => 'mwl_xlsx_message_interceptor',
        'data_modifier' => 'fn_mwl_xlsx_modify_vendor_communication_data',
        'conditions' => [
            'user_status' => ['A'], // Активные пользователи
        ],
    ];
}

return $schema;
