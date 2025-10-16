<?php

namespace Tygh\Addons\MwlXlsx\Repository;

use Tygh\Database\Connection;

class FilterRepository
{
    /** @var \Tygh\Database\Connection */
    private $db;

    public function __construct(Connection $db)
    {
        $this->db = $db;
    }

    /**
     * Returns price/feature filters for the given company.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getCompanyFilters(int $company_id): array
    {
        $filters = [];
        $rows = $this->db->getArray(
            'SELECT * FROM ?:product_filters WHERE company_id = ?i AND (field_type = ?s OR feature_id > 0)',
            $company_id,
            'P'
        );

        if (!$rows) {
            return [];
        }

        foreach ($rows as $row) {
            $filter_id = (int) $row['filter_id'];

            if (!$filter_id) {
                continue;
            }

            $filters[$filter_id] = $row;
        }

        if (!$filters) {
            return [];
        }

        return $filters;
    }

    /**
     * @param array<int> $filter_ids
     */
    public function deleteFilters(array $filter_ids): void
    {
        foreach ($filter_ids as $filter_id) {
            $filter_id = (int) $filter_id;

            if ($filter_id <= 0) {
                continue;
            }

            fn_delete_product_filter($filter_id);
        }
    }
}
