<?php

namespace Tygh\Addons\MwlXlsx\Service;

use Tygh\Addons\MwlXlsx\Repository\FilterRepository;
use Tygh\Registry;

class FilterSyncService
{
    private const COMPANY_ID = 3;
    private const STATUS = 'A';
    private const DISPLAY_COUNT = 10;
    private const CATEGORIES_PATH = '';
    private const PRICE_FIELD_TYPE = 'P';
    private const FEATURE_FIELD_TYPE = '';
    private const DEFAULT_FEATURE_ID = 0;
    private const RUSSIAN_LANG_CODE = 'ru';

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

        \fn_mwl_xlsx_append_log(sprintf('[filters_sync] Starting sync for %d CSV rows', count($rows)));

        $existing = $this->repository->getCompanyFilters(self::COMPANY_ID);
        $existing_by_name = [];

        $existing_names = [];

        foreach ($existing as $filter) {
            $normalized_name = $this->normalizeName($filter['filter'] ?? '');
            if ($normalized_name === '') {
                continue;
            }
            $existing_by_name[$normalized_name] = $filter;
            $existing_names[] = (string) ($filter['filter'] ?? '');
        }

        if ($existing_names) {
            \fn_mwl_xlsx_append_log(sprintf('[filters_sync] Existing filters (%d): %s', count($existing_names), implode(', ', $existing_names)));
        } else {
            \fn_mwl_xlsx_append_log('[filters_sync] No existing filters matched the criteria');
        }

        $processed_ids = [];

        foreach ($rows as $index => $row) {
            $row_number = $index + 2; // +2 to account for header row in CSV

            $name = trim((string) ($row['name'] ?? $row['filter'] ?? ''));
            if ($name === '') {
                $report->addError(sprintf('Row %d: missing required "filter" value', $row_number));
                $report->addSkipped(sprintf('Row %d skipped due to missing name', $row_number));
                \fn_mwl_xlsx_append_log(sprintf('[filters_sync] Row %d skipped: missing name', $row_number));
                continue;
            }

            $normalized_name = $this->normalizeName($name);

            if (isset($processed_ids[$normalized_name])) {
                $report->addError(sprintf('Row %d: duplicate filter name "%s"', $row_number, $name));
                $report->addSkipped(sprintf('Row %d skipped as duplicate', $row_number));
                \fn_mwl_xlsx_append_log(sprintf('[filters_sync] Row %d skipped: duplicate name "%s"', $row_number, $name));
                continue;
            }

            $name_ru = trim((string) ($row['name_ru'] ?? $row['filter_ru'] ?? ''));
            $position = isset($row['position']) ? (int) $row['position'] : 0;
            $round_to = $this->normalizeRoundTo($row['round_to'] ?? null);
            $display = $this->normalizeBoolean($row['display'] ?? '');
            $display_mobile = $this->normalizeBoolean($row['abt__ut2_display_mobile'] ?? null, $display);
            $display_tablet = $this->normalizeBoolean($row['abt__ut2_display_tablet'] ?? null, $display);
            $display_desktop = $this->normalizeBoolean($row['abt__ut2_display_desktop'] ?? null, $display);

            $feature_id = $this->normalizeFeatureId($row['feature_id'] ?? null);
            $field_type = $feature_id > 0 ? self::FEATURE_FIELD_TYPE : self::PRICE_FIELD_TYPE;

            $params = [
                'abt__ut2_display_mobile' => $display_mobile,
                'abt__ut2_display_tablet' => $display_tablet,
                'abt__ut2_display_desktop' => $display_desktop,
            ];

            $filter_type = $this->resolveFilterType(
                $existing_by_name[$normalized_name] ?? null,
                $feature_id,
                $field_type
            );

            if (!isset($existing_by_name[$normalized_name])) {
                \fn_mwl_xlsx_append_log(sprintf('[filters_sync] Creating filter "%s" (feature_id=%d, filter_type=%s)', $name, $feature_id, $filter_type));

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
                    'field_type' => $field_type,
                    'feature_id' => $feature_id,
                    'filter_type' => $filter_type,
                    'status' => self::STATUS,
                    'display_count' => self::DISPLAY_COUNT,
                    'categories_path' => self::CATEGORIES_PATH,
                ];

                $filter_id = fn_update_product_filter($filter_data, 0);

                if ($filter_id) {
                    \fn_mwl_xlsx_append_log(sprintf('[filters_sync] Created filter "%s" with ID %d', $name, $filter_id));
                    $this->syncTranslation((int) $filter_id, $name_ru, $row_number, $name, $report, $filter_type);
                    $report->addCreated($name, (int) $filter_id);
                    $processed_ids[$normalized_name] = (int) $filter_id;
                } else {
                    $report->addError(sprintf('Row %d: failed to create filter "%s"', $row_number, $name));
                    \fn_mwl_xlsx_append_log(sprintf('[filters_sync] Failed to create filter "%s"', $name));
                }

                continue;
            }

            $existing_filter = $existing_by_name[$normalized_name];
            $filter_id = (int) $existing_filter['filter_id'];
            $updates = [];
            $params_updates = [];
            $had_changes = false;

            if ((int) ($existing_filter['position'] ?? 0) !== $position) {
                $updates['position'] = $position;
            }

            $existing_round_to = $this->normalizeRoundTo($existing_filter['round_to'] ?? null);
            if ($existing_round_to !== $round_to) {
                $updates['round_to'] = $round_to;
            }

            $current_display = $this->getExistingValue($existing_filter, 'display', 'Y');
            if ($current_display !== $display) {
                $updates['display'] = $display;
            }

            if ((int) ($existing_filter['feature_id'] ?? 0) !== $feature_id) {
                $updates['feature_id'] = $feature_id;
            }

            $current_field_type = $this->getExistingValue($existing_filter, 'field_type', $field_type);
            if ($current_field_type !== $field_type) {
                $updates['field_type'] = $field_type;
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
                $filter_data = array_merge($existing_filter, $updates);

                $filter_data['filter'] = $name;
                $filter_data['position'] = $position;
                $filter_data['round_to'] = $round_to;
                $filter_data['display'] = $display;
                $filter_data['feature_id'] = $feature_id;
                $filter_data['field_type'] = $field_type;
                $filter_data['filter_type'] = $filter_type;

                $filter_data['params'] = array_merge($existing_params, $params);

                \fn_mwl_xlsx_append_log(sprintf('[filters_sync] Updating filter #%d "%s": %s', $filter_id, $name, json_encode([
                    'updates' => $updates,
                    'params_updates' => $params_updates,
                ], JSON_UNESCAPED_UNICODE)));

                $result = fn_update_product_filter($filter_data, $filter_id);

                if ($result) {
                    $had_changes = true;
                    $report->addUpdated($name, $filter_id);
                    \fn_mwl_xlsx_append_log(sprintf('[filters_sync] Updated filter #%d "%s"', $filter_id, $name));
                } else {
                    $report->addError(sprintf('Row %d: failed to update filter "%s"', $row_number, $name));
                    \fn_mwl_xlsx_append_log(sprintf('[filters_sync] Failed to update filter #%d "%s"', $filter_id, $name));
                }
            }

            $translation_result = $this->syncTranslation($filter_id, $name_ru, $row_number, $name, $report, $filter_type);

            if ($translation_result === true && !$had_changes) {
                $had_changes = true;
                $report->addUpdated($name, $filter_id);
            }

            if (!$had_changes && $translation_result !== false) {
                $report->addSkipped(sprintf('Row %d: no changes for "%s"', $row_number, $name));
            }

            $processed_ids[$normalized_name] = $filter_id;
        }

        $existing_ids = array_map('intval', array_column($existing, 'filter_id'));
        $processed_filter_ids = array_values($processed_ids);

        $obsolete_ids = array_diff($existing_ids, $processed_filter_ids);

        if ($obsolete_ids) {
            \fn_mwl_xlsx_append_log(sprintf('[filters_sync] Deletion disabled. Obsolete filter IDs detected but kept: %s', implode(', ', $obsolete_ids)));
        }

        $this->clearFiltersCache();

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

    /**
     * @param mixed $value
     */
    private function normalizeFeatureId($value): int
    {
        if ($value === null || $value === '') {
            return self::DEFAULT_FEATURE_ID;
        }

        return (int) $value;
    }

    /**
     * @param mixed $value
     */
    private function normalizeRoundTo($value): string
    {
        if ($value === null || $value === '') {
            return '0';
        }

        if (is_string($value)) {
            $value = trim(str_replace(',', '.', $value));
        }

        if (!is_numeric($value)) {
            return '0';
        }

        $float_value = (float) $value;

        if ((int) $float_value == $float_value) {
            return (string) (int) $float_value;
        }

        $normalized = rtrim(rtrim(sprintf('%.8F', $float_value), '0'), '.');

        return $normalized === '' ? '0' : $normalized;
    }

    private function syncTranslation(
        int $filter_id,
        string $name_ru,
        int $row_number,
        string $name,
        FilterSyncReport $report,
        string $filter_type
    ): ?bool
    {
        $name_ru = trim($name_ru);

        if ($name_ru === '') {
            return null;
        }

        $result = fn_update_product_filter([
            'filter' => $name_ru,
            'filter_type' => $filter_type,
        ], $filter_id, self::RUSSIAN_LANG_CODE);

        if (!$result) {
            $report->addError(sprintf(
                'Row %d: failed to update %s translation for "%s"',
                $row_number,
                self::RUSSIAN_LANG_CODE,
                $name
            ));

            return false;
        }

        \fn_mwl_xlsx_append_log(sprintf('[filters_sync] Updated "%s" translation for filter #%d', self::RUSSIAN_LANG_CODE, $filter_id));

        return true;
    }

    private function resolveFilterType(?array $existing_filter, int $feature_id, string $field_type): string
    {
        $existing_type = '';

        if ($existing_filter && isset($existing_filter['filter_type'])) {
            $existing_type = (string) $existing_filter['filter_type'];
        }

        if ($feature_id > 0) {
            $prefix = $this->getFeatureFilterPrefix($feature_id, $existing_type);

            return $prefix . $feature_id;
        }

        if ($existing_type !== '' && !$this->isFeatureFilterType($existing_type)) {
            $prefix = substr($existing_type, 0, 2);

            if ($prefix === 'R-' || $prefix === 'B-') {
                return $prefix . ($field_type !== '' ? $field_type : self::PRICE_FIELD_TYPE);
            }

            return $existing_type;
        }

        $effective_field_type = $field_type !== '' ? $field_type : self::PRICE_FIELD_TYPE;

        return 'R-' . $effective_field_type;
    }

    private function isFeatureFilterType(string $filter_type): bool
    {
        return strpos($filter_type, 'FF-') === 0
            || strpos($filter_type, 'RF-') === 0
            || strpos($filter_type, 'DF-') === 0;
    }

    private function getFeatureFilterPrefix(int $feature_id, string $existing_type): string
    {
        if ($this->isFeatureFilterType($existing_type)) {
            return substr($existing_type, 0, 3);
        }

        $feature_type = fn_get_product_feature_type_by_feature_id($feature_id);

        if ($feature_type === 'D') {
            return 'DF-';
        }

        if (in_array($feature_type, ['N', 'O'], true)) {
            return 'RF-';
        }

        return 'FF-';
    }

    private function clearFiltersCache(): void
    {
        $cleared = false;

        if (class_exists(Registry::class)) {
            if (method_exists(Registry::class, 'delByPattern')) {
                Registry::delByPattern('product_filters_filters');
                $cleared = true;
            }

            if (method_exists(Registry::class, 'del')) {
                Registry::del('product_filters_filters');
                $cleared = true;
            }
        }

        if (!$cleared && function_exists('fn_clear_cache')) {
            fn_clear_cache(['target' => 'registry']);
            $cleared = true;
        }

        if ($cleared) {
            \fn_mwl_xlsx_append_log('[filters_sync] Cleared filters cache');
        } else {
            \fn_mwl_xlsx_append_log('[filters_sync] Unable to clear filters cache (no suitable method)');
        }
    }
}
