<?php

declare(strict_types=1);

namespace SpeedySpec\CliWordPress;

/**
 * Reads plugin and theme headers the way WordPress's `get_file_data()` does, without loading WordPress.
 *
 * Only the first 8 KiB of the file is read, header names are matched case-insensitively at the start of a comment
 * line, and a value ends at the end of its line or at a closing comment.
 */
final class Headers
{
    /**
     * Reads a PHP file's `Plugin Name` and `Version` headers.
     *
     * @return ?array{name: string, version: ?string}
     *   The name and version, or null when the file has no `Plugin Name`.
     */
    public static function plugin(string $file): ?array
    {
        return self::read($file, 'Plugin Name');
    }

    /**
     * Reads a theme stylesheet's `Theme Name` and `Version` headers.
     *
     * @return ?array{name: string, version: ?string}
     *   The name and version, or null when the stylesheet has no `Theme Name`.
     */
    public static function theme(string $styleCss): ?array
    {
        return self::read($styleCss, 'Theme Name');
    }

    /**
     * @return ?array{name: string, version: ?string}
     */
    private static function read(string $file, string $nameHeader): ?array
    {
        if (!is_file($file) || !is_readable($file))
        {
            return null;
        }
        $handle = fopen($file, 'rb');
        if ($handle === false)
        {
            return null;
        }
        $text = (string) fread($handle, 8192);
        fclose($handle);
        $text = str_replace("\r", "\n", $text);
        $name = self::header($text, $nameHeader);
        if ($name === null || $name === '')
        {
            return null;
        }
        $version = self::header($text, 'Version');

        return ['name' => $name, 'version' => $version === '' ? null : $version];
    }

    private static function header(string $text, string $header): ?string
    {
        $pattern = '/^(?:[ \t]*<\?php)?[ \t\/*#@]*' . preg_quote($header, '/') . ':(.*)$/mi';
        if (preg_match($pattern, $text, $match) !== 1)
        {
            return null;
        }

        return trim((string) preg_replace('/\s*(?:\*\/|\?>).*/', '', $match[1]));
    }
}
