<?php

namespace Tygh\Addons\MwlXlsx\Cron;

class ProductsValidateRunner
{
    public function run(string $mode): void
    {
        echo 'products_validate: checking for duplicate default variations...' . PHP_EOL;

        $duplicates = db_get_array(
            'SELECT pd.product AS name, '
            . 'COUNT(DISTINCT vgp.product_id) AS default_count, '
            . 'GROUP_CONCAT(DISTINCT vgp.product_id) AS product_ids, '
            . 'GROUP_CONCAT(DISTINCT p.product_code) AS product_codes, '
            . 'GROUP_CONCAT(DISTINCT vg.code) AS group_codes '
            . 'FROM ?:product_variation_group_products vgp '
            . 'JOIN ?:products p ON p.product_id = vgp.product_id AND p.status != ?s '
            . 'JOIN ?:product_descriptions pd ON pd.product_id = vgp.product_id AND pd.lang_code = ?s '
            . 'JOIN ?:product_variation_groups vg ON vg.id = vgp.group_id '
            . 'WHERE vgp.parent_product_id = vgp.product_id '
            . 'AND vgp.parent_product_id != 0 '
            . 'GROUP BY pd.product '
            . 'HAVING default_count > 1 '
            . 'ORDER BY default_count DESC',
            'D',
            'en'
        );

        $total_checked = (int) db_get_field(
            'SELECT COUNT(DISTINCT vgp.product_id) '
            . 'FROM ?:product_variation_group_products vgp '
            . 'JOIN ?:products p ON p.product_id = vgp.product_id AND p.status != ?s '
            . 'WHERE vgp.parent_product_id = vgp.product_id '
            . 'AND vgp.parent_product_id != 0',
            'D'
        );

        $duplicate_names = [];

        foreach ($duplicates as $row) {
            $name = $row['name'];
            $count = (int) $row['default_count'];
            $product_ids = explode(',', $row['product_ids']);
            $product_codes = explode(',', $row['product_codes']);
            $group_codes = explode(',', $row['group_codes']);

            $duplicate_names[] = $name;

            $details = [];
            for ($i = 0; $i < count($product_ids); $i++) {
                $pid = $product_ids[$i] ?? '';
                $code = $product_codes[$i] ?? '';
                $group = $group_codes[$i] ?? '';
                $details[] = sprintf('#%s (%s, group: %s)', $pid, $code, $group);
            }

            $warning = sprintf(
                'products_validate: WARNING: "%s" has %d default variations: %s',
                $name,
                $count,
                implode(', ', $details)
            );

            echo $warning . PHP_EOL;
            fn_mwl_xlsx_append_log('[products_validate] ' . $warning);
        }

        $duplicates_found = count($duplicates);

        echo sprintf(
            'products_validate: checked %d products, found %d duplicates',
            $total_checked,
            $duplicates_found
        ) . PHP_EOL;

        $metrics = [
            'total_checked' => $total_checked,
            'duplicates_found' => $duplicates_found,
            'duplicate_names' => $duplicate_names,
        ];

        fn_mwl_xlsx_output_metrics($mode, $metrics);

        fn_mwl_xlsx_append_log(sprintf(
            '[products_validate] Metrics: %s',
            json_encode($metrics, JSON_UNESCAPED_UNICODE)
        ));
    }
}
