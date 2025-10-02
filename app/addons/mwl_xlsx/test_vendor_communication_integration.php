<?php

use Tygh\Registry;

if (!defined('BOOTSTRAP')) {
    die('Access denied');
}

/**
 * Тестовый файл для проверки интеграции с vendor_communication
 * Этот файл можно удалить после тестирования
 */

/**
 * Тестирование всех вариантов перехвата сообщений
 */
function fn_mwl_xlsx_test_vendor_communication_integration()
{
    echo "<h2>Тестирование интеграции с vendor_communication</h2>\n";
    
    // Тест 1: Проверка Event Dispatcher Extension
    echo "<h3>1. Тест Event Dispatcher Extension</h3>\n";
    $eventDispatcher = Tygh::$app['event.dispatcher'];
    if ($eventDispatcher instanceof \Tygh\Addons\MwlXlsx\EventDispatcherExtension) {
        echo "✅ Event Dispatcher Extension активен<br>\n";
    } else {
        echo "❌ Event Dispatcher Extension не активен<br>\n";
    }
    
    // Тест 2: Проверка транспорта уведомлений
    echo "<h3>2. Тест транспорта уведомлений</h3>\n";
    $transportClass = 'Tygh\Addons\MwlXlsx\MessageInterceptorTransport';
    if (class_exists($transportClass)) {
        echo "✅ Класс транспорта уведомлений найден<br>\n";
        
        // Проверяем, зарегистрирован ли транспорт
        $transport = new $transportClass();
        echo "✅ Транспорт уведомлений создан<br>\n";
    } else {
        echo "❌ Класс транспорта уведомлений не найден<br>\n";
    }
    
    // Тест 3: Проверка контроллера
    echo "<h3>3. Тест контроллера vendor_communication.post.php</h3>\n";
    $controllerFile = __DIR__ . '/controllers/common/vendor_communication.post.php';
    if (file_exists($controllerFile)) {
        echo "✅ Файл контроллера найден<br>\n";
    } else {
        echo "❌ Файл контроллера не найден<br>\n";
    }
    
    // Тест 4: Проверка хука
    echo "<h3>4. Тест хука vendor_communication_add_thread_message.post.php</h3>\n";
    $hookFile = __DIR__ . '/hooks/vendor_communication_add_thread_message.post.php';
    if (file_exists($hookFile)) {
        echo "✅ Файл хука найден<br>\n";
    } else {
        echo "❌ Файл хука не найден<br>\n";
    }
    
    // Тест 5: Проверка таблиц базы данных
    echo "<h3>5. Тест таблиц базы данных</h3>\n";
    $tables = [
        'mwl_xlsx_message_log',
        'mwl_xlsx_message_analysis', 
        'mwl_xlsx_availability_checks'
    ];
    
    foreach ($tables as $table) {
        $exists = db_get_field("SHOW TABLES LIKE '?:{$table}'");
        if ($exists) {
            echo "✅ Таблица {$table} существует<br>\n";
        } else {
            echo "❌ Таблица {$table} не существует<br>\n";
        }
    }
    
    // Тест 6: Проверка настроек
    echo "<h3>6. Тест настроек</h3>\n";
    $settings = [
        'mwl_xlsx.notify_admins_messages',
        'mwl_xlsx.notify_new_messages',
        'mwl_xlsx.telegram_bot_token',
        'mwl_xlsx.telegram_chat_id'
    ];
    
    foreach ($settings as $setting) {
        $value = Registry::get("addons.{$setting}");
        if ($value !== null) {
            echo "✅ Настройка {$setting}: " . (is_bool($value) ? ($value ? 'Да' : 'Нет') : $value) . "<br>\n";
        } else {
            echo "❌ Настройка {$setting} не найдена<br>\n";
        }
    }
    
    // Тест 7: Симуляция события
    echo "<h3>7. Тест симуляции события</h3>\n";
    try {
        $testData = [
            'message_data' => [
                'message_id' => 999999,
                'user_id' => 1,
                'message' => 'Тестовое сообщение для проверки интеграции',
                'user_type' => 'C'
            ],
            'thread_data' => [
                'thread_id' => 999999,
                'object_type' => 'O',
                'object_id' => 12345,
                'company_id' => 1
            ],
            'event_name' => 'vendor_communication.message_received'
        ];
        
        // Симулируем вызов event dispatcher
        $eventDispatcher->dispatch('vendor_communication.message_received', null, $testData);
        echo "✅ Событие успешно обработано<br>\n";
        
        // Проверяем, что данные сохранились в лог
        $logEntry = db_get_row("SELECT * FROM ?:mwl_xlsx_message_log WHERE message_id = ?i", 999999);
        if ($logEntry) {
            echo "✅ Запись в логе создана<br>\n";
        } else {
            echo "❌ Запись в логе не создана<br>\n";
        }
        
    } catch (\Exception $e) {
        echo "❌ Ошибка при тестировании события: " . $e->getMessage() . "<br>\n";
    }
    
    echo "<h3>Тестирование завершено</h3>\n";
}

/**
 * Очистка тестовых данных
 */
function fn_mwl_xlsx_cleanup_test_data()
{
    // Удаляем тестовые записи
    db_query("DELETE FROM ?:mwl_xlsx_message_log WHERE message_id = ?i", 999999);
    db_query("DELETE FROM ?:mwl_xlsx_message_analysis WHERE message_id = ?i", 999999);
    db_query("DELETE FROM ?:mwl_xlsx_availability_checks WHERE message_id = ?i", 999999);
    
    echo "Тестовые данные очищены<br>\n";
}

// Если файл вызван напрямую, запускаем тесты
if (basename($_SERVER['PHP_SELF']) === basename(__FILE__)) {
    fn_mwl_xlsx_test_vendor_communication_integration();
    
    if (isset($_GET['cleanup'])) {
        fn_mwl_xlsx_cleanup_test_data();
    }
}
