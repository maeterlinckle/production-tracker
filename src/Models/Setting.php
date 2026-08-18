<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/** Generic key/value settings store, same pattern as Kitwell's Setting model. */
final class Setting
{
    public static function get(string $key, ?string $default = null): ?string
    {
        $row = Database::one('SELECT setting_value FROM settings WHERE setting_key = :key', ['key' => $key]);

        return $row === null || $row['setting_value'] === null ? $default : $row['setting_value'];
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key);

        return $value === null ? $default : in_array($value, ['1', 'true', 'yes'], true);
    }

    public static function all(): array
    {
        $rows = Database::all('SELECT setting_key, setting_value FROM settings');
        $out = [];
        foreach ($rows as $row) {
            $out[$row['setting_key']] = $row['setting_value'];
        }

        return $out;
    }

    public static function put(string $key, ?string $value): void
    {
        Database::query(
            'INSERT INTO settings (setting_key, setting_value) VALUES (:key, :value)
             ON DUPLICATE KEY UPDATE setting_value = :value2',
            ['key' => $key, 'value' => $value, 'value2' => $value]
        );
    }
}
