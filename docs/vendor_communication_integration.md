# Интеграция с vendor_communication

Данный документ описывает различные способы перехвата событий создания сообщений через модуль `vendor_communication` в CS-Cart и использования данных сообщений в собственном модуле.

## Обзор

Модуль `vendor_communication` в CS-Cart позволяет пользователям общаться с вендорами и администраторами через систему сообщений. Для перехвата этих сообщений и их обработки в собственном модуле реализованы четыре различных подхода.

## Варианты реализации

### 1. Расширение Event Dispatcher

**Файлы:**
- `app/addons/mwl_xlsx/classes/EventDispatcherExtension.php`
- `app/addons/mwl_xlsx/init.php` (функция `fn_mwl_xlsx_init_addon`)

**Принцип работы:**
Создается декоратор для `Tygh::$app['event.dispatcher']`, который перехватывает все события, начинающиеся с `vendor_communication.`. При получении такого события выполняется дополнительная обработка.

**Преимущества:**
- Не требует изменений в модуле `vendor_communication`
- Работает для всех точек, где вызывается событие
- Централизованная обработка всех событий

**Недостатки:**
- Может влиять на производительность при большом количестве событий
- Требует понимания архитектуры событий CS-Cart

**Использование:**
```php
// Автоматически активируется при инициализации аддона
Tygh::$app->extend('event.dispatcher', function ($originalDispatcher) {
    return new EventDispatcherExtension($originalDispatcher);
});
```

### 2. Собственный транспорт уведомлений

**Файлы:**
- `app/addons/mwl_xlsx/schemas/notifications/events.post.php`
- `app/addons/mwl_xlsx/classes/MessageInterceptorTransport.php`
- `app/addons/mwl_xlsx/func.php` (функция `fn_mwl_xlsx_modify_vendor_communication_data`)

**Принцип работы:**
Расширяется схема событий уведомлений, добавляется собственный транспорт `mwl_xlsx_message_interceptor`, который получает все данные о сообщении и может выполнять дополнительную обработку.

**Преимущества:**
- Интеграция с системой уведомлений CS-Cart
- Возможность модификации данных через `data_modifier`
- Гибкая настройка условий отправки

**Недостатки:**
- Работает только с событиями, которые проходят через систему уведомлений
- Требует понимания схемы уведомлений

**Использование:**
```php
// В schemas/notifications/events.post.php
$schema[$event_name]['receivers']['mwl_xlsx_message_interceptor'] = [
    'transport' => 'mwl_xlsx_message_interceptor',
    'data_modifier' => 'fn_mwl_xlsx_modify_vendor_communication_data',
    'conditions' => [
        'user_status' => ['A'],
    ],
];
```

### 3. Расширение контроллера vendor_communication

**Файлы:**
- `app/addons/mwl_xlsx/controllers/common/vendor_communication.post.php`

**Принцип работы:**
Создается пост-контроллер, который выполняется после основного контроллера `vendor_communication` в режиме `post_message`. Получает доступ к данным запроса и может выполнить дополнительную обработку.

**Преимущества:**
- Простая реализация
- Прямой доступ к данным запроса
- Не требует изменений в ядре

**Недостатки:**
- Работает только для сообщений, отправленных через веб-интерфейс
- Не перехватывает внутренние вызовы API
- Зависит от структуры контроллера

**Использование:**
```php
// В controllers/common/vendor_communication.post.php
if ($mode === 'post_message') {
    $message = $_REQUEST['message'] ?? '';
    $thread_id = $_REQUEST['thread_id'] ?? 0;
    // Обработка сообщения...
}
```

### 4. Локальный хук для fn_vendor_communication_add_thread_message

**Файлы:**
- `app/addons/mwl_xlsx/hooks/vendor_communication_add_thread_message.post.php`

**Принцип работы:**
Добавляется хук в функцию `fn_vendor_communication_add_thread_message` модуля `vendor_communication`. Хук выполняется после создания сообщения и получает все данные о сообщении и треде.

**Преимущества:**
- Самый прямой способ перехвата
- Полный доступ к данным сообщения и треда
- Выполняется для всех способов создания сообщений

**Недостатки:**
- Требует модификации модуля `vendor_communication`
- Нужно поддерживать при обновлениях модуля
- Может конфликтовать с другими аддонами

**Использование:**
```php
// В app/addons/vendor_communication/func.php (требует модификации)
fn_set_hook('vendor_communication_add_thread_message_post', $message_data, $message_id, $thread_full_data);

// В нашем аддоне
function fn_mwl_xlsx_vendor_communication_add_thread_message_post($message_data, $message_id, $thread_full_data) {
    // Обработка сообщения...
}
```

## Структура данных

### Таблица mwl_xlsx_message_log
Хранит все перехваченные сообщения:
```sql
CREATE TABLE `?:mwl_xlsx_message_log` (
    `log_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `event_name` VARCHAR(255) NOT NULL,
    `message_id` INT UNSIGNED NOT NULL DEFAULT 0,
    `thread_id` INT UNSIGNED NOT NULL DEFAULT 0,
    `object_type` VARCHAR(10) NOT NULL DEFAULT '',
    `object_id` INT UNSIGNED NOT NULL DEFAULT 0,
    `user_id` INT UNSIGNED NOT NULL DEFAULT 0,
    `message_text` TEXT,
    `user_type` VARCHAR(10) NOT NULL DEFAULT '',
    `company_id` INT UNSIGNED NOT NULL DEFAULT 0,
    `created_at` INT UNSIGNED NOT NULL,
    `processed` TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (`log_id`)
);
```

### Таблица mwl_xlsx_message_analysis
Хранит анализ сообщений:
```sql
CREATE TABLE `?:mwl_xlsx_message_analysis` (
    `analysis_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `message_id` INT UNSIGNED NOT NULL,
    `analysis_data` TEXT NOT NULL,
    `created_at` INT UNSIGNED NOT NULL,
    PRIMARY KEY (`analysis_id`),
    UNIQUE KEY `message_id` (`message_id`)
);
```

### Таблица mwl_xlsx_availability_checks
Хранит проверки наличия товаров:
```sql
CREATE TABLE `?:mwl_xlsx_availability_checks` (
    `check_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `product_id` INT UNSIGNED NOT NULL,
    `message_id` INT UNSIGNED NOT NULL,
    `in_stock` TINYINT(1) NOT NULL DEFAULT 0,
    `amount` INT NOT NULL DEFAULT 0,
    `status` VARCHAR(10) NOT NULL DEFAULT '',
    `checked_at` INT UNSIGNED NOT NULL,
    PRIMARY KEY (`check_id`)
);
```

## Функциональность

### Анализ сообщений
Система автоматически анализирует сообщения и определяет:
- Содержит ли сообщение вопрос
- Является ли сообщение жалобой
- Содержит ли вопрос о цене
- Содержит ли вопрос о наличии
- Содержит ли вопрос о статусе
- Является ли сообщение срочным

### Уведомления
Система может отправлять уведомления в Telegram при:
- Создании нового сообщения
- Получении жалобы
- Вопросе о цене
- Сообщении от вендора
- Срочных сообщениях

### Интеграция с внешними системами
Система предоставляет точки интеграции для:
- CRM систем
- Систем аналитики
- Внешних API
- Email уведомлений

## Настройки

### Основные настройки
- `mwl_xlsx.notify_admins_messages` - Уведомлять администраторов о новых сообщениях
- `mwl_xlsx.notify_new_messages` - Уведомлять о всех новых сообщениях

### Telegram настройки
- `mwl_xlsx.telegram_bot_token` - Токен Telegram бота
- `mwl_xlsx.telegram_chat_id` - ID чата для уведомлений

## Тестирование

Для тестирования интеграции используйте файл `test_vendor_communication_integration.php`:

```php
// Запуск тестов
fn_mwl_xlsx_test_vendor_communication_integration();

// Очистка тестовых данных
fn_mwl_xlsx_cleanup_test_data();
```

## Рекомендации по использованию

1. **Для большинства случаев** рекомендуется использовать **Event Dispatcher Extension** - он не требует изменений в других модулях и работает надежно.

2. **Для интеграции с системой уведомлений** используйте **собственный транспорт уведомлений**.

3. **Для простых случаев** можно использовать **расширение контроллера**.

4. **Локальный хук** используйте только если другие методы не подходят, так как он требует модификации модуля `vendor_communication`.

## Безопасность

- Все пользовательские данные экранируются перед сохранением
- Ошибки логируются, но не прерывают выполнение основного функционала
- Telegram уведомления отправляются асинхронно
- Доступ к данным ограничен правами пользователя

## Производительность

- Обработка сообщений выполняется асинхронно
- Используются индексы для быстрого поиска в логах
- Старые записи можно периодически очищать
- Анализ сообщений кэшируется

## Обновления

При обновлении модуля `vendor_communication`:
1. Проверьте совместимость Event Dispatcher Extension
2. Обновите схему уведомлений при необходимости
3. Проверьте работу контроллера
4. Если используется локальный хук, обновите его в соответствии с изменениями в модуле
