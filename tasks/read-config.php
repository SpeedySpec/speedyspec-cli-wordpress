<?php

declare(strict_types=1);

/*
 * Reads the install's database settings from its wp-config.php, in the runner, without running WordPress: ABSPATH
 * points at an empty wp-settings.php, so the config defines its constants and stops. Constants built from environment
 * variables resolve as they would for the site.
 */

require_once __DIR__ . '/../src/ConfigFile.php';

return static function (array $input): array
{
    if (!defined('ABSPATH'))
    {
        define('ABSPATH', __DIR__ . '/stub/');
    }
    $config = getcwd() . '/wp-config.php';
    if (!is_file($config))
    {
        throw new RuntimeException('the install has no wp-config.php');
    }
    $table_prefix = 'wp_';
    require $config;
    foreach (['DB_NAME', 'DB_USER', 'DB_PASSWORD', 'DB_HOST'] as $constant)
    {
        if (!defined($constant))
        {
            throw new RuntimeException(sprintf('wp-config.php does not define %s', $constant));
        }
    }
    $host = \SpeedySpec\CliWordPress\ConfigFile::parseHost((string) DB_HOST);
    $charset = defined('DB_CHARSET') && (string) DB_CHARSET !== '' ? (string) DB_CHARSET : 'utf8mb4';

    return [
        'host' => $host['host'],
        'port' => $host['port'],
        'socket' => $host['socket'],
        'database' => (string) DB_NAME,
        'user' => (string) DB_USER,
        'password' => (string) DB_PASSWORD,
        'tablePrefix' => (string) $table_prefix,
        'charset' => $charset,
    ];
};
