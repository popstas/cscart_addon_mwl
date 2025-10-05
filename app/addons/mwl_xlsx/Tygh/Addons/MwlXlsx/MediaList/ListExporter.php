<?php

namespace Tygh\Addons\MwlXlsx\MediaList;

use Google\Service\Exception as GoogleServiceException;
use Google\Service\Sheets;
use Google\Service\Sheets\BatchUpdateSpreadsheetRequest;
use Google\Service\Sheets\ValueRange;

class ListExporter
{
    /** @var \Google\Service\Sheets|null */
    private $sheets;

    /** @var callable */
    private $vendorAutoloader;

    public function __construct(?Sheets $sheets = null, ?callable $vendor_autoloader = null)
    {
        $this->sheets = $sheets;
        $this->vendorAutoloader = $vendor_autoloader ?: static function (): void {
            $namespaced = __NAMESPACE__ . '\\fn_mwl_xlsx_load_vendor_autoloader';

            if (function_exists($namespaced)) {
                $namespaced();

                return;
            }

            if (function_exists('fn_mwl_xlsx_load_vendor_autoloader')) {
                fn_mwl_xlsx_load_vendor_autoloader();
            }
        };
    }

    /**
     * @param int   $list_id
     * @param array $auth
     * @param string $lang_code
     *
     * @return array
     */
    public function getListProducts(int $list_id, array $auth, string $lang_code = CART_LANGUAGE): array
    {
        $items = db_get_hash_array(
            "SELECT product_id, product_options, amount FROM ?:mwl_xlsx_list_products WHERE list_id = ?i",
            'product_id',
            $list_id
        );
        if (!$items) {
            return [];
        }

        $products = [];
        foreach ($items as $product_id => $item) {
            $product = fn_get_product_data($product_id, $auth, $lang_code);
            if ($product) {
                $features = fn_get_product_features_list([
                    'product_id'          => $product_id,
                    'features_display_on' => 'A',
                ], 0, $lang_code);

                $product['product_features'] = $features;
                $product['selected_options'] = empty($item['product_options']) ? [] : @unserialize($item['product_options']);
                $product['amount'] = $item['amount'];
                $product['mwl_list_id'] = $list_id;
                $products[] = $product;
            }
        }

        if ($products) {
            $params = [
                'get_icon' => true,
                'get_detailed' => true,
                'get_options' => true,
                'get_features' => false,
                'get_discounts' => true,
                'get_taxed_prices' => true,
            ];
            fn_gather_additional_products_data($products, $params, $lang_code);
        }

        return $products;
    }

    public function collectFeatureNames(array $products, string $lang_code = CART_LANGUAGE): array
    {
        $feature_ids = [];
        foreach ($products as $product) {
            if (empty($product['product_features'])) {
                continue;
            }
            foreach ($product['product_features'] as $feature) {
                if (!empty($feature['feature_id'])) {
                    $feature_ids[] = $feature['feature_id'];
                }
            }
        }

        $feature_ids = array_unique($feature_ids);
        if (!$feature_ids) {
            return [];
        }

        [$features] = fn_get_product_features([
            'feature_id' => $feature_ids,
        ], 0, $lang_code);

        $names = [];
        foreach ($features as $feature) {
            $names[$feature['feature_id']] = $feature['description'];
        }

        return $names;
    }

    public function getFeatureValues(array $features, string $lang_code = CART_LANGUAGE): array
    {
        $values = [];
        foreach ($features as $feature) {
            $feature_id = $feature['feature_id'];
            if (!empty($feature['value_int'])) {
                $values[$feature_id] = floatval($feature['value_int']);
            } elseif (!empty($feature['value'])) {
                $values[$feature_id] = $feature['value'];
            } elseif (!empty($feature['variant'])) {
                $values[$feature_id] = $feature['variant'];
            } elseif (!empty($feature['variants'])) {
                $values[$feature_id] = implode(', ', array_column($feature['variants'], 'variant'));
            } else {
                $values[$feature_id] = '';
            }
        }

        return $values;
    }

    public function getListData(int $list_id, array $auth, string $lang_code = CART_LANGUAGE): array
    {
        $products = $this->getListProducts($list_id, $auth, $lang_code);
        $feature_names = $this->collectFeatureNames($products, $lang_code);
        $feature_ids = array_keys($feature_names);

        $header = array_merge([__('name'), __('price')], array_values($feature_names));
        $data = [$header];

        $settings = fn_mwl_xlsx_get_user_settings($auth);
        foreach ($products as $product) {
            $price_str = fn_mwl_xlsx_transform_price_for_export($product['price'], $settings);
            $row = [$product['product'], $price_str];
            $values = $this->getFeatureValues($product['product_features'] ?? [], $lang_code);
            foreach ($feature_ids as $feature_id) {
                $row[] = $values[$feature_id] ?? null;
            }
            $data[] = $row;
        }

        return ['data' => $data];
    }

    public function fillGoogleSheet(string $spreadsheet_id, array $data, bool $debug = false): bool
    {
        if (!$this->sheets) {
            throw new \RuntimeException('Google Sheets service is not configured');
        }

        ($this->vendorAutoloader)();

        $data = array_slice($data, 0, 51);

        $normalized = [];
        foreach ($data as $row) {
            if ($row instanceof \Traversable) {
                $row = iterator_to_array($row);
            } elseif (is_object($row)) {
                $row = (array) $row;
            }

            if (is_array($row)) {
                $row = array_values($row);
            } else {
                $row = [$row];
            }

            foreach ($row as $i => $cell) {
                if ($cell === null) {
                    $row[$i] = '';
                }
            }

            $normalized[] = $row;
        }

        $data = $normalized;

        try {
            $body = new ValueRange([
                'majorDimension' => 'ROWS',
                'values' => $data,
            ]);
            $this->sheets->spreadsheets_values->update($spreadsheet_id, 'A1', $body, ['valueInputOption' => 'RAW']);
        } catch (GoogleServiceException $e) {
            if ($debug) {
                header('Content-Type: text/plain; charset=UTF-8');
                echo "Sheets API values.update error:\n";
                echo $e->getCode() . " " . $e->getMessage() . "\n";
                if (method_exists($e, 'getErrors')) {
                    echo json_encode($e->getErrors(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
                }
                echo "Created Spreadsheet ID: $spreadsheet_id\n";
                echo "URL: https://docs.google.com/spreadsheets/d/$spreadsheet_id\n";

                return false;
            }

            throw $e;
        }

        try {
            $ss = $this->sheets->spreadsheets->get($spreadsheet_id, ['fields' => 'sheets(properties(sheetId,title))']);
            $sheets_list = $ss ? $ss->getSheets() : null;
            $sheet_id = $sheets_list && isset($sheets_list[0]) ? $sheets_list[0]->getProperties()->sheetId : null;

            if ($sheet_id !== null) {
                $column_count = 0;
                foreach ($data as $row) {
                    $column_count = max($column_count, is_array($row) ? count($row) : 0);
                }

                $requests = [[
                    'autoResizeDimensions' => [
                        'dimensions' => [
                            'sheetId'    => $sheet_id,
                            'dimension'  => 'COLUMNS',
                            'startIndex' => 0,
                            'endIndex'   => $column_count,
                        ],
                    ],
                ]];
                $batch_req = new BatchUpdateSpreadsheetRequest(['requests' => $requests]);
                $this->sheets->spreadsheets->batchUpdate($spreadsheet_id, $batch_req);

                if ($debug) {
                    echo "Auto-resize columns applied on sheetId=$sheet_id for $column_count columns\n\n";
                }
            } elseif ($debug) {
                echo "Warning: Could not determine sheetId for auto-resize\n\n";
            }
        } catch (GoogleServiceException $e) {
            if ($debug) {
                echo "Sheets API batchUpdate (auto-resize) error:\n";
                echo $e->getCode() . " " . $e->getMessage() . "\n";
                if (method_exists($e, 'getErrors')) {
                    echo json_encode($e->getErrors(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
                }
                echo "\n";
            }
        }

        return true;
    }
}
