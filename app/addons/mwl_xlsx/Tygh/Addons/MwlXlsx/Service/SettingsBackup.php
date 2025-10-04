<?php

namespace Tygh\Addons\MwlXlsx\Service;

use Tygh\Settings;

class SettingsBackup
{
    private const ADDON_ID = 'mwl_xlsx';

    /**
     * Сохраняет текущие значения настроек аддона в одну запись (без company_id).
     */
    public static function backup(): void
    {
        $settings = self::getAddonSettings();
        $json = json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            $json = '{}';
        }

        db_replace_into('mwl_settings_backup', [
            'addon'         => self::ADDON_ID,
            'settings_json' => $json,
            'created_at'    => TIME,
        ]);
    }

    /**
     * Восстанавливает значения из бэкапа (только в существующие айтемы схемы).
     */
    public static function restore(): void
    {
        $row = db_get_row('SELECT * FROM ?:mwl_settings_backup WHERE addon = ?s', self::ADDON_ID);
        if (!$row || empty($row['settings_json'])) {
            return;
        }

        $data = json_decode($row['settings_json'], true);
        if (!is_array($data) || !$data) {
            return;
        }

        $settings = Settings::instance();
        foreach ($data as $name => $value) {
            $settings->updateValue($name, (string) $value, self::ADDON_ID);
        }
    }

    /**
     * Возвращает плоский массив name => value для настроек аддона без company_id.
     */
    private static function getAddonSettings(): array
    {
        $all = Settings::instance()->getValues(self::ADDON_ID, Settings::ADDON_SECTION, CART_LANGUAGE);
        if (!is_array($all)) {
            return [];
        }

        $flat = [];
        array_walk_recursive($all, static function ($value, $name) use (&$flat) {
            if (is_array($value)) {
                return;
            }

            $flat[$name] = $value;
        });

        return $flat;
    }
}


