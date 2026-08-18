<?php

declare(strict_types=1);

namespace App\Core;

final class Upload
{
    /** Normalises $_FILES[$field] (single or name="x[]") into a flat list. */
    public static function files(string $field): array
    {
        if (!isset($_FILES[$field])) {
            return [];
        }

        $raw = $_FILES[$field];

        if (!is_array($raw['name'])) {
            return $raw['error'] === UPLOAD_ERR_NO_FILE ? [] : [$raw];
        }

        $files = [];
        foreach ($raw['name'] as $i => $name) {
            if ($raw['error'][$i] === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $files[] = [
                'name' => $name,
                'type' => $raw['type'][$i],
                'tmp_name' => $raw['tmp_name'][$i],
                'error' => $raw['error'][$i],
                'size' => $raw['size'][$i],
            ];
        }

        return $files;
    }

    /**
     * Real (sniffed) mime types trusted for a handful of well-known
     * extensions -- CAD formats (dwg/dxf/step/iges) have no reliable magic
     * number, so we fall back to extension-only checking for those rather
     * than risk false-positive rejections.
     */
    private const KNOWN_MIMES = [
        'pdf' => ['application/pdf'],
        'png' => ['image/png'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'doc' => ['application/msword'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
    ];

    /** Returns a human error message, or null if the file is acceptable. */
    public static function validate(array $file, array $allowedExtensions, int $maxBytes): ?string
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return 'The file failed to upload. Please try again.';
        }

        if (!is_uploaded_file($file['tmp_name'])) {
            return 'The file failed to upload. Please try again.';
        }

        if ((int) $file['size'] <= 0) {
            return 'The uploaded file is empty.';
        }

        if ((int) $file['size'] > $maxBytes) {
            return 'The file is too large (max ' . self::formatBytes($maxBytes) . ').';
        }

        $extension = strtolower((string) pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, $allowedExtensions, true)) {
            return 'That file type is not allowed. Allowed: ' . implode(', ', $allowedExtensions);
        }

        if (isset(self::KNOWN_MIMES[$extension])) {
            $detected = self::detectMime($file['tmp_name']);
            if ($detected !== null && !in_array($detected, self::KNOWN_MIMES[$extension], true)) {
                return "The file's content doesn't look like a .{$extension} file.";
            }
        }

        return null;
    }

    public static function store(array $file, string $relativeDirectory): string
    {
        $extension = strtolower((string) pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        $filename = date('Ymd-His') . '-' . bin2hex(random_bytes(6)) . ($extension !== '' ? '.' . $extension : '');

        $relativeDirectory = trim($relativeDirectory, '/');
        $targetDir = rtrim((string) Config::get('storage.uploads'), '/\\') . '/' . $relativeDirectory;

        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0775, true);
        }

        $relativePath = $relativeDirectory . '/' . $filename;
        $absolutePath = $targetDir . '/' . $filename;

        if (!move_uploaded_file($file['tmp_name'], $absolutePath)) {
            throw new \RuntimeException('Could not move the uploaded file into storage.');
        }

        @chmod($absolutePath, 0640);

        return $relativePath;
    }

    public static function absolutePath(string $relativePath): ?string
    {
        $root = realpath((string) Config::get('storage.uploads'));
        if ($root === false) {
            return null;
        }

        $candidate = realpath($root . '/' . ltrim($relativePath, '/'));
        if ($candidate === false || !str_starts_with($candidate, $root)) {
            return null;
        }

        return $candidate;
    }

    public static function delete(string $relativePath): void
    {
        $path = self::absolutePath($relativePath);
        if ($path !== null) {
            @unlink($path);
        }
    }

    public static function detectMime(string $absolutePath): ?string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            return null;
        }
        $mime = finfo_file($finfo, $absolutePath);
        finfo_close($finfo);

        return $mime === false ? null : $mime;
    }

    public static function displayName(string $name): string
    {
        $base = basename($name);

        return preg_replace('/[^A-Za-z0-9 ._\-()]/', '_', $base) ?? $base;
    }

    public static function formatBytes(int $bytes): string
    {
        if ($bytes >= 1024 * 1024) {
            return round($bytes / (1024 * 1024), 1) . ' MB';
        }

        return round($bytes / 1024, 1) . ' KB';
    }
}
