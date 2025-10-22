<?php

namespace Tygh\Addons\MwlXlsx\Service;

use Tygh\Database\Connection;

class ProductPublishDownService
{
    /** @var Connection */
    private $db;

    public function __construct(Connection $db)
    {
        $this->db = $db;
    }

    /**
     * Publishes down products that were not updated within the given period.
     *
     * @param int $period_seconds Non-negative period in seconds.
     * @param int $limit Maximum number of products to publish down (0 = unlimited).
     *
     * @return array{candidates: int, disabled: array<int, int>, errors: array<int, string>, limit_reached: bool}
     */
    public function publishDownOutdated(int $period_seconds, int $limit = 0): array
    {
        $period_seconds = max(0, $period_seconds);
        $cutoff = time() - $period_seconds;

        $limit = (int) $limit;
        $limit_clause = '';
        if ($limit > 0) {
            $limit_clause = db_quote(' LIMIT ?i', $limit);
        }

        $rows = $this->db->getArray(
            'SELECT p.product_id, p.status,'
            . ' CASE WHEN p.updated_timestamp > 0 THEN p.updated_timestamp ELSE p.timestamp END AS updated_at'
            . ' FROM ?:products AS p'
            . ' WHERE (CASE WHEN p.updated_timestamp > 0 THEN p.updated_timestamp ELSE p.timestamp END) < ?i'
            . '   AND p.status IN (?a)'
            . ' ORDER BY (CASE WHEN p.updated_timestamp > 0 THEN p.updated_timestamp ELSE p.timestamp END) ASC ?p',
            $cutoff,
            ['A', 'H', 'P'],
            $limit_clause
        );

        $disabled = [];
        $errors = [];

        foreach ($rows as $row) {
            $product_id = (int) ($row['product_id'] ?? 0);
            if ($product_id <= 0) {
                continue;
            }

            if ($this->disableProduct($product_id)) {
                $disabled[] = $product_id;
            } else {
                $errors[] = sprintf('Failed to publish down product #%d', $product_id);
            }
        }

        return [
            'candidates' => count($rows),
            'disabled' => $disabled,
            'errors' => $errors,
            'limit_reached' => $limit > 0 && count($rows) >= $limit,
        ];
    }

    private function disableProduct(int $product_id): bool
    {
        if ($product_id <= 0) {
            return false;
        }

        if (function_exists('fn_tools_update_status')) {
            $result = fn_tools_update_status('products', $product_id, 'D', '', false, false, 0);
            if ($result !== false) {
                \fn_mwl_xlsx_append_log(sprintf('[publish_down] Disabled product #%d via fn_tools_update_status', $product_id));
                return true;
            }
        }

        $this->db->query('UPDATE ?:products SET status = ?s WHERE product_id = ?i', 'D', $product_id);
        \fn_mwl_xlsx_append_log(sprintf('[publish_down] Disabled product #%d directly', $product_id));

        return true;
    }
}
