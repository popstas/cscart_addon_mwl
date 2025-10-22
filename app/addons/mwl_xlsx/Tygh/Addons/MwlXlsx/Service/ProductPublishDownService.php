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
     * Records the products found in the CSV as recently seen.
     *
     * @param array<int, array<string, mixed>> $rows
     *
     * @return array{processed: int, matched: int, missing: array<int, string>}
     */
    public function recordSeenProducts(array $rows): array
    {
        $now = time();
        $processed = 0;
        $matched = 0;
        $missing = [];
        $records = [];
        $lookup_codes = [];
        $rows_by_index = [];

        foreach ($rows as $index => $row) {
            $processed++;
            $row_number = isset($row['__row']) ? (int) $row['__row'] : ($index + 2);

            $product_id = isset($row['product_id']) ? (int) $row['product_id'] : 0;
            if ($product_id > 0) {
                $matched++;
                $changed_at = $this->extractChangedAt($row, $now);

                if (isset($records[$product_id])) {
                    $records[$product_id]['last_changed_at'] = max($records[$product_id]['last_changed_at'], $changed_at);
                    $records[$product_id]['last_seen_at'] = $now;
                } else {
                    $records[$product_id] = [
                        'product_id' => $product_id,
                        'last_seen_at' => $now,
                        'last_changed_at' => $changed_at,
                    ];
                }
                continue;
            }

            $code = isset($row['product_code']) ? trim((string) $row['product_code']) : '';
            if ($code === '') {
                $missing[] = sprintf('Row %d: missing product identifier', $row_number);
                continue;
            }

            $lookup_codes[$code] = true;
            $rows_by_index[$index] = [
                'code' => $code,
                'row_number' => $row_number,
                'changed_at' => $this->extractChangedAt($row, $now),
            ];
        }

        if ($lookup_codes) {
            $codes = array_keys($lookup_codes);
            $code_map = db_get_hash_single_array(
                'SELECT product_code, product_id FROM ?:products WHERE product_code IN (?a)',
                ['product_code', 'product_id'],
                $codes
            );

            foreach ($rows_by_index as $row_index => $info) {
                $code = $info['code'];
                $row_number = $info['row_number'];
                if (!isset($code_map[$code])) {
                    $missing[] = sprintf('Row %d: product code "%s" not found', $row_number, $code);
                    continue;
                }

                $product_id = (int) $code_map[$code];
                $matched++;
                if (isset($records[$product_id])) {
                    $records[$product_id]['last_changed_at'] = max($records[$product_id]['last_changed_at'], $info['changed_at']);
                    $records[$product_id]['last_seen_at'] = $now;
                } else {
                    $records[$product_id] = [
                        'product_id' => $product_id,
                        'last_seen_at' => $now,
                        'last_changed_at' => $info['changed_at'],
                    ];
                }
            }
        }

        foreach ($records as $product_id => $record) {
            $this->db->query(
                'INSERT INTO ?:mwl_product_publish_tracker ?e'
                . ' ON DUPLICATE KEY UPDATE last_seen_at = VALUES(last_seen_at),'
                . ' last_changed_at = GREATEST(COALESCE(last_changed_at, 0), VALUES(last_changed_at))',
                [
                    'product_id' => $product_id,
                    'last_seen_at' => (int) $record['last_seen_at'],
                    'last_changed_at' => (int) $record['last_changed_at'],
                ]
            );
        }

        return [
            'processed' => $processed,
            'matched' => $matched,
            'missing' => $missing,
        ];
    }

    /**
     * Publishes down products that were not seen within the given period.
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
            'SELECT tracker.product_id, tracker.last_seen_at, tracker.last_changed_at, p.status'
            . ' FROM ?:mwl_product_publish_tracker AS tracker'
            . ' INNER JOIN ?:products AS p ON p.product_id = tracker.product_id'
            . ' WHERE tracker.last_seen_at < ?i'
            . '   AND COALESCE(tracker.last_changed_at, tracker.last_seen_at) < ?i'
            . '   AND p.status IN (?a)'
            . ' ORDER BY tracker.last_seen_at ASC ?p',
            $cutoff,
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

    private function extractChangedAt(array $row, int $default): int
    {
        $candidates = [
            'changed_at',
            'updated_at',
            'timestamp',
            'last_updated',
            'last_update',
            'modified_at',
            'modified',
            'updated',
        ];

        foreach ($candidates as $candidate) {
            if (!isset($row[$candidate])) {
                continue;
            }

            $value = $row[$candidate];
            $timestamp = $this->normalizeTimestamp($value);
            if ($timestamp !== null) {
                return $timestamp;
            }
        }

        return $default;
    }

    private function normalizeTimestamp($value): ?int
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->getTimestamp();
        }

        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (is_numeric($value)) {
            $int = (int) $value;
            return $int > 0 ? $int : null;
        }

        if (is_string($value) && $value !== '') {
            $timestamp = strtotime($value);
            if ($timestamp !== false) {
                return $timestamp;
            }
        }

        return null;
    }
}
