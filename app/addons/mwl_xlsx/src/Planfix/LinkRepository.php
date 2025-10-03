<?php

namespace Tygh\Addons\MwlXlsx\Planfix;

use Tygh\Database\Connection;

class LinkRepository
{
    /** @var Connection */
    private $db;

    public function __construct(Connection $db)
    {
        $this->db = $db;
    }

    public function upsert(int $company_id, string $entity_type, int $entity_id, string $planfix_object_type, string $planfix_object_id, array $extra = []): int
    {
        $data = [
            'company_id'         => $company_id,
            'entity_type'        => $entity_type,
            'entity_id'          => $entity_id,
            'planfix_object_type'=> $planfix_object_type,
            'planfix_object_id'  => $planfix_object_id,
            'extra'              => $extra ? json_encode($extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'created_at'         => TIME,
            'updated_at'         => TIME,
        ];

        return (int) $this->db->query('REPLACE INTO ?:mwl_planfix_links ?e', $data);
    }

    public function findByEntity(int $company_id, string $entity_type, int $entity_id): ?array
    {
        return $this->db->getRow(
            'SELECT * FROM ?:mwl_planfix_links WHERE company_id = ?i AND entity_type = ?s AND entity_id = ?i',
            $company_id,
            $entity_type,
            $entity_id
        ) ?: null;
    }

    public function findByPlanfix(string $planfix_object_type, string $planfix_object_id, ?int $company_id = null): ?array
    {
        $conditions = ['planfix_object_type = ?s', 'planfix_object_id = ?s'];
        $params = [$planfix_object_type, $planfix_object_id];

        if ($company_id !== null) {
            $conditions[] = 'company_id = ?i';
            $params[] = $company_id;
        }

        $condition = $this->db->quote(implode(' AND ', $conditions), ...$params);

        return $this->db->getRow('SELECT * FROM ?:mwl_planfix_links WHERE ?p', $condition) ?: null;
    }

    public function delete(int $link_id): void
    {
        $this->db->query('DELETE FROM ?:mwl_planfix_links WHERE link_id = ?i', $link_id);
    }
}
