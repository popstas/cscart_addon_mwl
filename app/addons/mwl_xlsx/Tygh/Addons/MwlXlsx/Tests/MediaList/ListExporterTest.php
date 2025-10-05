<?php

namespace Tygh\Addons\MwlXlsx\MediaList;

class ListExporterTestState
{
    public static $listItems = [];
    public static $productData = [];
    public static $productDataCalls = [];
    public static $productFeatures = [];
    public static $featureDefinitions = [];
    public static $userSettings = [];
    public static $translations = [];
    public static $priceMap = [];
    public static $gatheredProducts = [];
    public static $autoloaderCalls = 0;

    public static function reset(): void
    {
        self::$listItems = [];
        self::$productData = [];
        self::$productDataCalls = [];
        self::$productFeatures = [];
        self::$featureDefinitions = [];
        self::$userSettings = [];
        self::$translations = [];
        self::$priceMap = [];
        self::$gatheredProducts = [];
        self::$autoloaderCalls = 0;
    }
}

if (!function_exists(__NAMESPACE__ . '\\db_get_hash_array')) {
    function db_get_hash_array($query, $key, $list_id)
    {
        return ListExporterTestState::$listItems[$list_id] ?? [];
    }
}

if (!function_exists(__NAMESPACE__ . '\\fn_get_product_data')) {
    function fn_get_product_data($product_id, array $auth, $lang_code)
    {
        ListExporterTestState::$productDataCalls[] = [
            'product_id' => $product_id,
            'auth'       => $auth,
            'lang_code'  => $lang_code,
        ];

        return ListExporterTestState::$productData[$product_id] ?? [];
    }
}

if (!function_exists(__NAMESPACE__ . '\\fn_get_product_features_list')) {
    function fn_get_product_features_list(array $params, $something, $lang_code)
    {
        $product_id = $params['product_id'] ?? 0;

        return ListExporterTestState::$productFeatures[$product_id] ?? [];
    }
}

if (!function_exists(__NAMESPACE__ . '\\fn_gather_additional_products_data')) {
    function fn_gather_additional_products_data(array &$products, array $params, $lang_code)
    {
        ListExporterTestState::$gatheredProducts[] = [
            'products'  => $products,
            'params'    => $params,
            'lang_code' => $lang_code,
        ];
    }
}

if (!function_exists(__NAMESPACE__ . '\\fn_get_product_features')) {
    function fn_get_product_features(array $params, $something, $lang_code)
    {
        return [ListExporterTestState::$featureDefinitions, null];
    }
}

if (!function_exists(__NAMESPACE__ . '\\__')) {
    function __(string $key)
    {
        return ListExporterTestState::$translations[$key] ?? $key;
    }
}

if (!function_exists(__NAMESPACE__ . '\\fn_mwl_xlsx_get_user_settings')) {
    function fn_mwl_xlsx_get_user_settings(array $auth)
    {
        return ListExporterTestState::$userSettings;
    }
}

if (!function_exists(__NAMESPACE__ . '\\fn_mwl_xlsx_transform_price_for_export')) {
    function fn_mwl_xlsx_transform_price_for_export($price, array $settings)
    {
        $price_map = ListExporterTestState::$priceMap;

        return $price_map[$price] ?? (string) $price;
    }
}

if (!function_exists(__NAMESPACE__ . '\\fn_mwl_xlsx_load_vendor_autoloader')) {
    function fn_mwl_xlsx_load_vendor_autoloader(): void
    {
        ListExporterTestState::$autoloaderCalls++;
    }
}

namespace Google\Service;

if (!class_exists('Google\\Service\\Sheets')) {
    class Sheets
    {
        public $spreadsheets_values;
        public $spreadsheets;

        public function __construct(...$args)
        {
        }
    }
}

namespace Google\Service\Sheets;

if (!class_exists('Google\\Service\\Sheets\\ValueRange')) {
    class ValueRange
    {
        private $values;
        private $majorDimension;

        public function __construct(array $data = [])
        {
            $this->values = $data['values'] ?? [];
            $this->majorDimension = $data['majorDimension'] ?? null;
        }

        public function getValues(): array
        {
            return $this->values;
        }

        public function getMajorDimension()
        {
            return $this->majorDimension;
        }
    }
}

if (!class_exists('Google\\Service\\Sheets\\BatchUpdateSpreadsheetRequest')) {
    class BatchUpdateSpreadsheetRequest
    {
        private $requests;

        public function __construct(array $data = [])
        {
            $this->requests = $data['requests'] ?? [];
        }

        public function getRequests(): array
        {
            return $this->requests;
        }
    }
}

namespace Tygh\Addons\MwlXlsx\Tests\MediaList;

use ArrayObject;
use Google\Service\Sheets;
use Google\Service\Sheets\BatchUpdateSpreadsheetRequest;
use Google\Service\Sheets\ValueRange;
use PHPUnit\Framework\TestCase;
use Tygh\Addons\MwlXlsx\MediaList\ListExporter;
use Tygh\Addons\MwlXlsx\MediaList\ListExporterTestState;

class ListExporterTest extends TestCase
{
    protected function setUp(): void
    {
        ListExporterTestState::reset();
    }

    public function testGetListDataBuildsHeaderAndRows(): void
    {
        ListExporterTestState::$listItems = [
            10 => [
                101 => [
                    'product_id' => 101,
                    'product_options' => serialize(['size' => 'L']),
                    'amount' => 1,
                ],
                202 => [
                    'product_id' => 202,
                    'product_options' => serialize([]),
                    'amount' => 2,
                ],
            ],
        ];
        ListExporterTestState::$productData = [
            101 => [
                'product_id' => 101,
                'product'    => 'Alpha',
                'price'      => 100.0,
            ],
            202 => [
                'product_id' => 202,
                'product'    => 'Beta',
                'price'      => 250.0,
            ],
        ];
        ListExporterTestState::$productFeatures = [
            101 => [
                ['feature_id' => 1, 'value' => 'Red'],
                ['feature_id' => 2, 'variant' => 'Cotton'],
            ],
            202 => [
                ['feature_id' => 2, 'variant' => 'Wool'],
                ['feature_id' => 3, 'value_int' => 3.5],
            ],
        ];
        ListExporterTestState::$featureDefinitions = [
            ['feature_id' => 1, 'description' => 'Color'],
            ['feature_id' => 2, 'description' => 'Material'],
            ['feature_id' => 3, 'description' => 'Weight'],
        ];
        ListExporterTestState::$userSettings = ['round_to' => 10];
        ListExporterTestState::$translations = ['name' => 'Name', 'price' => 'Price'];
        ListExporterTestState::$priceMap = [
            100.0 => '100,00',
            250.0 => '250,00',
        ];

        $auth = ['user_id' => 5];
        $exporter = new ListExporter();

        $products = $exporter->getListProducts(10, $auth, 'en');
        $this->assertCount(2, $products);
        $this->assertSame(['size' => 'L'], $products[0]['selected_options']);
        $this->assertSame(1, $products[0]['amount']);
        $this->assertSame(10, $products[0]['mwl_list_id']);
        $this->assertSame(2, $products[1]['amount']);
        $this->assertSame([], $products[1]['selected_options']);

        $result = $exporter->getListData(10, $auth, 'en');
        $this->assertArrayHasKey('data', $result);

        $expected = [
            ['Name', 'Price', 'Color', 'Material', 'Weight'],
            ['Alpha', '100,00', 'Red', 'Cotton', null],
            ['Beta', '250,00', '', 'Wool', 3.5],
        ];
        $this->assertSame($expected, $result['data']);

        $this->assertCount(1, ListExporterTestState::$gatheredProducts);
        $gather_call = ListExporterTestState::$gatheredProducts[0];
        $this->assertSame('en', $gather_call['lang_code']);
        $this->assertSame([
            'get_icon' => true,
            'get_detailed' => true,
            'get_options' => true,
            'get_features' => false,
            'get_discounts' => true,
            'get_taxed_prices' => true,
        ], $gather_call['params']);
    }

    public function testFillGoogleSheetBuildsRequests(): void
    {
        $sheet_response = new FakeSpreadsheetResponse([['sheetId' => 321]]);
        $sheets = new FakeSheets($sheet_response);
        $exporter = new ListExporter($sheets);

        $data = [
            ['H1', 'H2', 'H3'],
            new ArrayObject(['R1', null, 'C1']),
            (object) ['first' => 'R2', 'second' => null, 'third' => 'C2', 'fourth' => 'D2'],
            'Tail',
        ];
        for ($i = 0; $i < 60; $i++) {
            $data[] = [$i, null, 'v' . $i];
        }

        $result = $exporter->fillGoogleSheet('spreadsheet', $data);
        $this->assertTrue($result);
        $this->assertSame(1, ListExporterTestState::$autoloaderCalls);

        $this->assertCount(1, $sheets->spreadsheets_values->updateCalls);
        $update_call = $sheets->spreadsheets_values->updateCalls[0];
        $this->assertSame('spreadsheet', $update_call['spreadsheet_id']);
        $this->assertSame('A1', $update_call['range']);
        $this->assertSame(['valueInputOption' => 'RAW'], $update_call['params']);

        /** @var ValueRange $body */
        $body = $update_call['body'];
        $values = $body->getValues();
        $this->assertCount(51, $values);
        $this->assertSame(['H1', 'H2', 'H3'], $values[0]);
        $this->assertSame(['R1', '', 'C1'], $values[1]);
        $this->assertSame(['R2', '', 'C2', 'D2'], $values[2]);
        $this->assertSame(['Tail'], $values[3]);

        $this->assertCount(1, $sheets->spreadsheets->getCalls);
        $this->assertSame(['fields' => 'sheets(properties(sheetId,title))'], $sheets->spreadsheets->getCalls[0]['params']);

        $this->assertCount(1, $sheets->spreadsheets->batchCalls);
        $batch_call = $sheets->spreadsheets->batchCalls[0];
        $this->assertSame('spreadsheet', $batch_call['spreadsheet_id']);
        /** @var BatchUpdateSpreadsheetRequest $batch_request */
        $batch_request = $batch_call['request'];
        $this->assertSame([
            [
                'autoResizeDimensions' => [
                    'dimensions' => [
                        'sheetId'    => 321,
                        'dimension'  => 'COLUMNS',
                        'startIndex' => 0,
                        'endIndex'   => 4,
                    ],
                ],
            ],
        ], $batch_request->getRequests());
    }
}

class FakeSheets extends Sheets
{
    public $spreadsheets_values;
    public $spreadsheets;

    public function __construct(FakeSpreadsheetResponse $response)
    {
        $this->spreadsheets_values = new FakeSpreadsheetsValues();
        $this->spreadsheets = new FakeSpreadsheets($response);
    }
}

class FakeSpreadsheetsValues
{
    public $updateCalls = [];

    public function update($spreadsheet_id, $range, $body, array $params = [])
    {
        $this->updateCalls[] = [
            'spreadsheet_id' => $spreadsheet_id,
            'range'          => $range,
            'body'           => $body,
            'params'         => $params,
        ];
    }
}

class FakeSpreadsheets
{
    public $getCalls = [];
    public $batchCalls = [];
    private $response;

    public function __construct(FakeSpreadsheetResponse $response)
    {
        $this->response = $response;
    }

    public function get($spreadsheet_id, array $params = [])
    {
        $this->getCalls[] = [
            'spreadsheet_id' => $spreadsheet_id,
            'params'         => $params,
        ];

        return $this->response;
    }

    public function batchUpdate($spreadsheet_id, $request)
    {
        $this->batchCalls[] = [
            'spreadsheet_id' => $spreadsheet_id,
            'request'        => $request,
        ];
    }
}

class FakeSpreadsheetResponse
{
    private $sheets;

    public function __construct(array $sheets)
    {
        $this->sheets = array_map(static function (array $sheet) {
            return new FakeSheet($sheet['sheetId']);
        }, $sheets);
    }

    public function getSheets(): array
    {
        return $this->sheets;
    }
}

class FakeSheet
{
    private $properties;

    public function __construct(int $sheet_id)
    {
        $this->properties = new FakeSheetProperties($sheet_id);
    }

    public function getProperties(): FakeSheetProperties
    {
        return $this->properties;
    }
}

class FakeSheetProperties
{
    public $sheetId;

    public function __construct(int $sheet_id)
    {
        $this->sheetId = $sheet_id;
    }
}
