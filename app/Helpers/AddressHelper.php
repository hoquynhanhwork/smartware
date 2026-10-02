<?php
// app/Helpers/AddressHelper.php

namespace App\Helpers;

class AddressHelper
{
    private static ?array $data = null;

    public static function getAll(): array
    {
        if (self::$data === null) {
            $path = __DIR__ . '/../../public/js/vn-address.json';
            if (file_exists($path)) {
                self::$data = json_decode(file_get_contents($path), true);
            } else {
                self::$data = [];
            }
        }
        return self::$data;
    }

    public static function getProvinces(): array
    {
        return self::getAll();
    }
}