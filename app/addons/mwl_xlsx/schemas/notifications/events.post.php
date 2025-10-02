<?php

defined('BOOTSTRAP') or die('Access denied');

/** @var array $schema */

$targets = [
    'vendor_communication.message_received',
    'vendor_communication.order_message_received',
];

// Схема событий для транспорта mwl

foreach ($targets as $event_id) {
    // Проверяем, существует ли уже событие
    if (!isset($schema[$event_id])) {
        $schema[$event_id] = [
            'group'     => 'vendor_communication',
            'name'      => ['template' => $event_id],
            'receivers' => [],
        ];
    }
    
    // Гарантируем, что у нас есть получатель A (Admin)
    if (!isset($schema[$event_id]['receivers']['A'])) {
        $schema[$event_id]['receivers']['A'] = [];
    }
    
    // Гарантируем, что у нас есть получатель C (Customer)
    if (!isset($schema[$event_id]['receivers']['C'])) {
        $schema[$event_id]['receivers']['C'] = [];
    }
    
    // Добавляем наш транспорт с правильной схемой сообщения
    // Используем InternalMessageSchema как базовую схему
    $schema[$event_id]['receivers']['A']['mwl'] = new \Tygh\Notifications\Transports\Internal\InternalMessageSchema();
    $schema[$event_id]['receivers']['C']['mwl'] = new \Tygh\Notifications\Transports\Internal\InternalMessageSchema();
}

// Возвращаем обновленную схему

return $schema;

