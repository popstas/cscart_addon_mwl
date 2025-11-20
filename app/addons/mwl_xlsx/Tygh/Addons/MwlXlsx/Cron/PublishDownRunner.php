<?php

namespace Tygh\Addons\MwlXlsx\Cron;

use Tygh\Addons\MwlXlsx\Service\ProductPublishDownService;
use Tygh\Addons\MwlXlsx\Utility\CsvHelper;
use Tygh\Registry;

class PublishDownRunner
{
    private ProductPublishDownService $publish_down_service;

    public function __construct(ProductPublishDownService $publish_down_service)
    {
        $this->publish_down_service = $publish_down_service;
    }

    public function disableOutdatedProducts(int $period_seconds, int $limit, string $mode): array
    {
        $publish_summary = $this->publish_down_service->publishDownOutdated($period_seconds, $limit);

        if (!empty($publish_summary['aborted_by_limit'])) {
            $error_message = __('mwl_xlsx.publish_down_limit_exceeded', [
                '[count]' => $publish_summary['outdated_total'] ?? 0,
                '[limit]' => $limit,
            ]);

            echo '[error] ' . $error_message . PHP_EOL;

            $metrics = [
                'candidates' => $publish_summary['candidates'],
                'outdated_total' => $publish_summary['outdated_total'],
                'disabled' => count($publish_summary['disabled']),
                'errors' => count($publish_summary['errors']),
                'aborted_by_limit' => 1,
            ];
            fn_mwl_xlsx_output_metrics($mode, $metrics);

            fn_mwl_xlsx_append_log('[error] ' . $error_message);

            exit(1);
        }

        $metrics = [
            'candidates' => $publish_summary['candidates'],
            'outdated_total' => $publish_summary['outdated_total'],
            'disabled' => count($publish_summary['disabled']),
            'errors' => count($publish_summary['errors']),
            'aborted_by_limit' => $publish_summary['aborted_by_limit'] ? 1 : 0,
        ];
        fn_mwl_xlsx_output_metrics($mode, $metrics);

        foreach ($publish_summary['errors'] as $error) {
            echo '[error] ' . $error . PHP_EOL;
            fn_mwl_xlsx_append_log('[error] ' . $error);
        }

        if ($publish_summary['limit_reached'] && $limit > 0) {
            $limit_message = __('mwl_xlsx.publish_down_limit_reached', ['[limit]' => $limit]);
            echo '[info] ' . $limit_message . PHP_EOL;
            fn_mwl_xlsx_append_log('[info] ' . $limit_message);
        }

        $log_payload = array_merge($metrics, [
            'disabled_product_ids' => $publish_summary['disabled'],
            'error_messages' => $publish_summary['errors'],
            'period_seconds' => $period_seconds,
            'limit' => $limit,
        ]);
        fn_mwl_xlsx_append_log(sprintf('[%s] Metrics: %s', $mode, json_encode($log_payload, JSON_UNESCAPED_UNICODE)));

        return [CONTROLLER_STATUS_NO_CONTENT];
    }

    public function disableMissingProductsFromCsv(string $csv_path, string $mode): array
    {
        if ($csv_path === '') {
            $csv_path = Registry::get('config.dir.root') . '/var/files/products.csv';
        }

        if (!file_exists($csv_path)) {
            $message = "CSV file not found: {$csv_path}";
            echo '[error] ' . $message . PHP_EOL;
            fn_mwl_xlsx_append_log('[publish_down_csv] ' . $message);

            return [CONTROLLER_STATUS_NO_CONTENT];
        }

        echo 'publish_down_missing_products_csv: reading CSV...' . PHP_EOL;

        $result = $this->readProductsCsv($csv_path);

        if (!empty($result['errors'])) {
            foreach ($result['errors'] as $error) {
                echo '[error] ' . $error . PHP_EOL;
                fn_mwl_xlsx_append_log('[publish_down_csv] ' . $error);
            }

            return [CONTROLLER_STATUS_NO_CONTENT];
        }

        $rows = $result['rows'];

        if (!$rows) {
            $message = 'CSV file is empty or has no valid rows';
            echo '[error] ' . $message . PHP_EOL;
            fn_mwl_xlsx_append_log('[publish_down_csv] ' . $message);

            return [CONTROLLER_STATUS_NO_CONTENT];
        }

        echo 'publish_down_missing_products_csv: processing ' . count($rows) . ' CSV rows...' . PHP_EOL;

        $csv_groups = [];
        foreach ($rows as $row) {
            $group_code = $row['variation_group_code'];
            $product_code = $row['product_code'];

            if (!isset($csv_groups[$group_code])) {
                $csv_groups[$group_code] = [];
            }

            $csv_groups[$group_code][] = $product_code;
        }

        $groups_processed = 0;
        $products_checked = 0;
        $disabled_product_ids = [];
        $errors = [];

        echo 'publish_down_missing_products_csv: found ' . count($csv_groups) . ' unique variation groups...' . PHP_EOL;

        foreach ($csv_groups as $group_code => $csv_product_codes) {
            $group_id = db_get_field(
                'SELECT id FROM ?:product_variation_groups WHERE code = ?s',
                $group_code
            );

            if (!$group_id) {
                $warning = "Variation group not found in database: {$group_code}";
                echo '[info] ' . $warning . PHP_EOL;
                fn_mwl_xlsx_append_log('[publish_down_csv] ' . $warning);
                continue;
            }

            $group_product_ids = db_get_fields(
                'SELECT product_id FROM ?:product_variation_group_products WHERE group_id = ?i',
                $group_id
            );

            if (!$group_product_ids) {
                continue;
            }

            $db_products = db_get_hash_array(
                'SELECT product_id, product_code FROM ?:products WHERE product_id IN (?n)',
                'product_id',
                $group_product_ids
            );

            $products_checked += count($db_products);
            $csv_product_codes_map = array_flip($csv_product_codes);

            foreach ($db_products as $product_id => $product) {
                $product_code = $product['product_code'];

                if (!isset($csv_product_codes_map[$product_code])) {
                    $current_status = db_get_field(
                        'SELECT status FROM ?:products WHERE product_id = ?i',
                        $product_id
                    );

                    if ($current_status !== 'D') {
                        $updated = db_query(
                            'UPDATE ?:products SET status = ?s WHERE product_id = ?i',
                            'D',
                            $product_id
                        );

                        if ($updated) {
                            $disabled_product_ids[] = (int) $product_id;
                            echo "[disabled] Product ID: {$product_id}, Code: {$product_code}, Group: {$group_code}" . PHP_EOL;
                            fn_mwl_xlsx_append_log("[publish_down_csv] Disabled product {$product_id} (code: {$product_code}, group: {$group_code})");
                        } else {
                            $error_msg = "Failed to disable product {$product_id} (code: {$product_code})";
                            $errors[] = $error_msg;
                            echo '[error] ' . $error_msg . PHP_EOL;
                            fn_mwl_xlsx_append_log('[publish_down_csv] ' . $error_msg);
                        }
                    }
                }
            }

            $groups_processed++;
        }

        $metrics = [
            'groups_in_csv' => count($csv_groups),
            'groups_processed' => $groups_processed,
            'products_checked' => $products_checked,
            'disabled' => count($disabled_product_ids),
            'errors' => count($errors),
        ];
        fn_mwl_xlsx_output_metrics($mode, $metrics);

        $log_payload = array_merge($metrics, [
            'disabled_product_ids' => $disabled_product_ids,
            'error_messages' => $errors,
        ]);
        fn_mwl_xlsx_append_log(sprintf('[%s] Metrics: %s', $mode, json_encode($log_payload, JSON_UNESCAPED_UNICODE)));

        return [CONTROLLER_STATUS_NO_CONTENT];
    }

    private function readProductsCsv(string $path): array
    {
        $rows = [];
        $errors = [];

        if (!file_exists($path)) {
            return [
                'rows' => $rows,
                'errors' => ["CSV file not found: {$path}"],
            ];
        }

        if (!is_readable($path)) {
            return [
                'rows' => $rows,
                'errors' => ["CSV file not readable: {$path}"],
            ];
        }

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return [
                'rows' => $rows,
                'errors' => ["Failed to open CSV file: {$path}"],
            ];
        }

        $first_line = fgets($handle);

        if ($first_line === false) {
            fclose($handle);

            return [
                'rows' => $rows,
                'errors' => ['CSV file is empty'],
            ];
        }

        $delimiter = CsvHelper::detectDelimiter($first_line);
        rewind($handle);

        $header = fgetcsv($handle, 0, $delimiter);

        if ($header === false) {
            fclose($handle);

            return [
                'rows' => $rows,
                'errors' => ['Failed to read CSV header'],
            ];
        }

        $normalized_header = [];

        foreach ($header as $index => $column) {
            $normalized_header[$index] = CsvHelper::normalizeHeaderValue((string) $column, $index === 0);
        }

        $header_map = array_flip($normalized_header);

        if (!isset($header_map['variation group code']) || !isset($header_map['product code'])) {
            fclose($handle);

            return [
                'rows' => $rows,
                'errors' => ['Required columns missing'],
            ];
        }

        $variation_group_code_index = $header_map['variation group code'];
        $product_code_index = $header_map['product code'];

        $line_number = 1;

        while (($data = fgetcsv($handle, 0, $delimiter)) !== false) {
            $line_number++;

            if (count($data) < max($variation_group_code_index, $product_code_index) + 1) {
                $errors[] = "Line {$line_number}: insufficient columns";
                continue;
            }

            $variation_group_code = trim((string) ($data[$variation_group_code_index] ?? ''));
            $product_code = trim((string) ($data[$product_code_index] ?? ''));

            if ($variation_group_code === '' || $product_code === '') {
                continue;
            }

            $rows[] = [
                'variation_group_code' => $variation_group_code,
                'product_code' => $product_code,
            ];
        }

        fclose($handle);

        return [
            'rows' => $rows,
            'errors' => $errors,
        ];
    }
}
