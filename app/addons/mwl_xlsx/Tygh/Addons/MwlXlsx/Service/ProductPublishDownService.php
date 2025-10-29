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
     * @return array{
     *     candidates: int,
     *     disabled: array<int, int>,
     *     errors: array<int, string>,
     *     limit_reached: bool,
     *     outdated_total: int,
     *     aborted_by_limit: bool,
     * }
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

        $outdated_total = (int) $this->db->getField(
            'SELECT COUNT(*)'
            . ' FROM ?:products AS p'
            . ' WHERE (CASE WHEN p.updated_timestamp > 0 THEN p.updated_timestamp ELSE p.timestamp END) < ?i'
            . '   AND p.status IN (?a)',
            $cutoff,
            ['A', 'H', 'P']
        );

        if ($limit > 0 && $outdated_total > $limit) {
            $this->logDebug(sprintf('[publish_down] Aborting: %d outdated products exceed the limit of %d',
                $outdated_total,
                $limit
            ));

            return [
                'candidates' => $outdated_total,
                'disabled' => [],
                'errors' => [],
                'limit_reached' => false,
                'outdated_total' => $outdated_total,
                'aborted_by_limit' => true,
            ];
        }

        $this->logDebug(sprintf('[publish_down] Starting run: period=%d seconds, cutoff=%d, limit=%s',
            $period_seconds,
            $cutoff,
            $limit > 0 ? (string) $limit : 'none'
        ));

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

        $total_candidates = count($rows);

        $this->logDebug(sprintf('[publish_down] Found %d candidate(s) for disabling', $total_candidates));

        $disabled = [];
        $errors = [];

        foreach ($rows as $index => $row) {
            $product_id = (int) ($row['product_id'] ?? 0);
            if ($product_id <= 0) {
                continue;
            }

            $status = (string) ($row['status'] ?? '');
            $updated_at = (int) ($row['updated_at'] ?? 0);

            $this->logDebug(sprintf('[publish_down] #%d/%d: product_id=%d status=%s updated_at=%d',
                $index + 1,
                $total_candidates,
                $product_id,
                $status,
                $updated_at
            ));

            if ($this->disableProduct($product_id, $status, $updated_at)) {
                $disabled[] = $product_id;
            } else {
                $error_message = sprintf('Failed to publish down product #%d', $product_id);
                $errors[] = $error_message;
                $this->logDebug('[publish_down] ' . $error_message);
            }
        }

        return [
            'candidates' => $outdated_total,
            'disabled' => $disabled,
            'errors' => $errors,
            'limit_reached' => $limit > 0 && $total_candidates >= $limit,
            'outdated_total' => $outdated_total,
            'aborted_by_limit' => false,
        ];
    }

    private function disableProduct(int $product_id, string $previous_status, int $updated_at): bool
    {
        if ($product_id <= 0) {
            return false;
        }

        $this->db->query('UPDATE ?:products SET status = ?s WHERE product_id = ?i', 'D', $product_id);

        $this->logDebug(sprintf('[publish_down] Disabled product #%d (previous_status=%s, updated_at=%d)',
            $product_id,
            $previous_status,
            $updated_at
        ));

        return true;
    }

    private function logDebug(string $message): void
    {
        echo $message . PHP_EOL;

        if (function_exists('fn_mwl_xlsx_append_log')) {
            \fn_mwl_xlsx_append_log($message);
        }
    }
}
