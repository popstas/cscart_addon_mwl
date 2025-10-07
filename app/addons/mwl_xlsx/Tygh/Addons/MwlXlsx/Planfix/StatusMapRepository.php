<?php

namespace Tygh\Addons\MwlXlsx\Planfix;

use Tygh\Database\Connection;

class StatusMapRepository
{
    /** @var Connection */
    private $db;

    public function __construct(Connection $db)
    {
        $this->db = $db;
    }

    public function setStatus(int $company_id, string $entity_type, string $entity_status, string $planfix_status_id, bool $is_default = false): int
    {
        $data = [
            'company_id'         => $company_id,
            'entity_type'        => $entity_type,
            'entity_status'      => $entity_status,
            'planfix_status_id'  => $planfix_status_id,
            'is_default'         => $is_default ? 1 : 0,
            'created_at'         => TIME,
            'updated_at'         => TIME,
        ];

        return (int) $this->db->query('REPLACE INTO ?:mwl_planfix_status_map ?e', $data);
    }

    public function findPlanfixStatus(int $company_id, string $entity_type, string $entity_status): ?array
    {
        return $this->db->getRow(
            'SELECT * FROM ?:mwl_planfix_status_map WHERE company_id = ?i AND entity_type = ?s AND entity_status = ?s',
            $company_id,
            $entity_type,
            $entity_status
        ) ?: null;
    }

    public function findLocalStatus(int $company_id, string $entity_type, string $planfix_status_id): ?array
    {
        return $this->db->getRow(
            'SELECT * FROM ?:mwl_planfix_status_map WHERE company_id = ?i AND entity_type = ?s AND planfix_status_id = ?s',
            $company_id,
            $entity_type,
            $planfix_status_id
        ) ?: null;
    }

    public function delete(int $map_id): void
    {
        $this->db->query('DELETE FROM ?:mwl_planfix_status_map WHERE map_id = ?i', $map_id);
    }

    public function getAllMappings(int $company_id, string $entity_type = ''): array
    {
        $condition = $company_id ? db_quote('WHERE company_id = ?i', $company_id) : '';
        if ($entity_type) {
            $condition .= ($condition ? ' AND ' : ' WHERE ') . db_quote('entity_type = ?s', $entity_type);
        }

        return $this->db->getArray('SELECT * FROM ?:mwl_planfix_status_map ?p ORDER BY entity_type, entity_status', $condition);
    }

    public function getEntityStatuses(string $type = 'O'): array
    {
        // Получаем статусы заказов из CS-Cart с описаниями
        $all_statuses = fn_get_statuses($type, [], true, false, DESCR_SL);

        $statuses = [];
        foreach ($all_statuses as $status_code => $status_data) {
            $statuses[$status_code] = [
                'status' => $status_code,
                'description' => $status_data['description'],
                'type' => $status_data['type']
            ];
        }

        return $statuses;
    }

    public function updateMapping(int $map_id, array $data): bool
    {
        $data['updated_at'] = TIME;

        $this->db->query('UPDATE ?:mwl_planfix_status_map SET ?u WHERE map_id = ?i', $data, $map_id);

        return true;
    }

    public function getMappingById(int $map_id): ?array
    {
        return $this->db->getRow('SELECT * FROM ?:mwl_planfix_status_map WHERE map_id = ?i', $map_id) ?: null;
    }
}
