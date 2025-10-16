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
        $filter_ids = $this->db->getColumn(
            'SELECT filter_id FROM ?:product_filters WHERE company_id = ?i AND (field_type = ?s OR feature_id > 0)',
            $company_id,
            'P'
        );

        if (!$filter_ids) {
            return [];
        }

        $filter_ids = array_filter(array_map('intval', $filter_ids));

        if (!$filter_ids) {
            return [];
        }

        $filters = fn_product_filters_get_filters([
            'filter_item_ids' => implode(',', $filter_ids),
        ], \DESCR_SL);

        $raw_filters = $this->db->getArray(
            'SELECT pf.*, pfd.filter FROM ?:product_filters AS pf'
            . ' LEFT JOIN ?:product_filter_descriptions AS pfd ON pfd.filter_id = pf.filter_id AND pfd.lang_code = ?s'
            . ' WHERE pf.filter_id IN (?n)',
            \DESCR_SL,
            $filter_ids
        );

        $raw_by_id = [];

        foreach ($raw_filters as $row) {
            $filter_id = (int) ($row['filter_id'] ?? 0);

            if ($filter_id <= 0) {
                continue;
            }

            if (isset($row['params']) && is_string($row['params']) && $row['params'] !== '') {
                $params = @unserialize($row['params'], ['allowed_classes' => false]);

                if (is_array($params)) {
                    $row['params'] = $params;
                } else {
                    unset($row['params']);
                }
            }

            $raw_by_id[$filter_id] = $row;
        }

        $result = [];

        foreach ($filter_ids as $filter_id) {
            $filter_id = (int) $filter_id;

            if ($filter_id <= 0) {
                continue;
            }

            $raw_data = $raw_by_id[$filter_id] ?? [];
            $core_data = $filters[$filter_id] ?? [];

            $result[$filter_id] = array_merge($raw_data, $core_data);
        }

        return $result;
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
