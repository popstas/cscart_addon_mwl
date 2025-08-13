<?php

use Tygh\Registry;
use Tygh\Storage;
use Tygh\Tools\SecurityHelper;

if (!defined('BOOTSTRAP')) { die('Access denied'); }

$storage = Storage::instance('custom_files'); // var/files storage (работает и с S3)
$company_id = fn_get_runtime_company_id();

/**
 * Возвращает первый валидный файл из $_FILES-спецификации (поддержка множественных инпутов)
 *
 * @param array|null $spec $_FILES['...']
 * @return array|null ['name' => string, 'tmp_name' => string, 'size' => int, 'error' => int]
 */
function mwl_xlsx_pick_first_file(?array $spec)
{
    if (!$spec) {
        return null;
    }

    // Если множественная загрузка: значения — массивы
    if (is_array($spec['tmp_name'])) {
        $count = count($spec['tmp_name']);
        for ($i = 0; $i < $count; $i++) {
            if ((int) $spec['error'][$i] === UPLOAD_ERR_OK && !empty($spec['tmp_name'][$i])) {
                return [
                    'name'     => (string) $spec['name'][$i],
                    'tmp_name' => (string) $spec['tmp_name'][$i],
                    'size'     => (int) $spec['size'][$i],
                    'error'    => (int) $spec['error'][$i],
                ];
            }
        }
        return null;
    }

    // Обычная загрузка: одиночные значения
    if ((int) $spec['error'] === UPLOAD_ERR_OK && !empty($spec['tmp_name'])) {
        return [
            'name'     => (string) $spec['name'],
            'tmp_name' => (string) $spec['tmp_name'],
            'size'     => (int) $spec['size'],
            'error'    => (int) $spec['error'],
        ];
    }

    return null;
}

// --- POST ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if ($mode === 'upload') {
        // 1) Поддерживаем оба варианта имени инпута:
        //    - common/fileuploader.tpl -> file_xlsx_template (из твоего payload)
        //    - простой <input type="file" name="xlsx_template"> (fallback)
        $file = mwl_xlsx_pick_first_file($_FILES['file_xlsx_template'] ?? null)
             ?: mwl_xlsx_pick_first_file($_FILES['xlsx_template'] ?? null);

        if (!$file) {
            fn_set_notification('E', __('error'), __('cant_upload_file'));
            return [CONTROLLER_STATUS_OK, 'mwl_xlsx_templates.manage'];
        }

        // 2) Проверим расширение (строго .xlsx)
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($ext !== 'xlsx') {
            fn_set_notification('E', __('error'), __('text_invalid_file_type'));
            return [CONTROLLER_STATUS_OK, 'mwl_xlsx_templates.manage'];
        }

        // (необязательно) Доп. проверка MIME — не делаем блокирующей, т.к. на некоторых серверах будет application/zip
        $mime_detected = fn_get_mime_content_type($file['tmp_name']);
        $allowed_mimes = [
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/zip',
            'application/octet-stream',
        ];
        if (!in_array($mime_detected, $allowed_mimes, true)) {
            // предупредим, но продолжим, т.к. расширение уже проверили
            fn_set_notification('W', __('warning'), 'MIME: ' . $mime_detected);
        }

        // 3) Готовим имя и путь в сторадже
        $basename = SecurityHelper::sanitizeFileName(pathinfo($file['name'], PATHINFO_FILENAME));
        $fname    = $basename . '-' . substr(sha1(uniqid('', true)), 0, 8) . '.xlsx';
        $dst      = 'mwl_xlsx/templates/' . (int) $company_id . '/' . $fname;

        // Уже существующие шаблоны (должен быть максимум один)
        $existing = db_get_array('SELECT template_id, path FROM ?:mwl_xlsx_templates WHERE company_id = ?i', (int) $company_id);

        // 4) Копируем во Storage
        $put = $storage->put($dst, ['file' => $file['tmp_name']]);
        if ($put) {
            // удаляем старые шаблоны
            foreach ($existing as $tpl) {
                $storage->delete($tpl['path']);
                db_query('DELETE FROM ?:mwl_xlsx_templates WHERE template_id = ?i', (int) $tpl['template_id']);
            }

            $now = TIME;
            db_query(
                'INSERT INTO ?:mwl_xlsx_templates ?e',
                [
                    'company_id' => (int) $company_id,
                    'name'       => $file['name'], // оригинальное имя
                    'path'       => $dst,           // относительный путь в сторадже
                    'size'       => (int) $file['size'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
            fn_set_notification('N', __('notice'), __('file_uploaded'));
        } else {
            fn_set_notification('E', __('error'), __('cant_upload_file'));
        }

        return [CONTROLLER_STATUS_OK, 'mwl_xlsx_templates.manage'];
    }

    if ($mode === 'delete' && !empty($_REQUEST['template_id'])) {
        $tpl = db_get_row(
            'SELECT * FROM ?:mwl_xlsx_templates WHERE template_id = ?i AND company_id IN (?n)',
            (int) $_REQUEST['template_id'],
            $company_id ? [$company_id] : [0, $company_id] // суперадмин видит все
        );
        if ($tpl) {
            $storage->delete($tpl['path']);
            db_query('DELETE FROM ?:mwl_xlsx_templates WHERE template_id = ?i', (int) $tpl['template_id']);
            fn_set_notification('N', __('notice'), __('deleted'));
        }
        return [CONTROLLER_STATUS_OK, 'mwl_xlsx_templates.manage'];
    }

    return [CONTROLLER_STATUS_OK];
}

// --- GET ---
if ($mode === 'manage') {
    $condition = $company_id
        ? db_quote(' WHERE company_id = ?i', $company_id)
        : '';

    $templates = db_get_array('SELECT * FROM ?:mwl_xlsx_templates ?p ORDER BY template_id DESC', $condition);

    Tygh::$app['view']->assign([
        'mwl_xlsx_templates' => $templates,
        'company_id'         => $company_id,
    ]);
}
