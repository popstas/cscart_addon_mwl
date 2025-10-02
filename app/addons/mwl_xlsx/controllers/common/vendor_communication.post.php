<?php

use Tygh\Registry;

if (!defined('BOOTSTRAP')) {
    die('Access denied');
}

// Перехватываем создание сообщений через контроллер vendor_communication
if ($mode === 'post_message') {
    // Получаем данные сообщения из запроса
    $message = $_REQUEST['message'] ?? '';
    $thread_id = $_REQUEST['thread_id'] ?? 0;
    $object_type = $_REQUEST['object_type'] ?? '';
    $object_id = $_REQUEST['object_id'] ?? 0;
    
    // Получаем информацию о пользователе
    $auth = Tygh::$app['session']['auth'] ?? [];
    $user_id = $auth['user_id'] ?? 0;
    $user_type = $auth['user_type'] ?? '';
    
    // Логируем перехваченное сообщение
    fn_mwl_xlsx_log_controller_message([
        'message' => $message,
        'thread_id' => $thread_id,
        'object_type' => $object_type,
        'object_id' => $object_id,
        'user_id' => $user_id,
        'user_type' => $user_type,
        'created_at' => time(),
        'source' => 'controller_post'
    ]);
    
    // Дополнительная обработка в зависимости от типа объекта
    if ($object_type === 'O') { // Заказ
        fn_mwl_xlsx_handle_order_message($message, $thread_id, $object_id, $user_id);
    } elseif ($object_type === 'P') { // Продукт
        fn_mwl_xlsx_handle_product_message($message, $thread_id, $object_id, $user_id);
    }
    
    // Можно добавить уведомления администраторов
    fn_mwl_xlsx_notify_admins_about_message($message, $object_type, $object_id, $user_id);
}

/**
 * Логирование сообщения из контроллера
 *
 * @param array $messageData
 */
function fn_mwl_xlsx_log_controller_message(array $messageData)
{
    try {
        db_query('INSERT INTO ?:mwl_xlsx_message_log ?e', $messageData);
    } catch (\Exception $e) {
        fn_log_event('mwl_xlsx', 'controller_log_error', [
            'error' => $e->getMessage(),
            'message_data' => $messageData
        ]);
    }
}

/**
 * Обработка сообщения по заказу
 *
 * @param string $message
 * @param int $thread_id
 * @param int $order_id
 * @param int $user_id
 */
function fn_mwl_xlsx_handle_order_message($message, $thread_id, $order_id, $user_id)
{
    // Получаем информацию о заказе
    $order = fn_get_order_info($order_id);
    if (!$order) {
        return;
    }
    
    // Анализируем сообщение
    $is_urgent = fn_mwl_xlsx_is_urgent_message($message);
    $contains_question = strpos($message, '?') !== false;
    
    // Логируем специфичную информацию
    fn_log_event('mwl_xlsx', 'order_message_processed', [
        'order_id' => $order_id,
        'thread_id' => $thread_id,
        'user_id' => $user_id,
        'is_urgent' => $is_urgent,
        'contains_question' => $contains_question,
        'order_status' => $order['status'] ?? '',
        'order_total' => $order['total'] ?? 0
    ]);
    
    // Если сообщение срочное, уведомляем менеджеров
    if ($is_urgent) {
        fn_mwl_xlsx_notify_managers_urgent_order_message($order_id, $message, $user_id);
    }
}

/**
 * Обработка сообщения по продукту
 *
 * @param string $message
 * @param int $thread_id
 * @param int $product_id
 * @param int $user_id
 */
function fn_mwl_xlsx_handle_product_message($message, $thread_id, $product_id, $user_id)
{
    // Получаем информацию о продукте
    $product = fn_get_product_data($product_id, $auth, CART_LANGUAGE, '', true, true, true, true, false, false, true);
    if (!$product) {
        return;
    }
    
    // Анализируем сообщение
    $is_price_question = fn_mwl_xlsx_is_price_question($message);
    $is_availability_question = fn_mwl_xlsx_is_availability_question($message);
    
    // Логируем специфичную информацию
    fn_log_event('mwl_xlsx', 'product_message_processed', [
        'product_id' => $product_id,
        'thread_id' => $thread_id,
        'user_id' => $user_id,
        'is_price_question' => $is_price_question,
        'is_availability_question' => $is_availability_question,
        'product_name' => $product['product'] ?? '',
        'product_status' => $product['status'] ?? ''
    ]);
    
    // Если вопрос о цене, уведомляем менеджеров
    if ($is_price_question) {
        fn_mwl_xlsx_notify_managers_price_question($product_id, $message, $user_id);
    }
}

/**
 * Проверка, является ли сообщение срочным
 *
 * @param string $message
 * @return bool
 */
function fn_mwl_xlsx_is_urgent_message($message)
{
    $urgent_keywords = [
        'срочно', 'urgent', 'быстро', 'quick', 'немедленно', 'immediately',
        'проблема', 'problem', 'ошибка', 'error', 'не работает', 'not working'
    ];
    
    $message_lower = mb_strtolower($message);
    
    foreach ($urgent_keywords as $keyword) {
        if (strpos($message_lower, $keyword) !== false) {
            return true;
        }
    }
    
    return false;
}

/**
 * Проверка, является ли сообщение вопросом о цене
 *
 * @param string $message
 * @return bool
 */
function fn_mwl_xlsx_is_price_question($message)
{
    $price_keywords = [
        'цена', 'price', 'стоимость', 'cost', 'сколько', 'how much',
        'скидка', 'discount', 'акция', 'sale', 'дешевле', 'cheaper'
    ];
    
    $message_lower = mb_strtolower($message);
    
    foreach ($price_keywords as $keyword) {
        if (strpos($message_lower, $keyword) !== false) {
            return true;
        }
    }
    
    return false;
}

/**
 * Проверка, является ли сообщение вопросом о наличии
 *
 * @param string $message
 * @return bool
 */
function fn_mwl_xlsx_is_availability_question($message)
{
    $availability_keywords = [
        'есть', 'available', 'наличие', 'stock', 'в наличии', 'in stock',
        'когда', 'when', 'доставка', 'delivery', 'срок', 'term'
    ];
    
    $message_lower = mb_strtolower($message);
    
    foreach ($availability_keywords as $keyword) {
        if (strpos($message_lower, $keyword) !== false) {
            return true;
        }
    }
    
    return false;
}

/**
 * Уведомление администраторов о новом сообщении
 *
 * @param string $message
 * @param string $object_type
 * @param int $object_id
 * @param int $user_id
 */
function fn_mwl_xlsx_notify_admins_about_message($message, $object_type, $object_id, $user_id)
{
    // Получаем настройки уведомлений
    $notify_enabled = Registry::get('addons.mwl_xlsx.notify_admins_messages');
    if (!$notify_enabled) {
        return;
    }
    
    // Формируем текст уведомления
    $notification_text = "Новое сообщение в vendor_communication\n";
    $notification_text .= "Тип объекта: {$object_type}\n";
    $notification_text .= "ID объекта: {$object_id}\n";
    $notification_text .= "Пользователь: {$user_id}\n";
    $notification_text .= "Сообщение: " . substr($message, 0, 200) . "...";
    
    // Отправляем в Telegram, если настроено
    $telegram_token = Registry::get('addons.mwl_xlsx.telegram_bot_token');
    $telegram_chat_id = Registry::get('addons.mwl_xlsx.telegram_chat_id');
    
    if ($telegram_token && $telegram_chat_id) {
        fn_mwl_xlsx_send_telegram_message($telegram_token, $telegram_chat_id, $notification_text);
    }
}

/**
 * Уведомление менеджеров о срочном сообщении по заказу
 *
 * @param int $order_id
 * @param string $message
 * @param int $user_id
 */
function fn_mwl_xlsx_notify_managers_urgent_order_message($order_id, $message, $user_id)
{
    $notification_text = "🚨 СРОЧНОЕ сообщение по заказу #{$order_id}\n";
    $notification_text .= "Пользователь: {$user_id}\n";
    $notification_text .= "Сообщение: " . substr($message, 0, 200) . "...";
    
    $telegram_token = Registry::get('addons.mwl_xlsx.telegram_bot_token');
    $telegram_chat_id = Registry::get('addons.mwl_xlsx.telegram_chat_id');
    
    if ($telegram_token && $telegram_chat_id) {
        fn_mwl_xlsx_send_telegram_message($telegram_token, $telegram_chat_id, $notification_text);
    }
}

/**
 * Уведомление менеджеров о вопросе по цене
 *
 * @param int $product_id
 * @param string $message
 * @param int $user_id
 */
function fn_mwl_xlsx_notify_managers_price_question($product_id, $message, $user_id)
{
    $notification_text = "💰 Вопрос о цене продукта #{$product_id}\n";
    $notification_text .= "Пользователь: {$user_id}\n";
    $notification_text .= "Сообщение: " . substr($message, 0, 200) . "...";
    
    $telegram_token = Registry::get('addons.mwl_xlsx.telegram_bot_token');
    $telegram_chat_id = Registry::get('addons.mwl_xlsx.telegram_chat_id');
    
    if ($telegram_token && $telegram_chat_id) {
        fn_mwl_xlsx_send_telegram_message($telegram_token, $telegram_chat_id, $notification_text);
    }
}

/**
 * Отправка сообщения в Telegram
 *
 * @param string $token
 * @param string $chat_id
 * @param string $text
 */
function fn_mwl_xlsx_send_telegram_message($token, $chat_id, $text)
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
