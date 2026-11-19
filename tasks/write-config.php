<?php

declare(strict_types=1);

/*
 * Writes the install's wp-config.php from its own wp-config-sample.php, with the database settings, the table prefix
 * and fresh salts. Safe to run twice: an existing wp-config.php is left as it is, since replacing its salts would log
 * everyone out. The file keeps the sample's permissions, because php-fpm, often another user, has to read it. Errors
 * never repeat the values given, the password among them.
 */

require_once __DIR__ . '/../src/ConfigFile.php';

return static function (array $input): string
{
    $root = getcwd();
    $config = $root . '/wp-config.php';
    if (file_exists($config))
    {
        return 'wp-config.php already exists; left as it is';
    }
    $samplePath = $root . '/wp-config-sample.php';
    $sample = @file_get_contents($samplePath);
    if ($sample === false)
    {
        throw new RuntimeException('the install has no wp-config-sample.php to start from');
    }
    $text = \SpeedySpec\CliWordPress\ConfigFile::render($sample, $input, \SpeedySpec\CliWordPress\ConfigFile::salts());
    // Ends in .php: a copy left behind by a crash holds the password, and the web server runs it (printing
    // nothing) rather than serving it as text.
    $temporary = $root . '/.wp-config.speedyspec-' . bin2hex(random_bytes(6)) . '.php';
    if (@file_put_contents($temporary, $text) === false)
    {
        throw new RuntimeException(sprintf('cannot write in %s', $root));
    }
    $mode = @fileperms($samplePath);
    if ($mode !== false)
    {
        @chmod($temporary, $mode & 0777);
    }
    if (!@rename($temporary, $config))
    {
        @unlink($temporary);
        throw new RuntimeException(sprintf('cannot create %s', $config));
    }

    return 'wrote wp-config.php';
};
