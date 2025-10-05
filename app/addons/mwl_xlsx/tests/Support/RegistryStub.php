<?php

namespace Tygh\Addons\MwlXlsx\Tests\Support;

class RegistryStub
{
    private static $data = [];

    public static function get($key)
    {
        return self::$data[$key] ?? '';
    }

    public static function set($key, $value): void
    {
        self::$data[$key] = $value;
    }
}
