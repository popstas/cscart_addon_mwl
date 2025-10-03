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

    public function setStatus(int $company_id, string $entity_type, string $entity_status, string $planfix_status_id, string $planfix_status_name, string $planfix_status_type, bool $is_default = false): int
    {
        $data = [
            'company_id'         => $company_id,
            'entity_type'        => $entity_type,
            'entity_status'      => $entity_status,
            'planfix_status_id'  => $planfix_status_id,
            'planfix_status_name'=> $planfix_status_name,
            'planfix_status_type'=> $planfix_status_type,
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

    public function findLocalStatus(int $company_id, string $entity_type, string $planfix_status_id, string $planfix_status_type): ?array
    {
        return $this->db->getRow(
            'SELECT * FROM ?:mwl_planfix_status_map WHERE company_id = ?i AND entity_type = ?s AND planfix_status_id = ?s AND planfix_status_type = ?s',
            $company_id,
            $entity_type,
            $planfix_status_id,
            $planfix_status_type
        ) ?: null;
    }

    public function delete(int $map_id): void
    {
        $this->db->query('DELETE FROM ?:mwl_planfix_status_map WHERE map_id = ?i', $map_id);
    }
}
