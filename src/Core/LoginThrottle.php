<?php

declare(strict_types=1);

namespace App\Core;

final class LoginThrottle
{
    public static function record(string $email, string $ip, bool $successful): void
    {
        Database::insert(
            'INSERT INTO login_attempts (email, ip_address, succeeded) VALUES (:email, :ip, :ok)',
            ['email' => $email, 'ip' => $ip, 'ok' => $successful]
        );

        if (random_int(1, 50) === 1) {
            Database::query('DELETE FROM login_attempts WHERE attempted_at < (NOW() - INTERVAL 30 DAY)');
        }
    }

    public static function isLocked(string $email, string $ip): bool
    {
        $maxAttempts = (int) Config::get('login_throttle.max_attempts', 5);
        $windowMinutes = (int) Config::get('login_throttle.lockout_minutes', 15);

        $byEmail = self::failedCount('email', $email, $windowMinutes);
        if ($byEmail >= $maxAttempts) {
            return true;
        }

        $byIp = self::failedCount('ip_address', $ip, $windowMinutes);

        return $byIp >= $maxAttempts * 3;
    }

    private static function failedCount(string $column, string $value, int $windowMinutes): int
    {
        $row = Database::one(
            "SELECT COUNT(*) AS n FROM login_attempts
             WHERE {$column} = :value AND succeeded = 0
               AND attempted_at > (NOW() - INTERVAL :minutes MINUTE)",
            ['value' => $value, 'minutes' => $windowMinutes]
        );

        return (int) ($row['n'] ?? 0);
    }

    public static function clear(string $email, string $ip): void
    {
        Database::query(
            'DELETE FROM login_attempts WHERE succeeded = 0 AND (email = :email OR ip_address = :ip)',
            ['email' => $email, 'ip' => $ip]
        );
    }

    public static function remaining(string $email, string $ip): int
    {
        $maxAttempts = (int) Config::get('login_throttle.max_attempts', 5);
        $windowMinutes = (int) Config::get('login_throttle.lockout_minutes', 15);

        return max(0, $maxAttempts - self::failedCount('email', $email, $windowMinutes));
    }
}
