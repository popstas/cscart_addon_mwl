<?php

namespace Tygh\Addons\MwlXlsx;

use Tygh\Notifications\Transports\ITransport;
use Tygh\Notifications\Receivers\SearchCondition;
use Tygh\Notifications\DataValue;

/**
 * Транспорт для перехвата сообщений vendor_communication
 */
class MessageInterceptorTransport implements ITransport
{
    /**
     * {@inheritdoc}
     */
    public function process(array $receivers, array $data, array $attachments = [])
    {
        // Извлекаем данные сообщения
        $messageData = $data['message_data'] ?? [];
        $threadData = $data['thread_data'] ?? [];
        $eventName = $data['event_name'] ?? '';

        // Логируем перехваченное сообщение
        $this->logInterceptedMessage($eventName, $messageData, $threadData);

        // Обрабатываем сообщение
        $this->processInterceptedMessage($eventName, $messageData, $threadData);

        // Возвращаем успешный результат
        return true;
    }

    /**
     * Логирование перехваченного сообщения
     *
     * @param string $eventName
     * @param array $messageData
     * @param array $threadData
     */
    private function logInterceptedMessage($eventName, array $messageData, array $threadData)
    {
        try {
            $logData = [
                'event_name' => $eventName,
                'message_id' => $messageData['message_id'] ?? 0,
                'thread_id' => $threadData['thread_id'] ?? 0,
                'object_type' => $threadData['object_type'] ?? '',
                'object_id' => $threadData['object_id'] ?? 0,
                'user_id' => $messageData['user_id'] ?? 0,
                'message_text' => $messageData['message'] ?? '',
                'user_type' => $messageData['user_type'] ?? '',
                'company_id' => $threadData['company_id'] ?? 0,
                'created_at' => time(),
                'processed' => 0
            ];

            db_query('INSERT INTO ?:mwl_xlsx_message_log ?e', $logData);

        } catch (\Exception $e) {
            fn_log_event('mwl_xlsx', 'transport_log_error', [
                'error' => $e->getMessage(),
                'event_name' => $eventName
            ]);
        }
    }

    /**
     * Обработка перехваченного сообщения
     *
     * @param string $eventName
     * @param array $messageData
     * @param array $threadData
     */
    private function processInterceptedMessage($eventName, array $messageData, array $threadData)
    {
        // Анализируем тип сообщения
        $messageType = $this->analyzeMessageType($messageData, $threadData);
        
        // Выполняем специфичную обработку в зависимости от типа
        switch ($messageType) {
            case 'order_question':
                $this->handleOrderQuestion($messageData, $threadData);
                break;
            case 'vendor_inquiry':
                $this->handleVendorInquiry($messageData, $threadData);
                break;
            case 'admin_notification':
                $this->handleAdminNotification($messageData, $threadData);
                break;
            default:
                $this->handleGenericMessage($messageData, $threadData);
                break;
        }

        // Можно добавить интеграцию с внешними системами
        $this->sendToExternalSystems($eventName, $messageData, $threadData);
    }

    /**
     * Анализ типа сообщения
     *
     * @param array $messageData
     * @param array $threadData
     * @return string
     */
    private function analyzeMessageType(array $messageData, array $threadData)
    {
        $objectType = $threadData['object_type'] ?? '';
        $userType = $messageData['user_type'] ?? '';
        $messageText = $messageData['message'] ?? '';

        if ($objectType === 'O' && $userType === 'C') {
            return 'order_question';
        } elseif ($userType === 'V') {
            return 'vendor_inquiry';
        } elseif ($userType === 'A') {
            return 'admin_notification';
        }

        return 'generic';
    }

    /**
     * Обработка вопроса по заказу
     *
     * @param array $messageData
     * @param array $threadData
     */
    private function handleOrderQuestion(array $messageData, array $threadData)
    {
        // Логика для обработки вопросов по заказам
        // Например, уведомление менеджеров, создание задач в CRM и т.д.
        
        fn_log_event('mwl_xlsx', 'order_question_processed', [
            'order_id' => $threadData['object_id'] ?? 0,
            'message_id' => $messageData['message_id'] ?? 0,
            'user_id' => $messageData['user_id'] ?? 0
        ]);
    }

    /**
     * Обработка запроса от вендора
     *
     * @param array $messageData
     * @param array $threadData
     */
    private function handleVendorInquiry(array $messageData, array $threadData)
    {
        // Логика для обработки запросов от вендоров
        
        fn_log_event('mwl_xlsx', 'vendor_inquiry_processed', [
            'company_id' => $threadData['company_id'] ?? 0,
            'message_id' => $messageData['message_id'] ?? 0,
            'user_id' => $messageData['user_id'] ?? 0
        ]);
    }

    /**
     * Обработка уведомления администратора
     *
     * @param array $messageData
     * @param array $threadData
     */
    private function handleAdminNotification(array $messageData, array $threadData)
    {
        // Логика для обработки уведомлений от администраторов
        
        fn_log_event('mwl_xlsx', 'admin_notification_processed', [
            'message_id' => $messageData['message_id'] ?? 0,
            'user_id' => $messageData['user_id'] ?? 0
        ]);
    }

    /**
     * Обработка общего сообщения
     *
     * @param array $messageData
     * @param array $threadData
     */
    private function handleGenericMessage(array $messageData, array $threadData)
    {
        // Общая логика обработки сообщений
        
        fn_log_event('mwl_xlsx', 'generic_message_processed', [
            'message_id' => $messageData['message_id'] ?? 0,
            'thread_id' => $threadData['thread_id'] ?? 0
        ]);
    }

    /**
     * Отправка данных во внешние системы
     *
     * @param string $eventName
     * @param array $messageData
     * @param array $threadData
     */
    private function sendToExternalSystems($eventName, array $messageData, array $threadData)
    {
        // Здесь можно добавить интеграцию с:
        // - CRM системами
        // - Системами аналитики
        // - Внешними API
        // - Telegram/Discord ботами
        // - Email уведомлениями
        
        // Пример отправки в Telegram (если настроено)
        $telegramEnabled = Registry::get('addons.mwl_xlsx.telegram_bot_token');
        if ($telegramEnabled) {
            $this->sendToTelegram($eventName, $messageData, $threadData);
        }
    }

    /**
     * Отправка уведомления в Telegram
     *
     * @param string $eventName
     * @param array $messageData
     * @param array $threadData
     */
    private function sendToTelegram($eventName, array $messageData, array $threadData)
    {
        $token = Registry::get('addons.mwl_xlsx.telegram_bot_token');
        $chatId = Registry::get('addons.mwl_xlsx.telegram_chat_id');
        
        if (!$token || !$chatId) {
            return;
        }

        $messageText = "Новое сообщение в vendor_communication\n";
        $messageText .= "Тип: {$eventName}\n";
        $messageText .= "Пользователь: " . ($messageData['user_id'] ?? 'Неизвестно') . "\n";
        $messageText .= "Сообщение: " . substr($messageData['message'] ?? '', 0, 200) . "...";

        $url = "https://api.telegram.org/bot{$token}/sendMessage";
        $data = [
            'chat_id' => $chatId,
            'text' => $messageText,
            'parse_mode' => 'HTML'
        ];

        // Отправляем асинхронно
        fn_http_request('POST', $url, $data, [
            'timeout' => 5,
            'log_pre' => 'mwl_xlsx.telegram_notification'
        ]);
    }
}
