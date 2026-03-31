<?php

namespace Tygh\Addons\MwlXlsx\Cron;

class ProductsValidateRunner
{
    public function run(string $mode): array
    {
        echo 'products_validate: checking for duplicate default variations...' . PHP_EOL;

        $duplicates = db_get_array(
            'SELECT pd.product AS name, '
            . 'COUNT(DISTINCT vgp.product_id) AS default_count, '
            . 'GROUP_CONCAT(vgp.product_id ORDER BY vgp.product_id) AS product_ids, '
            . 'GROUP_CONCAT(p.product_code ORDER BY vgp.product_id) AS product_codes, '
            . 'GROUP_CONCAT(vg.code ORDER BY vgp.product_id) AS group_codes '
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

        $unique_products = (int) db_get_field(
            'SELECT COUNT(*) FROM ?:products p '
            . 'WHERE p.status != ?s '
            . 'AND (p.product_id NOT IN (SELECT product_id FROM ?:product_variation_group_products) '
            . '     OR p.product_id IN ('
            . '         SELECT product_id FROM ?:product_variation_group_products '
            . '         WHERE parent_product_id = product_id AND parent_product_id != 0'
            . '     ))',
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

        // Auto-attach ungrouped products to existing groups with the same name
        $total_attached = $this->attachUngroupedProducts();

        echo sprintf(
            'products_validate: %d unique products, found %d duplicate defaults, %d attached to groups',
            $unique_products,
            $duplicates_found,
            $total_attached
        ) . PHP_EOL;

        $metrics = [
            'unique_products' => $unique_products,
            'duplicate_names' => $duplicates_found,
            'attached_to_groups' => $total_attached,
        ];

        fn_mwl_xlsx_output_metrics($mode, $metrics);

        fn_mwl_xlsx_append_log(sprintf(
            '[products_validate] Metrics: %s',
            json_encode($metrics, JSON_UNESCAPED_UNICODE)
        ));

        return [CONTROLLER_STATUS_NO_CONTENT];
    }

    /**
     * Find ungrouped products that share a name with grouped products,
     * and attach them to the existing group.
     */
    private function attachUngroupedProducts(): int
    {
        $mixed = db_get_array(
            'SELECT pd.product AS name, '
            . 'MIN(vgp.group_id) AS group_id, '
            . 'GROUP_CONCAT(CASE WHEN vgp.group_id IS NULL AND p.status != ?s THEN p.product_id END) AS ungrouped_ids, '
            . 'GROUP_CONCAT(CASE WHEN vgp.group_id IS NULL AND p.status != ?s THEN p.product_code END) AS ungrouped_codes '
            . 'FROM ?:products p '
            . 'JOIN ?:product_descriptions pd ON pd.product_id = p.product_id AND pd.lang_code = ?s '
            . 'LEFT JOIN ?:product_variation_group_products vgp ON vgp.product_id = p.product_id '
            . 'GROUP BY pd.product '
            . 'HAVING SUM(vgp.group_id IS NULL AND p.status != ?s) > 0 AND SUM(vgp.group_id IS NOT NULL) > 0 '
            . 'ORDER BY pd.product',
            'D',
            'D',
            'en',
            'D'
        );

        if (empty($mixed)) {
            return 0;
        }

        $service = \Tygh\Addons\ProductVariations\ServiceProvider::getService();
        $total_attached = 0;

        foreach ($mixed as $row) {
            $group_id = (int) $row['group_id'];
            $ungrouped_ids = array_filter(array_map('intval', explode(',', $row['ungrouped_ids'])));

            if (empty($ungrouped_ids) || !$group_id) {
                continue;
            }

            try {
                $result = $service->attachProductsToGroup($group_id, $ungrouped_ids);

                $statuses = $result->getData('products_status', []);
                $attached = 0;
                foreach ($statuses as $pid => $status) {
                    if (!\Tygh\Addons\ProductVariations\Product\Group\Group::isResultError($status)) {
                        $attached++;
                    }
                }

                if ($attached > 0) {
                    $msg = sprintf(
                        'products_validate: attached %d products to group #%d for "%s" (codes: %s)',
                        $attached,
                        $group_id,
                        $row['name'],
                        $row['ungrouped_codes']
                    );
                    echo $msg . PHP_EOL;
                    fn_mwl_xlsx_append_log('[products_validate] ' . $msg);
                    $total_attached += $attached;
                }

                $errors = $result->getErrors();
                if (!empty($errors)) {
                    $msg = sprintf(
                        'products_validate: WARNING: errors attaching to group #%d for "%s": %s',
                        $group_id,
                        $row['name'],
                        implode('; ', $errors)
                    );
                    echo $msg . PHP_EOL;
                    fn_mwl_xlsx_append_log('[products_validate] ' . $msg);
                }
            } catch (\Exception $e) {
                $msg = sprintf(
                    'products_validate: ERROR attaching to group #%d for "%s": %s',
                    $group_id,
                    $row['name'],
                    $e->getMessage()
                );
                echo $msg . PHP_EOL;
                fn_mwl_xlsx_append_log('[products_validate] ' . $msg);
            }
        }

        return $total_attached;
    }
}
