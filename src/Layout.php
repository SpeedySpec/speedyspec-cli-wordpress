<?php

declare(strict_types=1);

namespace SpeedySpec\CliWordPress;

use SpeedySpec\Cli\Contracts\Extension;

/**
 * Recognizes WordPress plugins and themes on disk, for the CLI's `plugin` and `theme` commands.
 *
 * A plugin is a directory whose top level holds a PHP file with a `Plugin Name` header, or a single PHP file in the
 * plugins directory with one (like Hello Dolly). A theme is a directory whose `style.css` has a `Theme Name`.
 */
final class Layout
{
    /**
     * Describes the entry at `$path`, or gives null when it is not a plugin or theme.
     */
    public static function inspect(string $kind, string $path): ?Extension
    {
        if ($kind === 'theme')
        {
            $headers = is_dir($path) ? Headers::theme($path . '/style.css') : null;

            return $headers === null ? null : new Extension('theme', basename($path), $headers['name'], $headers['version']);
        }
        if (is_file($path))
        {
            if (!str_ends_with($path, '.php'))
            {
                return null;
            }
            $headers = Headers::plugin($path);

            return $headers === null ? null : new Extension('plugin', basename($path, '.php'), $headers['name'], $headers['version']);
        }
        if (!is_dir($path))
        {
            return null;
        }
        $files = glob($path . '/*.php') ?: [];
        sort($files);
        foreach ($files as $file)
        {
            $headers = Headers::plugin($file);
            if ($headers !== null)
            {
                return new Extension('plugin', basename($path), $headers['name'], $headers['version']);
            }
        }

        return null;
    }
}
