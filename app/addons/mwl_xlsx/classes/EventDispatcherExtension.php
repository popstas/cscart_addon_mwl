<?php

namespace Tygh\Addons\MwlXlsx;

use Tygh\Events\EventDispatcherInterface;
use Tygh\Events\Event;

/**
 * Event Dispatcher Extension для перехвата событий vendor_communication
 */
class EventDispatcherExtension implements EventDispatcherInterface
{
    /**
     * @var EventDispatcherInterface
     */
    private $originalDispatcher;

    public function __construct(EventDispatcherInterface $originalDispatcher)
    {
        $this->originalDispatcher = $originalDispatcher;
    }

    /**
     * {@inheritdoc}
     */
    public function dispatch($eventName, Event $event = null, array $data = [])
    {
        // Перехватываем события vendor_communication
        if (is_string($eventName) && strpos($eventName, 'vendor_communication.') === 0) {
            $this->handleVendorCommunicationEvent($eventName, $event, $data);
        }

        // Вызываем оригинальный диспетчер
        return $this->originalDispatcher->dispatch($eventName, $event, $data);
    }

    /**
     * Обработка событий vendor_communication
     *
     * @param string $eventName
     * @param Event|null $event
     * @param array $data
     */
    private function handleVendorCommunicationEvent($eventName, $event, array $data)
    {
        // Логируем событие для отладки
        fn_log_event('mwl_xlsx', 'vendor_communication_event', [
            'event_name' => $eventName,
            'data' => $data,
            'timestamp' => time()
        ]);

        // Обрабатываем конкретные события
        switch ($eventName) {
            case 'vendor_communication.message_received':
            case 'vendor_communication.vendor_to_admin_message_received':
            case 'vendor_communication.order_message_received':
                $this->processMessageEvent($eventName, $data);
                break;
        }
    }

    /**
     * Обработка события сообщения
     *
     * @param string $eventName
     * @param array $data
     */
    private function processMessageEvent($eventName, array $data)
    {
        // Извлекаем данные сообщения
        $messageData = $data['message_data'] ?? [];
        $threadData = $data['thread_data'] ?? [];
        $notificationRules = $data['notification_rules'] ?? [];

        // Сохраняем данные в нашу таблицу для дальнейшей обработки
        $this->saveMessageData($eventName, $messageData, $threadData, $notificationRules);

        // Можно добавить дополнительную логику:
        // - Отправка в внешние системы
        // - Анализ содержимого сообщения
        // - Уведомления администраторов
        // - Интеграция с CRM
    }

    /**
     * Сохранение данных сообщения
     *
     * @param string $eventName
     * @param array $messageData
     * @param array $threadData
     * @param array $notificationRules
     */
    private function saveMessageData($eventName, array $messageData, array $threadData, array $notificationRules)
    {
        try {
            // Создаем запись в нашей таблице для отслеживания сообщений
            $insertData = [
                'event_name' => $eventName,
                'message_id' => $messageData['message_id'] ?? 0,
                'thread_id' => $threadData['thread_id'] ?? 0,
                'object_type' => $threadData['object_type'] ?? '',
                'object_id' => $threadData['object_id'] ?? 0,
                'user_id' => $messageData['user_id'] ?? 0,
                'message_text' => $messageData['message'] ?? '',
                'created_at' => time(),
                'processed' => 0
            ];

            db_query('INSERT INTO ?:mwl_xlsx_message_log ?e', $insertData);

        } catch (\Exception $e) {
            // Логируем ошибку, но не прерываем выполнение
            fn_log_event('mwl_xlsx', 'message_log_error', [
                'error' => $e->getMessage(),
                'event_name' => $eventName
            ]);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function addListener($eventName, $listener, $priority = 0)
    {
        return $this->originalDispatcher->addListener($eventName, $listener, $priority);
    }

    /**
     * {@inheritdoc}
     */
    public function removeListener($eventName, $listener)
    {
        return $this->originalDispatcher->removeListener($eventName, $listener);
    }

    /**
     * {@inheritdoc}
     */
    public function getListeners($eventName = null)
    {
        return $this->originalDispatcher->getListeners($eventName);
    }

    /**
     * {@inheritdoc}
     */
    public function hasListeners($eventName = null)
    {
        return $this->originalDispatcher->hasListeners($eventName);
    }
}
