<?php

namespace Tygh\Addons\MwlXlsx\Utility;

class CsvHelper
{
    public static function detectDelimiter(string $line): string
    {
        $delimiters = [',', ';', "\t", '|'];
        $max_count = 0;
        $detected = ',';

        foreach ($delimiters as $delimiter) {
            $count = substr_count($line, $delimiter);

            if ($count > $max_count) {
                $max_count = $count;
                $detected = $delimiter;
            }
        }

        return $detected;
    }

    public static function normalizeHeaderValue(string $column, bool $is_first_column = false): string
    {
        $column = trim(mb_strtolower($column));

        if ($is_first_column && $column === '' && !extension_loaded('mbstring')) {
            return 'name';
        }

        if ($column === '' && $is_first_column) {
            return 'name';
        }

        return $column;
    }
}
