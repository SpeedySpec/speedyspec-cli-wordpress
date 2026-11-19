<?php

declare(strict_types=1);

namespace SpeedySpec\CliWordPress;

/**
 * Reads the version of the WordPress in an install, for the manifest of its backups.
 *
 * The version is declared in `wp-includes/version.php` as `$wp_version = '…';`. That file is WordPress code, and
 * speedyspec never runs WordPress, so the line is read as text.
 */
final class Version
{
    /**
     * Reads the version of the WordPress at `$root`, or gives null when it cannot be told.
     */
    public static function of(string $root): ?string
    {
        $file = $root . '/wp-includes/version.php';
        if (!is_file($file) || !is_readable($file))
        {
            return null;
        }
        $text = (string) file_get_contents($file, false, null, 0, 65536);
        if (preg_match('/^\$wp_version\s*=\s*\'([^\'\s]{1,64})\';/m', $text, $match) !== 1)
        {
            return null;
        }

        return $match[1];
    }
}
