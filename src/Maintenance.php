<?php

declare(strict_types=1);

namespace SpeedySpec\CliWordPress;

/**
 * Writes WordPress's `.maintenance` file the way WordPress itself does.
 *
 * WordPress shows its maintenance page while the file is there, until `$upgrading` is ten minutes old. The time is
 * written as a number when the file is made, so a file the CLI could not remove (it was stopped or killed mid-swap)
 * lapses on its own instead of keeping the site down.
 */
final class Maintenance
{
    /**
     * Builds the file's contents, dated now.
     */
    public static function contents(): string
    {
        return '<?php $upgrading = ' . time() . ';';
    }
}
