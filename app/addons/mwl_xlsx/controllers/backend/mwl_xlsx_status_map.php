<?php

use Tygh\Registry;

if (!defined('BOOTSTRAP')) { die('Access denied'); }

$status_map_repository = fn_mwl_planfix_status_map_repository();

// --- POST ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $company_id = fn_get_runtime_company_id();

    if ($mode === 'add' || $mode === 'update') {
        $map_id = isset($_REQUEST['map_id']) ? (int) $_REQUEST['map_id'] : 0;
        $entity_type = isset($_REQUEST['entity_type']) ? (string) $_REQUEST['entity_type'] : 'order';
        $entity_status = isset($_REQUEST['entity_status']) ? (string) $_REQUEST['entity_status'] : '';
        $planfix_status_id = isset($_REQUEST['planfix_status_id']) ? (string) $_REQUEST['planfix_status_id'] : '';
        $planfix_status_name = isset($_REQUEST['planfix_status_name']) ? (string) $_REQUEST['planfix_status_name'] : '';
        $is_default = isset($_REQUEST['is_default']) ? (bool) $_REQUEST['is_default'] : false;

        if (!$entity_status || !$planfix_status_id) {
            fn_set_notification('E', __('error'), __('mwl_xlsx.status_map_fill_required_fields'));
            return [CONTROLLER_STATUS_OK, 'mwl_xlsx_status_map.manage'];
        }

        if ($mode === 'add') {
            // Проверяем, существует ли уже маппинг
            $existing = $status_map_repository->findPlanfixStatus($company_id, $entity_type, $entity_status);
            if ($existing) {
                fn_set_notification('E', __('error'), __('mwl_xlsx.status_map_already_exists'));
                return [CONTROLLER_STATUS_OK, 'mwl_xlsx_status_map.manage'];
            }

            $status_map_repository->setStatus($company_id, $entity_type, $entity_status, $planfix_status_id, $is_default);
            fn_set_notification('N', __('notice'), __('mwl_xlsx.status_map_added'));
        } else {
            // Обновляем существующий маппинг
            $data = [
                'entity_type' => $entity_type,
                'entity_status' => $entity_status,
                'planfix_status_id' => $planfix_status_id,
                'planfix_status_name' => $planfix_status_name,
                'is_default' => $is_default ? 1 : 0,
            ];
            $status_map_repository->updateMapping($map_id, $data);
            fn_set_notification('N', __('notice'), __('mwl_xlsx.status_map_updated'));
        }

        return [CONTROLLER_STATUS_OK, 'mwl_xlsx_status_map.manage'];
    }

    if ($mode === 'delete' && !empty($_REQUEST['map_id'])) {
        $map_id = (int) $_REQUEST['map_id'];
        $status_map_repository->delete($map_id);
        fn_set_notification('N', __('notice'), __('mwl_xlsx.status_map_deleted'));
        return [CONTROLLER_STATUS_OK, 'mwl_xlsx_status_map.manage'];
    }

    return [CONTROLLER_STATUS_OK];
}

// --- GET ---
if ($mode === 'manage') {
    $company_id = fn_get_runtime_company_id();

    // Получаем все маппинги для компании
    $mappings = $status_map_repository->getAllMappings($company_id);

    // Получаем доступные статусы заказов из CS-Cart
    $entity_statuses = $status_map_repository->getEntityStatuses('O');

    // Группируем маппинги по типу сущности для удобства отображения
    $grouped_mappings = [];
    foreach ($mappings as $mapping) {
        $grouped_mappings[$mapping['entity_type']][] = $mapping;
    }

    Tygh::$app['view']->assign([
        'mappings' => $mappings,
        'grouped_mappings' => $grouped_mappings,
        'entity_statuses' => $entity_statuses,
        'company_id' => $company_id,
    ]);
}

if ($mode === 'add') {
    $company_id = fn_get_runtime_company_id();

    // Получаем доступные статусы заказов из CS-Cart
    $entity_statuses = $status_map_repository->getEntityStatuses('O');

    Tygh::$app['view']->assign([
        'entity_statuses' => $entity_statuses,
        'company_id' => $company_id,
    ]);
}

if ($mode === 'update') {
    $company_id = fn_get_runtime_company_id();
    $map_id = isset($_REQUEST['map_id']) ? (int) $_REQUEST['map_id'] : 0;

    if (!$map_id) {
        return [CONTROLLER_STATUS_NO_PAGE];
    }

    // Получаем маппинг для редактирования
    $mapping = $status_map_repository->getMappingById($map_id);
    if (!$mapping) {
        return [CONTROLLER_STATUS_NO_PAGE];
    }

    // Получаем доступные статусы заказов из CS-Cart
    $entity_statuses = $status_map_repository->getEntityStatuses('O');

    Tygh::$app['view']->assign([
        'mapping' => $mapping,
        'entity_statuses' => $entity_statuses,
        'company_id' => $company_id,
    ]);
}
