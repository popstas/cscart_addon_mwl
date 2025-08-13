<?php

use Tygh\Registry;
use Tygh\Storage;
use Tygh\Tools\SecurityHelper;

if (!defined('BOOTSTRAP')) { die('Access denied'); }

$storage = Storage::instance('custom_files'); // хранение в var/files/ (S3 и др. тоже ок)
$company_id = fn_get_runtime_company_id();

//
// POST
//
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if ($mode === 'upload') {
        // ожидаем input name = xlsx_template
        $uploaded = fn_filter_uploaded_data('xlsx_template'); // массив файлов от fileuploader

        if (!empty($uploaded)) {
            $file = reset($uploaded);
            if (!empty($file['path']) && empty($file['error'])) {
                // защита: только .xlsx
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                if ($ext !== 'xlsx') {
                    fn_set_notification('E', __('error'), __('text_invalid_file_type'));
                } else {
                    // нормализуем имя и избегаем коллизий
                    $basename = SecurityHelper::sanitizeFileName(pathinfo($file['name'], PATHINFO_FILENAME));
                    $fname = $basename . '-' . substr(sha1(uniqid('', true)), 0, 8) . '.xlsx';

                    $dst = 'mwl_xlsx/templates/' . (int)$company_id . '/' . $fname;
                    $put = $storage->put($dst, ['file' => $file['path']]);

                    if ($put) {
                        $now = TIME;
                        db_query(
                            "INSERT INTO ?:mwl_xlsx_templates ?e",
                            [
                                'company_id' => (int)$company_id,
                                'name'       => $file['name'], // оригинальное имя
                                'path'       => $dst,           // относительный путь в сторадже
                                'size'       => (int)$file['size'],
                                'created_at' => $now,
                                'updated_at' => $now,
                            ]
                        );
                        fn_set_notification('N', __('notice'), __('file_uploaded'));
                    } else {
                        fn_set_notification('E', __('error'), __('cant_upload_file'));
                    }
                }
            } else {
                fn_set_notification('E', __('error'), __('cant_upload_file'));
            }
        }

        return [CONTROLLER_STATUS_OK, 'mwl_xlsx_templates.manage'];
    }

    if ($mode === 'delete' && !empty($_REQUEST['template_id'])) {
        $tpl = db_get_row(
            "SELECT * FROM ?:mwl_xlsx_templates WHERE template_id = ?i AND company_id IN (?n)",
            (int) $_REQUEST['template_id'],
            $company_id ? [$company_id] : [0, $company_id] // админ может видеть 0/все
        );
        if ($tpl) {
            $storage->delete($tpl['path']);
            db_query("DELETE FROM ?:mwl_xlsx_templates WHERE template_id = ?i", (int)$tpl['template_id']);
            fn_set_notification('N', __('notice'), __('deleted'));
        }
        return [CONTROLLER_STATUS_OK, 'mwl_xlsx_templates.manage'];
    }

    return [CONTROLLER_STATUS_OK];
}

//
// GET
//
if ($mode === 'manage') {
    $condition = $company_id
        ? db_quote(" WHERE company_id = ?i", $company_id)
        : '';
    $templates = db_get_array("SELECT * FROM ?:mwl_xlsx_templates ?p ORDER BY template_id DESC", $condition);

    Tygh::$app['view']->assign([
        'mwl_xlsx_templates' => $templates,
        'company_id'         => $company_id,
    ]);
}

