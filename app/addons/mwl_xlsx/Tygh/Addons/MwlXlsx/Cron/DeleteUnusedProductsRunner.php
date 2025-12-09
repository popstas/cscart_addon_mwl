<?php

namespace Tygh\Addons\MwlXlsx\Cron;

use Tygh\Registry;

class DeleteUnusedProductsRunner
{
    public function run(bool $dry_run, string $mode): array
    {
        echo 'delete_unused_products: check tables...' . PHP_EOL;

        if (isset($_REQUEST['dry_run'])) {
            $dry_run_value = (string) $_REQUEST['dry_run'];
            $dry_run = in_array(strtolower($dry_run_value), ['1', 'y', 'yes', 'true'], true);
        }

        $critical_tables = [
            '?:discussion' => [
                'columns' => ['object_id'],
                'condition' => "object_type = 'P'",
            ],
            '?:mwl_xlsx_list_products' => [
                'columns' => ['product_id'],
            ],
            '?:order_details' => [
                'columns' => ['product_id'],
            ],
            '?:product_reviews' => [
                'columns' => ['product_id'],
            ],
            '?:product_sales' => [
                'columns' => ['product_id'],
            ],
            '?:rma_return_products' => [
                'columns' => ['product_id'],
            ],
            '?:user_session_products' => [
                'columns' => ['product_id'],
            ],
            '?:product_subscriptions' => [
                'columns' => ['product_id'],
            ],
        ];

        $existing_tables = [];
        $referenced_product_ids = [];
        $table_prefix = (string) Registry::get('config.table_prefix') ?? '';

        foreach ($critical_tables as $table => $columns) {
            $table_name = str_replace('?:', $table_prefix, $table);
            $table_exists = (bool) db_get_field('SHOW TABLES LIKE ?l', $table_name);

            if (!$table_exists) {
                $warning_message = __('mwl_xlsx.delete_unused_products_table_missing', ['[table]' => $table_name]);
                echo '[warning] ' . $warning_message . PHP_EOL;
                fn_mwl_xlsx_append_log('[delete_unused] ' . $warning_message);

                continue;
            }

            $table_columns = (array) ($columns['columns'] ?? []);
            $table_condition = isset($columns['condition']) && $columns['condition'] !== ''
                ? ' AND ' . $columns['condition']
                : '';

            if (!$table_columns) {
                continue;
            }

            $existing_tables[$table] = [
                'columns' => $table_columns,
                'condition' => $table_condition,
            ];

            foreach ($table_columns as $column) {
                $rows = db_get_fields("SELECT DISTINCT $column FROM $table WHERE $column IS NOT NULL$table_condition");

                foreach ($rows as $value) {
                    $product_id = (int) $value;

                    if ($product_id > 0) {
                        $referenced_product_ids[$product_id] = true;
                    }
                }
            }
        }

        $all_product_ids = array_map(
            'intval',
            db_get_fields(
                'SELECT product_id FROM ?:products WHERE product_type IN(?a) AND status = ?s',
                ['P', 'V'],
                'D'
            )
        );

        if (!$all_product_ids) {
            $message = __('mwl_xlsx.delete_unused_products_empty');
            echo $message . PHP_EOL;
            fn_mwl_xlsx_append_log('[delete_unused] ' . $message);

            return [CONTROLLER_STATUS_NO_CONTENT];
        }

        $in_use_lookup = $referenced_product_ids;
        $unused_product_ids = [];

        foreach ($all_product_ids as $product_id) {
            if ($product_id <= 0) {
                continue;
            }

            if (!isset($in_use_lookup[$product_id])) {
                $unused_product_ids[] = $product_id;
            }
        }

        sort($unused_product_ids);

        $disabled_lookup = array_fill_keys($all_product_ids, true);
        $referenced_disabled_count = 0;

        foreach ($referenced_product_ids as $product_id => $flag) {
            if (isset($disabled_lookup[$product_id])) {
                $referenced_disabled_count++;
            }
        }

        $summary_message = __('mwl_xlsx.delete_unused_products_summary', [
            '[disabled_total]' => count($all_product_ids),
            '[referenced_disabled]' => $referenced_disabled_count,
            '[candidates]' => count($unused_product_ids),
        ]);

        echo $summary_message . PHP_EOL;

        if ($dry_run) {
            $dry_run_message = __('mwl_xlsx.delete_unused_products_dry_run_enabled');
            echo '[info] ' . $dry_run_message . PHP_EOL;
            fn_mwl_xlsx_append_log('[delete_unused] ' . $dry_run_message);
        }

        if (!$unused_product_ids) {
            $message = __('mwl_xlsx.delete_unused_products_none');
            echo '[info] ' . $message . PHP_EOL;

            $metrics = [
                'disabled' => count($all_product_ids),
                'referenced_disabled' => $referenced_disabled_count,
                'deleted' => 0,
                'skipped' => 0,
                'errors' => 0,
            ];
            fn_mwl_xlsx_output_metrics($mode, $metrics);

            fn_mwl_xlsx_append_log('[delete_unused] ' . $message);

            return [CONTROLLER_STATUS_NO_CONTENT];
        }

        $deleted_products = [];
        $skipped_products = [];
        $errors = [];
        $planned_products = [];

        $check_references = static function (int $product_id) use ($existing_tables): array {
            $references = [];

            foreach ($existing_tables as $table => $columns) {
                foreach ($columns['columns'] as $column) {
                    $condition = $columns['condition'] ?? '';
                    $is_linked = (bool) db_get_field(
                        "SELECT 1 FROM $table WHERE $column = ?i$condition LIMIT 1",
                        $product_id
                    );

                    if ($is_linked) {
                        $references[] = sprintf('%s.%s', str_replace('?:', '', $table), $column);
                        break;
                    }
                }
            }

            return $references;
        };

        foreach ($unused_product_ids as $product_id) {
            $references = $check_references($product_id);

            if ($references) {
                $reference_message = __('mwl_xlsx.delete_unused_products_skip', [
                    '[product_id]' => $product_id,
                    '[sources]' => implode(', ', $references),
                ]);

                echo '[skip] ' . $reference_message . PHP_EOL;
                fn_mwl_xlsx_append_log('[delete_unused] ' . $reference_message);

                $skipped_products[] = $product_id;

                continue;
            }

            if ($dry_run) {
                $planned_products[] = $product_id;

                $planned_message = __('mwl_xlsx.delete_unused_products_dry_run_entry', ['[product_id]' => $product_id]);
                echo '[dry-run] ' . $planned_message . PHP_EOL;
                fn_mwl_xlsx_append_log('[delete_unused] ' . $planned_message);

                continue;
            }

            $deleted = fn_delete_product($product_id);

            if ($deleted) {
                $deleted_message = __('mwl_xlsx.delete_unused_products_deleted', ['[product_id]' => $product_id]);
                echo '[deleted] ' . $deleted_message . PHP_EOL;
                fn_mwl_xlsx_append_log('[delete_unused] ' . $deleted_message);

                $deleted_products[] = $product_id;

                continue;
            }

            $error_message = __('mwl_xlsx.delete_unused_products_error', ['[product_id]' => $product_id]);
            echo '[error] ' . $error_message . PHP_EOL;
            fn_mwl_xlsx_append_log('[delete_unused] ' . $error_message);

            $errors[] = $product_id;
        }

        $metrics = [
            'disabled' => count($all_product_ids),
            'referenced_disabled' => $referenced_disabled_count,
            'deleted' => count($deleted_products),
            'skipped' => count($skipped_products),
            'errors' => count($errors),
        ];
        fn_mwl_xlsx_output_metrics($mode, $metrics);

        if ($dry_run && $planned_products) {
            $planned_message = __('mwl_xlsx.delete_unused_products_dry_run_list', ['[ids]' => implode(', ', $planned_products)]);
            echo '[info] ' . $planned_message . PHP_EOL;
        }

        if ($errors) {
            $errors_message = __('mwl_xlsx.delete_unused_products_errors_list', ['[ids]' => implode(', ', $errors)]);
            echo '[error] ' . $errors_message . PHP_EOL;
        }

        $log_payload = [
            'disabled' => count($all_product_ids),
            'referenced_disabled' => $referenced_disabled_count,
            'deleted' => count($deleted_products),
            'skipped' => count($skipped_products),
            'errors' => count($errors),
            'dry_run' => $dry_run,
            'deleted_product_ids' => $deleted_products,
            'skipped_product_ids' => $skipped_products,
            'error_product_ids' => $errors,
        ];
        fn_mwl_xlsx_append_log(sprintf('[%s] Metrics: %s', $mode, json_encode($log_payload, JSON_UNESCAPED_UNICODE)));

        return [CONTROLLER_STATUS_NO_CONTENT];
    }
}
