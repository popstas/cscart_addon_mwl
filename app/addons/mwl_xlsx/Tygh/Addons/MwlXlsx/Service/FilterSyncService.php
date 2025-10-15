<?php

namespace Tygh\Addons\MwlXlsx\Service;

use Tygh\Addons\MwlXlsx\Repository\FilterRepository;

class FilterSyncService
{
    private const COMPANY_ID = 3;
    private const FILTER_TYPE = 'P';
    private const FIELD_TYPE = 'P';
    private const FEATURE_ID = 0;
    private const STATUS = 'A';
    private const DISPLAY_COUNT = 10;
    private const CATEGORIES_PATH = '';

    /** @var FilterRepository */
    private $repository;

    public function __construct(FilterRepository $repository)
    {
        $this->repository = $repository;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    public function syncPriceFilters(array $rows): FilterSyncReport
    {
        $report = new FilterSyncReport();

        $existing = $this->repository->getCompanyPriceFilters(self::COMPANY_ID);
        $existing_by_name = [];

        foreach ($existing as $filter) {
            $normalized_name = $this->normalizeName($filter['filter'] ?? '');
            if ($normalized_name === '') {
                continue;
            }
            $existing_by_name[$normalized_name] = $filter;
        }

        $processed_ids = [];

        foreach ($rows as $index => $row) {
            $row_number = $index + 2; // +2 to account for header row in CSV

            $name = trim((string) ($row['filter'] ?? ''));
            if ($name === '') {
                $report->addError(sprintf('Row %d: missing required "filter" value', $row_number));
                $report->addSkipped(sprintf('Row %d skipped due to missing name', $row_number));
                continue;
            }

            $normalized_name = $this->normalizeName($name);

            if (isset($processed_ids[$normalized_name])) {
                $report->addError(sprintf('Row %d: duplicate filter name "%s"', $row_number, $name));
                $report->addSkipped(sprintf('Row %d skipped as duplicate', $row_number));
                continue;
            }

            $position = isset($row['position']) ? (int) $row['position'] : 0;
            $round_to = isset($row['round_to']) ? (int) $row['round_to'] : 0;
            $display = $this->normalizeBoolean($row['display'] ?? '');
            $display_mobile = $this->normalizeBoolean($row['abt__ut2_display_mobile'] ?? null, $display);
            $display_tablet = $this->normalizeBoolean($row['abt__ut2_display_tablet'] ?? null, $display);
            $display_desktop = $this->normalizeBoolean($row['abt__ut2_display_desktop'] ?? null, $display);

            $params = [
                'display' => $display,
                'abt__ut2_display_mobile' => $display_mobile,
                'abt__ut2_display_tablet' => $display_tablet,
                'abt__ut2_display_desktop' => $display_desktop,
            ];

            if (!isset($existing_by_name[$normalized_name])) {
                $filter_data = [
                    'filter' => $name,
                    'position' => $position,
                    'round_to' => $round_to,
                    'display' => $display,
                    'params' => [
                        'abt__ut2_display_mobile' => $display_mobile,
                        'abt__ut2_display_tablet' => $display_tablet,
                        'abt__ut2_display_desktop' => $display_desktop,
                    ],
                    'company_id' => self::COMPANY_ID,
                    'filter_type' => self::FILTER_TYPE,
                    'field_type' => self::FIELD_TYPE,
                    'feature_id' => self::FEATURE_ID,
                    'status' => self::STATUS,
                    'display_count' => self::DISPLAY_COUNT,
                    'categories_path' => self::CATEGORIES_PATH,
                ];

                $filter_id = fn_update_product_filter($filter_data);

                if ($filter_id) {
                    $report->addCreated($name, (int) $filter_id);
                    $processed_ids[$normalized_name] = (int) $filter_id;
                } else {
                    $report->addError(sprintf('Row %d: failed to create filter "%s"', $row_number, $name));
                }

                continue;
            }

            $existing_filter = $existing_by_name[$normalized_name];
            $filter_id = (int) $existing_filter['filter_id'];
            $updates = [];
            $params_updates = [];

            if ((int) ($existing_filter['position'] ?? 0) !== $position) {
                $updates['position'] = $position;
            }

            if ((int) ($existing_filter['round_to'] ?? 0) !== $round_to) {
                $updates['round_to'] = $round_to;
            }

            $current_display = $this->getExistingValue($existing_filter, 'display', 'Y');
            if ($current_display !== $display) {
                $updates['display'] = $display;
            }

            $existing_params = isset($existing_filter['params']) && is_array($existing_filter['params'])
                ? $existing_filter['params']
                : [];

            foreach (['abt__ut2_display_mobile', 'abt__ut2_display_tablet', 'abt__ut2_display_desktop'] as $param_name) {
                $current_value = isset($existing_params[$param_name])
                    ? (string) $existing_params[$param_name]
                    : $display;

                if ($current_value !== $params[$param_name]) {
                    $params_updates[$param_name] = $params[$param_name];
                }
            }

            if ($updates || $params_updates) {
                if ($params_updates) {
                    $updates['params'] = $params_updates;
                }

                $result = fn_update_product_filter($updates, $filter_id);

                if ($result) {
                    $report->addUpdated($name, $filter_id);
                } else {
                    $report->addError(sprintf('Row %d: failed to update filter "%s"', $row_number, $name));
                }
            } else {
                $report->addSkipped(sprintf('Row %d: no changes for "%s"', $row_number, $name));
            }

            $processed_ids[$normalized_name] = $filter_id;
        }

        $existing_ids = array_map('intval', array_column($existing, 'filter_id'));
        $processed_filter_ids = array_values($processed_ids);
        $obsolete_ids = array_diff($existing_ids, $processed_filter_ids);

        if ($obsolete_ids) {
            foreach ($existing as $filter) {
                $filter_id = (int) $filter['filter_id'];
                if (!in_array($filter_id, $obsolete_ids, true)) {
                    continue;
                }

                $this->repository->deleteFilters([$filter_id]);
                $report->addDeleted((string) ($filter['filter'] ?? ''), $filter_id);
            }
        }

        return $report;
    }

    private function normalizeName(string $name): string
    {
        $name = trim($name);
        $name = mb_strtolower($name, 'UTF-8');

        return $name;
    }

    /**
     * @param mixed $value
     */
    private function normalizeBoolean($value, string $fallback = 'N'): string
    {
        if ($value === null || $value === '') {
            return $fallback;
        }

        $value = is_string($value) ? trim($value) : $value;
        $normalized = is_string($value) ? strtoupper($value) : $value;

        $truthy = ['Y', 'YES', 'TRUE', '1', 1, true, 'ON'];

        return in_array($normalized, $truthy, true) ? 'Y' : 'N';
    }

    private function getExistingValue(array $filter, string $key, string $default = 'N'): string
    {
        if (!isset($filter[$key])) {
            return $default;
        }

        $value = $filter[$key];

        return is_string($value) ? $value : (string) $value;
    }
}
