<?php

namespace Tygh\Addons\MwlXlsx\Repository;

use Tygh\Database\Connection;

class FilterRepository
{
    private const FILTER_TYPE = 'P';
    private const FIELD_TYPE = 'P';

    /** @var \Tygh\Database\Connection */
    private $db;

    public function __construct(Connection $db)
    {
        $this->db = $db;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getCompanyPriceFilters(int $company_id): array
    {
        $filters = $this->db->getHashArray(
            'SELECT * FROM ?:product_filters WHERE company_id = ?i AND filter_type = ?s AND field_type = ?s',
            'filter_id',
            $company_id,
            self::FILTER_TYPE,
            self::FIELD_TYPE
        );

        if (!$filters) {
            return [];
        }

        $filter_ids = array_keys($filters);
        $params = $this->db->getArray(
            'SELECT filter_id, param_name, param_value FROM ?:product_filter_params WHERE filter_id IN (?n)',
            $filter_ids
        );

        foreach ($params as $param) {
            $filter_id = (int) $param['filter_id'];

            if (!isset($filters[$filter_id])) {
                continue;
            }

            if (!isset($filters[$filter_id]['params']) || !is_array($filters[$filter_id]['params'])) {
                $filters[$filter_id]['params'] = [];
            }

            $filters[$filter_id]['params'][$param['param_name']] = $param['param_value'];
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
