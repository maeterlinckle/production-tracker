<?php

declare(strict_types=1);

namespace App\Core;

/**
 * AES-256-GCM encryption for secrets that have to live in the database (the
 * SMTP password). Key comes from APP_KEY. Format: 'v1.' + base64(iv[12] +
 * tag[16] + ciphertext). Fails closed -- never stores plaintext if it can't
 * encrypt.
 */
final class Crypto
{
    public static function isAvailable(): bool
    {
        return extension_loaded('openssl');
    }

    public static function hasKey(): bool
    {
        return self::rawKey() !== null;
    }

    private static function rawKey(): ?string
    {
        $key = (string) Config::get('app.key', '');
        if ($key === '') {
            return null;
        }

        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);

            return $decoded === false ? null : $decoded;
        }

        return $key;
    }

    public static function encrypt(string $plaintext): ?string
    {
        $key = self::rawKey();
        if ($key === null || !self::isAvailable()) {
            return null;
        }

        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);

        if ($ciphertext === false) {
            return null;
        }

        return 'v1.' . base64_encode($iv . $tag . $ciphertext);
    }

    public static function decrypt(string $encoded): ?string
    {
        $key = self::rawKey();
        if ($key === null || !self::isAvailable() || !str_starts_with($encoded, 'v1.')) {
            return null;
        }

        $raw = base64_decode(substr($encoded, 3), true);
        if ($raw === false || strlen($raw) < 28) {
            return null;
        }

        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $ciphertext = substr($raw, 28);

        $plaintext = openssl_decrypt($ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);

        return $plaintext === false ? null : $plaintext;
    }
}
