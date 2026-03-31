<?php

namespace Tygh\Addons\MwlXlsx\Import;

use Tygh\Addons\MwlXlsx\Utility\CsvHelper;
use Tygh\Registry;

class ImportPrepareRunner
{
    public function run(array $request): array
    {
        $debug = isset($request['debug']) ? (bool) $request['debug'] : false;

        if ($debug) {
            fn_mwl_xlsx_log_debug('========================================');
            fn_mwl_xlsx_log_debug('Import prepare: syncing variation group features from CSV');
        }

        $csv_path = Registry::get('config.dir.root') . '/var/files/products.csv';
        if (isset($request['csv_path'])) {
            $csv_path = trim((string) $request['csv_path']);
        }

        if ($debug) {
            fn_mwl_xlsx_log_debug("CSV file path: {$csv_path}");
        }

        if (!file_exists($csv_path)) {
            $message = "CSV file not found: {$csv_path}";
            echo "[error] {$message}" . PHP_EOL;
            fn_mwl_xlsx_append_log($message);
            return [CONTROLLER_STATUS_NO_CONTENT];
        }

        if (!is_readable($csv_path)) {
            $message = "CSV file not readable: {$csv_path}";
            echo "[error] {$message}" . PHP_EOL;
            fn_mwl_xlsx_append_log($message);
            return [CONTROLLER_STATUS_NO_CONTENT];
        }

        $handle = fopen($csv_path, 'rb');
        if ($handle === false) {
            $message = "Failed to open CSV file: {$csv_path}";
            echo "[error] {$message}" . PHP_EOL;
            fn_mwl_xlsx_append_log($message);
            return [CONTROLLER_STATUS_NO_CONTENT];
        }

        $first_line = fgets($handle);
        if ($first_line === false) {
            fclose($handle);
            $message = "CSV file is empty: {$csv_path}";
            echo "[error] {$message}" . PHP_EOL;
            fn_mwl_xlsx_append_log($message);
            return [CONTROLLER_STATUS_NO_CONTENT];
        }

        $delimiter = CsvHelper::detectDelimiter($first_line);
        rewind($handle);

        $header = fgetcsv($handle, 0, $delimiter);
        if ($header === false) {
            fclose($handle);
            $message = "Failed to read CSV header";
            echo "[error] {$message}" . PHP_EOL;
            fn_mwl_xlsx_append_log($message);
            return [CONTROLLER_STATUS_NO_CONTENT];
        }

        $normalized_header = [];
        foreach ($header as $index => $column) {
            $normalized_header[$index] = CsvHelper::normalizeHeaderValue((string) $column, $index === 0);
        }

        $header_map = array_flip($normalized_header);

        $required_columns = ['variation group code', 'features'];
        $missing_columns = [];
        foreach ($required_columns as $required_column) {
            if (!isset($header_map[$required_column])) {
                $missing_columns[] = $required_column;
            }
        }

        if (!empty($missing_columns)) {
            fclose($handle);
            $message = "Missing required columns: " . implode(', ', $missing_columns);
            echo "[error] {$message}" . PHP_EOL;
            fn_mwl_xlsx_append_log($message);
            return [CONTROLLER_STATUS_NO_CONTENT];
        }

        $variation_group_code_index = $header_map['variation group code'];
        $features_index = $header_map['features'];
        $product_code_index = isset($header_map['product code']) ? $header_map['product code'] : null;

        $groups_data = [];

        $line_number = 1;
        while (($data = fgetcsv($handle, 0, $delimiter)) !== false) {
            $line_number++;

            if (count($data) < max($variation_group_code_index, $features_index) + 1) {
                if ($debug) {
                    fn_mwl_xlsx_log_debug("Line {$line_number}: insufficient columns");
                }
                continue;
            }

            $variation_group_code = trim((string) ($data[$variation_group_code_index] ?? ''));
            $features_string = trim((string) ($data[$features_index] ?? ''));
            $product_code = ($product_code_index !== null) ? trim((string) ($data[$product_code_index] ?? '')) : '';

            if ($variation_group_code === '' || $features_string === '') {
                continue;
            }

            $features = $this->parseFeaturesFromCsvRow($features_string);

            if (empty($features)) {
                continue;
            }

            if (!isset($groups_data[$variation_group_code])) {
                $groups_data[$variation_group_code] = ['features' => [], 'product_codes' => []];
            }

            $groups_data[$variation_group_code]['features'] = array_merge(
                $groups_data[$variation_group_code]['features'],
                $features
            );

            if ($product_code !== '') {
                $groups_data[$variation_group_code]['product_codes'][] = $product_code;
            }
        }

        fclose($handle);

        foreach ($groups_data as $group_code => &$group_data) {
            $unique_features = [];
            foreach ($group_data['features'] as $name => $type) {
                $unique_features[$name] = $type;
            }
            $group_data['features'] = $unique_features;
        }
        unset($group_data);

        if ($debug) {
            fn_mwl_xlsx_log_debug("Found " . count($groups_data) . " variation groups in CSV");
        }

        if (empty($groups_data)) {
            if ($debug) {
                fn_mwl_xlsx_log_debug("No variation groups found in CSV");
            }
            return [CONTROLLER_STATUS_NO_CONTENT];
        }

        $total_added = 0;
        $total_removed = 0;
        $total_errors = 0;
        $total_warnings = 0;
        $total_groups_created = 0;

        foreach ($groups_data as $group_code => $group_data) {
            if ($debug) {
                fn_mwl_xlsx_log_debug("Processing group: {$group_code}");
            }

            $group_id = db_get_field(
                "SELECT id FROM ?:product_variation_groups WHERE code = ?s LIMIT 1",
                $group_code
            );

            if (!$group_id) {
                $group_id = $this->createMissingGroup($group_code, $group_data['product_codes'] ?? [], $debug);
                if ($group_id) {
                    $total_groups_created++;
                } else {
                    continue;
                }
            }

            if ($debug) {
                fn_mwl_xlsx_log_debug("Group ID: {$group_id}");
            }

            $sync_result = $this->syncGroupFeaturesFromCsv($group_id, $group_data['features'], $debug);

            $total_added += count($sync_result['added']);
            $total_removed += count($sync_result['removed']);
            $total_errors += count($sync_result['errors']);
            $total_warnings += count($sync_result['warnings']);
        }

        $metrics = [
            'groups_processed' => count($groups_data),
            'groups_created' => $total_groups_created,
            'features_added' => $total_added,
            'features_removed' => $total_removed,
            'errors' => $total_errors,
        ];
        fn_mwl_xlsx_output_metrics('import_prepare', $metrics);

        if ($debug && $total_warnings > 0) {
            fn_mwl_xlsx_log_debug("Warnings: {$total_warnings} (features not found - not variation features)");
        }
        if ($debug) {
            fn_mwl_xlsx_log_debug('========================================');
        }

        return [CONTROLLER_STATUS_NO_CONTENT];
    }

    private function parseFeaturesFromCsvRow(string $features_string): array
    {
        $result = [];

        if (empty($features_string)) {
            return $result;
        }

        $features_string = trim($features_string, '\"\'');
        $parts = explode(';', $features_string);

        foreach ($parts as $part) {
            $part = trim($part);
            if (empty($part)) {
                continue;
            }

            $colon_pos = strpos($part, ':');
            if ($colon_pos === false) {
                continue;
            }

            $feature_name = trim(substr($part, 0, $colon_pos));
            $feature_value_part = trim(substr($part, $colon_pos + 1));

            if (preg_match('/^([STN])\\[/', $feature_value_part, $matches)) {
                $feature_type = $matches[1];
                $result[$feature_name] = $feature_type;
            }
        }

        return $result;
    }

    /**
     * Create a variation group that exists in CSV but not in the database.
     * Finds product IDs by product_codes from CSV and uses CS-Cart Service to create the group.
     */
    private function createMissingGroup(string $group_code, array $product_codes, bool $debug = false): ?int
    {
        if (empty($product_codes)) {
            if ($debug) {
                fn_mwl_xlsx_log_debug("Group '{$group_code}' not found, no product codes in CSV to create it");
            }
            return null;
        }

        $product_ids = db_get_fields(
            "SELECT product_id FROM ?:products WHERE product_code IN (?a) AND company_id = ?i",
            $product_codes,
            \Tygh\Registry::get('runtime.company_id') ?: 3
        );

        if (empty($product_ids)) {
            if ($debug) {
                fn_mwl_xlsx_log_debug("Group '{$group_code}' not found, no matching products in DB for codes: " . implode(', ', $product_codes));
            }
            return null;
        }

        try {
            $service = \Tygh\Addons\ProductVariations\ServiceProvider::getService();
            $result = $service->createGroup($product_ids, $group_code);

            $group = $result->getData('group');
            if ($group && $group->getId()) {
                $group_id = $group->getId();
                echo "[import_prepare] Created variation group '{$group_code}' (ID: {$group_id}) with " . count($product_ids) . " products" . PHP_EOL;
                fn_mwl_xlsx_append_log("[import_prepare] Created group '{$group_code}' (ID: {$group_id})");

                if ($debug) {
                    fn_mwl_xlsx_log_debug("Created group '{$group_code}' (ID: {$group_id}) with products: " . implode(', ', $product_ids));
                }

                return (int) $group_id;
            } else {
                $errors = $result->getErrors();
                $error_msg = !empty($errors) ? implode('; ', $errors) : 'unknown error';
                echo "[warning] Failed to create group '{$group_code}': {$error_msg}" . PHP_EOL;
                fn_mwl_xlsx_append_log("[import_prepare] Failed to create group '{$group_code}': {$error_msg}");

                if ($debug) {
                    fn_mwl_xlsx_log_debug("Failed to create group '{$group_code}': {$error_msg}");
                }

                return null;
            }
        } catch (\Exception $e) {
            echo "[error] Exception creating group '{$group_code}': " . $e->getMessage() . PHP_EOL;
            fn_mwl_xlsx_append_log("[import_prepare] Exception creating group '{$group_code}': " . $e->getMessage());
            return null;
        }
    }

    private function findFeatureIdByName(string $feature_name, string $lang_code = 'en'): ?int
    {
        $feature_id = db_get_field(
            "SELECT f.feature_id " .
            "FROM ?:product_features f " .
            "INNER JOIN ?:product_features_descriptions fd ON f.feature_id = fd.feature_id " .
            "WHERE fd.description = ?s AND fd.lang_code = ?s AND f.purpose = 'group_variation_catalog_item' " .
            "LIMIT 1",
            $feature_name,
            $lang_code
        );

        return $feature_id ? (int) $feature_id : null;
    }

    private function syncGroupFeaturesFromCsv(int $group_id, array $csv_features, bool $debug = false): array
    {
        $result = [
            'added' => [],
            'removed' => [],
            'errors' => [],
            'warnings' => []
        ];

        $current_db_features = db_get_array(
            "SELECT feature_id FROM ?:product_variation_group_features WHERE group_id = ?i ORDER BY feature_id",
            $group_id
        );
        $current_feature_ids = empty($current_db_features) ? [] : array_column($current_db_features, 'feature_id');

        if ($debug) {
            fn_mwl_xlsx_log_debug("Current features in group: " . (empty($current_feature_ids) ? 'none' : implode(', ', $current_feature_ids)));
        }

        $csv_feature_ids = [];
        foreach ($csv_features as $feature_name => $feature_type) {
            $feature_id = $this->findFeatureIdByName($feature_name);
            if ($feature_id === null) {
                $result['warnings'][] = "Feature '{$feature_name}' not found in database (not a variation feature)";
                if ($debug) {
                    fn_mwl_xlsx_log_debug("Feature '{$feature_name}' not found in database (not a variation feature)");
                }
                continue;
            }
            $csv_feature_ids[$feature_id] = $feature_name;
        }

        if ($debug) {
            $features_list = [];
            foreach ($csv_feature_ids as $fid => $name) {
                $features_list[] = "{$name} (#{$fid})";
            }
            fn_mwl_xlsx_log_debug("Features from CSV: " . (empty($features_list) ? 'none' : implode(', ', $features_list)));
        }

        $features_to_add = array_diff(array_keys($csv_feature_ids), $current_feature_ids);
        $features_to_remove = array_diff($current_feature_ids, array_keys($csv_feature_ids));

        if ($debug) {
            fn_mwl_xlsx_log_debug("Features to add: " . (empty($features_to_add) ? 'none' : implode(', ', $features_to_add)));
            fn_mwl_xlsx_log_debug("Features to remove: " . (empty($features_to_remove) ? 'none' : implode(', ', $features_to_remove)));
        }

        foreach ($features_to_add as $feature_id) {
            $inserted = db_query(
                "INSERT INTO ?:product_variation_group_features (group_id, feature_id, purpose) VALUES (?i, ?i, ?s)",
                $group_id,
                $feature_id,
                'group_variation_catalog_item'
            );

            if ($inserted) {
                $result['added'][] = $feature_id;
                $feature_name = $csv_feature_ids[$feature_id] ?? "Feature #{$feature_id}";
                if ($debug) {
                    fn_mwl_xlsx_log_debug("✓ Added feature #{$feature_id} ({$feature_name})");
                }
            } else {
                $result['errors'][] = "Failed to add feature #{$feature_id}";
                echo "  - [error] Failed to add feature #{$feature_id}" . PHP_EOL;
            }
        }

        foreach ($features_to_remove as $feature_id) {
            $deleted = db_query(
                "DELETE FROM ?:product_variation_group_features WHERE group_id = ?i AND feature_id = ?i",
                $group_id,
                $feature_id
            );

            if ($deleted) {
                $result['removed'][] = $feature_id;
                if ($debug) {
                    fn_mwl_xlsx_log_debug("✓ Removed feature #{$feature_id}");
                }
            } else {
                $result['errors'][] = "Failed to remove feature #{$feature_id}";
                echo "  - [error] Failed to remove feature #{$feature_id}" . PHP_EOL;
            }
        }

        return $result;
    }
}
