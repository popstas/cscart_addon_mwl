<?php

namespace Tygh\Addons\MwlXlsx\Cron;

use Tygh\Addons\MwlXlsx\Service\FilterSyncService;
use Tygh\Addons\MwlXlsx\Utility\CsvHelper;

class FiltersSyncRunner
{
    private FilterSyncService $filter_sync_service;

    public function __construct(FilterSyncService $filter_sync_service)
    {
        $this->filter_sync_service = $filter_sync_service;
    }

    public function run(string $csv_path, string $mode): array
    {
        $csv_path = trim($csv_path);

        if ($csv_path === '') {
            $message = __('mwl_xlsx.filters_sync_missing_path');
            echo $message . PHP_EOL;
            fn_mwl_xlsx_append_log($message);

            return [CONTROLLER_STATUS_NO_CONTENT];
        }

        $result = $this->readFiltersCsv($csv_path);

        if (!empty($result['errors'])) {
            foreach ($result['errors'] as $error) {
                echo '[error] ' . $error . PHP_EOL;
                fn_mwl_xlsx_append_log('[error] ' . $error);
            }

            return [CONTROLLER_STATUS_NO_CONTENT];
        }

        $rows = $result['rows'];

        if (!$rows) {
            $message = __('mwl_xlsx.filters_sync_error_empty');
            echo $message . PHP_EOL;
            fn_mwl_xlsx_append_log($message);

            return [CONTROLLER_STATUS_NO_CONTENT];
        }

        if (count($rows) > 100) {
            $message = __('mwl_xlsx.filters_sync_limit_exceeded');
            echo '[error] ' . $message . PHP_EOL;
            fn_mwl_xlsx_append_log('[error] ' . $message);

            return [CONTROLLER_STATUS_NO_CONTENT];
        }

        $report = $this->filter_sync_service->syncPriceFilters($rows);
        $summary = $report->getSummary();

        $metrics = [
            'created' => $summary['created'],
            'updated' => $summary['updated'],
            'skipped' => $summary['skipped'],
            'errors' => $summary['errors'],
        ];
        fn_mwl_xlsx_output_metrics($mode, $metrics);

        foreach ($report->getErrors() as $error) {
            echo '[error] ' . $error . PHP_EOL;
        }

        foreach ($report->getSkipped() as $skip) {
            echo '[skip] ' . $skip . PHP_EOL;
        }

        fn_mwl_xlsx_append_log(sprintf('[%s] Metrics: %s', $mode, json_encode($metrics, JSON_UNESCAPED_UNICODE)));

        return [CONTROLLER_STATUS_NO_CONTENT];
    }

    private function readFiltersCsv(string $path): array
    {
        $rows = [];
        $errors = [];

        if (!file_exists($path)) {
            return [
                'rows' => $rows,
                'errors' => [__(
                    'mwl_xlsx.filters_sync_error_not_found',
                    ['[path]' => $path]
                )],
            ];
        }

        if (!is_readable($path)) {
            return [
                'rows' => $rows,
                'errors' => [__(
                    'mwl_xlsx.filters_sync_error_not_readable',
                    ['[path]' => $path]
                )],
            ];
        }

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return [
                'rows' => $rows,
                'errors' => [__(
                    'mwl_xlsx.filters_sync_error_open_failed',
                    ['[path]' => $path]
                )],
            ];
        }

        $first_line = fgets($handle);

        if ($first_line === false) {
            fclose($handle);

            return [
                'rows' => $rows,
                'errors' => [__('mwl_xlsx.filters_sync_error_empty')],
            ];
        }

        $delimiter = CsvHelper::detectDelimiter($first_line);
        rewind($handle);

        $header = fgetcsv($handle, 0, $delimiter);

        if ($header === false) {
            fclose($handle);

            return [
                'rows' => $rows,
                'errors' => [__('mwl_xlsx.filters_sync_error_header')],
            ];
        }

        $normalized_header = [];

        foreach ($header as $index => $column) {
            $normalized_header[$index] = CsvHelper::normalizeHeaderValue((string) $column, $index === 0);
        }

        $header_map = array_flip($normalized_header);
        $name_column = null;

        if (isset($header_map['name'])) {
            $name_column = 'name';
        } elseif (isset($header_map['filter'])) {
            $name_column = 'filter';
        }

        if ($name_column === null) {
            fclose($handle);

            return [
                'rows' => [],
                'errors' => [__(
                    'mwl_xlsx.filters_sync_error_missing_column',
                    ['[column]' => 'name']
                )],
            ];
        }

        $required_columns = ['position', 'round_to', 'display'];

        if (!isset($header_map['name_ru'])) {
            fclose($handle);

            return [
                'rows' => [],
                'errors' => [__(
                    'mwl_xlsx.filters_sync_error_missing_column',
                    ['[column]' => 'name_ru']
                )],
            ];
        }

        if (!isset($header_map['feature_id'])) {
            fclose($handle);

            return [
                'rows' => [],
                'errors' => [__(
                    'mwl_xlsx.filters_sync_error_missing_column',
                    ['[column]' => 'feature_id']
                )],
            ];
        }

        $required_columns[] = $name_column;
        $required_columns[] = 'name_ru';
        $required_columns[] = 'feature_id';

        foreach ($required_columns as $required) {
            if (!in_array($required, $normalized_header, true)) {
                fclose($handle);

                return [
                    'rows' => [],
                    'errors' => [__(
                        'mwl_xlsx.filters_sync_error_missing_column',
                        ['[column]' => $required]
                    )],
                ];
            }
        }

        while (($data = fgetcsv($handle, 0, $delimiter)) !== false) {
            if (count(array_filter($data, static function ($value) {
                return $value !== null && $value !== '';
            })) === 0) {
                continue;
            }

            $row = [];

            foreach ($normalized_header as $index => $column) {
                $row[$column] = $data[$index] ?? null;
            }

            if ($name_column === 'filter' && isset($row['filter']) && !isset($row['name'])) {
                $row['name'] = $row['filter'];
            }

            if (isset($row['filter_ru']) && !isset($row['name_ru'])) {
                $row['name_ru'] = $row['filter_ru'];
            }

            $rows[] = $row;
        }

        fclose($handle);

        return [
            'rows' => $rows,
            'errors' => $errors,
        ];
    }
}
