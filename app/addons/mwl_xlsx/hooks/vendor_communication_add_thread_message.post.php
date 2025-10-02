<?php

use Tygh\Registry;

if (!defined('BOOTSTRAP')) {
    die('Access denied');
}

/**
 * Хук для перехвата создания сообщений в vendor_communication
 * Этот файл должен быть скопирован в app/addons/vendor_communication/func.php
 * или подключен через другой механизм
 */

/**
 * Обработка сообщения после его создания
 *
 * @param array $message_data Данные сообщения
 * @param int $message_id ID созданного сообщения
 * @param array $thread_full_data Полные данные треда
 */
function fn_mwl_xlsx_vendor_communication_add_thread_message_post($message_data, $message_id, $thread_full_data)
{
    // Логируем перехваченное сообщение
    fn_mwl_xlsx_log_hook_message([
        'message_id' => $message_id,
        'message_data' => $message_data,
        'thread_data' => $thread_full_data,
        'created_at' => time(),
        'source' => 'hook_post'
    ]);
    
    // Обрабатываем сообщение в зависимости от типа треда
    $object_type = $thread_full_data['object_type'] ?? '';
    $object_id = $thread_full_data['object_id'] ?? 0;
    
    switch ($object_type) {
        case 'O': // Заказ
            fn_mwl_xlsx_process_order_message($message_data, $message_id, $thread_full_data);
            break;
        case 'P': // Продукт
            fn_mwl_xlsx_process_product_message($message_data, $message_id, $thread_full_data);
            break;
        case 'C': // Компания
            fn_mwl_xlsx_process_company_message($message_data, $message_id, $thread_full_data);
            break;
        default:
            fn_mwl_xlsx_process_generic_message($message_data, $message_id, $thread_full_data);
            break;
    }
    
    // Отправляем уведомления
    fn_mwl_xlsx_send_message_notifications($message_data, $message_id, $thread_full_data);
}

/**
 * Логирование сообщения из хука
 *
 * @param array $logData
 */
function fn_mwl_xlsx_log_hook_message(array $logData)
{
    try {
        // Сохраняем в нашу таблицу
        $insertData = [
            'event_name' => 'vendor_communication.message_created',
            'message_id' => $logData['message_id'],
            'thread_id' => $logData['thread_data']['thread_id'] ?? 0,
            'object_type' => $logData['thread_data']['object_type'] ?? '',
            'object_id' => $logData['thread_data']['object_id'] ?? 0,
            'user_id' => $logData['message_data']['user_id'] ?? 0,
            'message_text' => $logData['message_data']['message'] ?? '',
            'user_type' => $logData['message_data']['user_type'] ?? '',
            'company_id' => $logData['thread_data']['company_id'] ?? 0,
            'created_at' => $logData['created_at'],
            'processed' => 0
        ];
        
        db_query('INSERT INTO ?:mwl_xlsx_message_log ?e', $insertData);
        
    } catch (\Exception $e) {
        fn_log_event('mwl_xlsx', 'hook_log_error', [
            'error' => $e->getMessage(),
            'message_id' => $logData['message_id'] ?? 0
        ]);
    }
}

/**
 * Обработка сообщения по заказу
 *
 * @param array $message_data
 * @param int $message_id
 * @param array $thread_data
 */
function fn_mwl_xlsx_process_order_message($message_data, $message_id, $thread_data)
{
    $order_id = $thread_data['object_id'] ?? 0;
    $message_text = $message_data['message'] ?? '';
    $user_id = $message_data['user_id'] ?? 0;
    
    // Получаем информацию о заказе
    $order = fn_get_order_info($order_id);
    if (!$order) {
        return;
    }
    
    // Анализируем сообщение
    $analysis = fn_mwl_xlsx_analyze_message($message_text);
    
    // Сохраняем анализ
    fn_mwl_xlsx_save_message_analysis($message_id, $analysis);
    
    // Если сообщение содержит жалобу, уведомляем менеджеров
    if ($analysis['contains_complaint']) {
        fn_mwl_xlsx_notify_complaint($order_id, $message_text, $user_id, $message_id);
    }
    
    // Если сообщение содержит вопрос о статусе, обновляем приоритет
    if ($analysis['contains_status_question']) {
        fn_mwl_xlsx_update_order_priority($order_id, 'high');
    }
    
    fn_log_event('mwl_xlsx', 'order_message_processed', [
        'order_id' => $order_id,
        'message_id' => $message_id,
        'user_id' => $user_id,
        'analysis' => $analysis
    ]);
}

/**
 * Обработка сообщения по продукту
 *
 * @param array $message_data
 * @param int $message_id
 * @param array $thread_data
 */
function fn_mwl_xlsx_process_product_message($message_data, $message_id, $thread_data)
{
    $product_id = $thread_data['object_id'] ?? 0;
    $message_text = $message_data['message'] ?? '';
    $user_id = $message_data['user_id'] ?? 0;
    
    // Получаем информацию о продукте
    $product = fn_get_product_data($product_id, $auth, CART_LANGUAGE, '', true, true, true, true, false, false, true);
    if (!$product) {
        return;
    }
    
    // Анализируем сообщение
    $analysis = fn_mwl_xlsx_analyze_message($message_text);
    
    // Сохраняем анализ
    fn_mwl_xlsx_save_message_analysis($message_id, $analysis);
    
    // Если вопрос о цене, уведомляем менеджеров
    if ($analysis['contains_price_question']) {
        fn_mwl_xlsx_notify_price_question($product_id, $message_text, $user_id, $message_id);
    }
    
    // Если вопрос о наличии, проверяем склад
    if ($analysis['contains_availability_question']) {
        fn_mwl_xlsx_check_product_availability($product_id, $message_id);
    }
    
    fn_log_event('mwl_xlsx', 'product_message_processed', [
        'product_id' => $product_id,
        'message_id' => $message_id,
        'user_id' => $user_id,
        'analysis' => $analysis
    ]);
}

/**
 * Обработка сообщения по компании
 *
 * @param array $message_data
 * @param int $message_id
 * @param array $thread_data
 */
function fn_mwl_xlsx_process_company_message($message_data, $message_id, $thread_data)
{
    $company_id = $thread_data['object_id'] ?? 0;
    $message_text = $message_data['message'] ?? '';
    $user_id = $message_data['user_id'] ?? 0;
    
    // Анализируем сообщение
    $analysis = fn_mwl_xlsx_analyze_message($message_text);
    
    // Сохраняем анализ
    fn_mwl_xlsx_save_message_analysis($message_id, $analysis);
    
    // Если сообщение от вендора, уведомляем администраторов
    if ($message_data['user_type'] === 'V') {
        fn_mwl_xlsx_notify_vendor_message($company_id, $message_text, $user_id, $message_id);
    }
    
    fn_log_event('mwl_xlsx', 'company_message_processed', [
        'company_id' => $company_id,
        'message_id' => $message_id,
        'user_id' => $user_id,
        'analysis' => $analysis
    ]);
}

/**
 * Обработка общего сообщения
 *
 * @param array $message_data
 * @param int $message_id
 * @param array $thread_data
 */
function fn_mwl_xlsx_process_generic_message($message_data, $message_id, $thread_data)
{
    $message_text = $message_data['message'] ?? '';
    $user_id = $message_data['user_id'] ?? 0;
    
    // Анализируем сообщение
    $analysis = fn_mwl_xlsx_analyze_message($message_text);
    
    // Сохраняем анализ
    fn_mwl_xlsx_save_message_analysis($message_id, $analysis);
    
    fn_log_event('mwl_xlsx', 'generic_message_processed', [
        'message_id' => $message_id,
        'user_id' => $user_id,
        'analysis' => $analysis
    ]);
}

/**
 * Анализ сообщения
 *
 * @param string $message_text
 * @return array
 */
function fn_mwl_xlsx_analyze_message($message_text)
{
    $message_lower = mb_strtolower($message_text);
    
    return [
        'contains_question' => strpos($message_text, '?') !== false,
        'contains_complaint' => fn_mwl_xlsx_contains_keywords($message_lower, [
            'жалоба', 'complaint', 'проблема', 'problem', 'недоволен', 'dissatisfied',
            'плохо', 'bad', 'ужасно', 'terrible', 'не работает', 'not working'
        ]),
        'contains_price_question' => fn_mwl_xlsx_contains_keywords($message_lower, [
            'цена', 'price', 'стоимость', 'cost', 'сколько', 'how much',
            'скидка', 'discount', 'акция', 'sale', 'дешевле', 'cheaper'
        ]),
        'contains_availability_question' => fn_mwl_xlsx_contains_keywords($message_lower, [
            'есть', 'available', 'наличие', 'stock', 'в наличии', 'in stock',
            'когда', 'when', 'доставка', 'delivery', 'срок', 'term'
        ]),
        'contains_status_question' => fn_mwl_xlsx_contains_keywords($message_lower, [
            'статус', 'status', 'когда', 'when', 'готов', 'ready',
            'отправлен', 'sent', 'доставлен', 'delivered'
        ]),
        'is_urgent' => fn_mwl_xlsx_contains_keywords($message_lower, [
            'срочно', 'urgent', 'быстро', 'quick', 'немедленно', 'immediately'
        ]),
        'message_length' => strlen($message_text),
        'word_count' => str_word_count($message_text)
    ];
}

/**
 * Проверка наличия ключевых слов в тексте
 *
 * @param string $text
 * @param array $keywords
 * @return bool
 */
function fn_mwl_xlsx_contains_keywords($text, array $keywords)
{
    foreach ($keywords as $keyword) {
        if (strpos($text, $keyword) !== false) {
            return true;
        }
    }
    return false;
}

/**
 * Сохранение анализа сообщения
 *
 * @param int $message_id
 * @param array $analysis
 */
function fn_mwl_xlsx_save_message_analysis($message_id, array $analysis)
{
    try {
        $insertData = [
            'message_id' => $message_id,
            'analysis_data' => json_encode($analysis),
            'created_at' => time()
        ];
        
        db_query('INSERT INTO ?:mwl_xlsx_message_analysis ?e', $insertData);
        
    } catch (\Exception $e) {
        fn_log_event('mwl_xlsx', 'analysis_save_error', [
            'error' => $e->getMessage(),
            'message_id' => $message_id
        ]);
    }
}

/**
 * Отправка уведомлений о сообщении
 *
 * @param array $message_data
 * @param int $message_id
 * @param array $thread_data
 */
function fn_mwl_xlsx_send_message_notifications($message_data, $message_id, $thread_data)
{
    // Проверяем настройки уведомлений
    $notify_enabled = Registry::get('addons.mwl_xlsx.notify_new_messages');
    if (!$notify_enabled) {
        return;
    }
    
    // Формируем уведомление
    $notification_text = "Новое сообщение в vendor_communication\n";
    $notification_text .= "ID сообщения: {$message_id}\n";
    $notification_text .= "Пользователь: " . ($message_data['user_id'] ?? 'Неизвестно') . "\n";
    $notification_text .= "Тип объекта: " . ($thread_data['object_type'] ?? '') . "\n";
    $notification_text .= "ID объекта: " . ($thread_data['object_id'] ?? 0) . "\n";
    $notification_text .= "Сообщение: " . substr($message_data['message'] ?? '', 0, 200) . "...";
    
    // Отправляем в Telegram
    $telegram_token = Registry::get('addons.mwl_xlsx.telegram_bot_token');
    $telegram_chat_id = Registry::get('addons.mwl_xlsx.telegram_chat_id');
    
    if ($telegram_token && $telegram_chat_id) {
        fn_mwl_xlsx_send_telegram_notification($telegram_token, $telegram_chat_id, $notification_text);
    }
}

/**
 * Отправка уведомления о жалобе
 *
 * @param int $order_id
 * @param string $message
 * @param int $user_id
 * @param int $message_id
 */
function fn_mwl_xlsx_notify_complaint($order_id, $message, $user_id, $message_id)
{
    $notification_text = "🚨 ЖАЛОБА по заказу #{$order_id}\n";
    $notification_text .= "Пользователь: {$user_id}\n";
    $notification_text .= "ID сообщения: {$message_id}\n";
    $notification_text .= "Сообщение: " . substr($message, 0, 300) . "...";
    
    $telegram_token = Registry::get('addons.mwl_xlsx.telegram_bot_token');
    $telegram_chat_id = Registry::get('addons.mwl_xlsx.telegram_chat_id');
    
    if ($telegram_token && $telegram_chat_id) {
        fn_mwl_xlsx_send_telegram_notification($telegram_token, $telegram_chat_id, $notification_text);
    }
}

/**
 * Отправка уведомления о вопросе по цене
 *
 * @param int $product_id
 * @param string $message
 * @param int $user_id
 * @param int $message_id
 */
function fn_mwl_xlsx_notify_price_question($product_id, $message, $user_id, $message_id)
{
    $notification_text = "💰 Вопрос о цене продукта #{$product_id}\n";
    $notification_text .= "Пользователь: {$user_id}\n";
    $notification_text .= "ID сообщения: {$message_id}\n";
    $notification_text .= "Сообщение: " . substr($message, 0, 300) . "...";
    
    $telegram_token = Registry::get('addons.mwl_xlsx.telegram_bot_token');
    $telegram_chat_id = Registry::get('addons.mwl_xlsx.telegram_chat_id');
    
    if ($telegram_token && $telegram_chat_id) {
        fn_mwl_xlsx_send_telegram_notification($telegram_token, $telegram_chat_id, $notification_text);
    }
}

/**
 * Отправка уведомления от вендора
 *
 * @param int $company_id
 * @param string $message
 * @param int $user_id
 * @param int $message_id
 */
function fn_mwl_xlsx_notify_vendor_message($company_id, $message, $user_id, $message_id)
{
    $notification_text = "🏢 Сообщение от вендора #{$company_id}\n";
    $notification_text .= "Пользователь: {$user_id}\n";
    $notification_text .= "ID сообщения: {$message_id}\n";
    $notification_text .= "Сообщение: " . substr($message, 0, 300) . "...";
    
    $telegram_token = Registry::get('addons.mwl_xlsx.telegram_bot_token');
    $telegram_chat_id = Registry::get('addons.mwl_xlsx.telegram_chat_id');
    
    if ($telegram_token && $telegram_chat_id) {
        fn_mwl_xlsx_send_telegram_notification($telegram_token, $telegram_chat_id, $notification_text);
    }
}

/**
 * Проверка наличия продукта
 *
 * @param int $product_id
 * @param int $message_id
 */
function fn_mwl_xlsx_check_product_availability($product_id, $message_id)
{
    // Получаем информацию о наличии продукта
    $product = fn_get_product_data($product_id, $auth, CART_LANGUAGE, '', true, true, true, true, false, false, true);
    
    if ($product) {
        $availability_info = [
            'product_id' => $product_id,
            'message_id' => $message_id,
            'in_stock' => $product['amount'] > 0,
            'amount' => $product['amount'],
            'status' => $product['status'],
            'checked_at' => time()
        ];
        
        // Сохраняем информацию о наличии
        db_query('INSERT INTO ?:mwl_xlsx_availability_checks ?e', $availability_info);
        
        fn_log_event('mwl_xlsx', 'availability_checked', $availability_info);
    }
}

/**
 * Обновление приоритета заказа
 *
 * @param int $order_id
 * @param string $priority
 */
function fn_mwl_xlsx_update_order_priority($order_id, $priority)
{
    // Здесь можно добавить логику обновления приоритета заказа
    // Например, через дополнительное поле в таблице заказов
    
    fn_log_event('mwl_xlsx', 'order_priority_updated', [
        'order_id' => $order_id,
        'priority' => $priority,
        'updated_at' => time()
    ]);
}

/**
 * Отправка уведомления в Telegram
 *
 * @param string $token
 * @param string $chat_id
 * @param string $text
 */
function fn_mwl_xlsx_send_telegram_notification($token, $chat_id, $text)
{
    $url = "https://api.telegram.org/bot{$token}/sendMessage";
    $data = [
        'chat_id' => $chat_id,
        'text' => $text,
        'parse_mode' => 'HTML'
    ];
    
    fn_http_request('POST', $url, $data, [
        'timeout' => 5,
        'log_pre' => 'mwl_xlsx.telegram_notification'
    ]);
}
