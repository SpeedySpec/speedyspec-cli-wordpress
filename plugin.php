<?php

declare(strict_types=1);

/*
 * The speedyspec CLI plugin for WordPress. It offers WordPress as an app, finishes an install by writing
 * wp-config.php, says where the install's database is, and describes its plugins and themes. It never runs WordPress:
 * the site is served by the machine's own PHP, and the rest (the site title, the admin user, activating plugins) is
 * done in WordPress's own installer and admin.
 */

use SpeedySpec\Cli\Contracts\App;
use SpeedySpec\Cli\Contracts\AppTask;
use SpeedySpec\Cli\Contracts\BackupSpec;
use SpeedySpec\Cli\Contracts\ExtensionLayout;
use SpeedySpec\Cli\Contracts\Install;
use SpeedySpec\Cli\Contracts\InstallOption;
use SpeedySpec\Cli\Contracts\InstallRequest;
use SpeedySpec\Cli\Contracts\PrefixedColumn;
use SpeedySpec\Cli\Contracts\SetupPlan;
use SpeedySpec\Cli\Contracts\SiteUrl;
use SpeedySpec\Cli\Contracts\ZipSource;
use SpeedySpec\CliWordPress\Layout;
use SpeedySpec\CliWordPress\Version;
use SpeedySpec\Hooks\Hook;

require_once __DIR__ . '/src/ConfigFile.php';
require_once __DIR__ . '/src/Headers.php';
require_once __DIR__ . '/src/Layout.php';
require_once __DIR__ . '/src/Version.php';

const SPEEDYSPEC_WORDPRESS_OPTIONS = ['db-name', 'db-user', 'db-password', 'db-host', 'table-prefix'];

Hook::add('speedyspec_apps', static function (array $apps): array
{
    $apps[] = new App('wordpress', 'WordPress', [], ['zip']);

    return $apps;
});

Hook::add('speedyspec_install_options', static function (array $options, string $app): array
{
    if ($app !== 'wordpress')
    {
        return $options;
    }
    $options[] = new InstallOption('db-name', 'Database name', required: true);
    $options[] = new InstallOption('db-user', 'Database user', required: true);
    $options[] = new InstallOption('db-password', 'Database password', required: true, secret: true);
    $options[] = new InstallOption('db-host', 'Database host', default: 'localhost');
    $options[] = new InstallOption('table-prefix', 'Table prefix', default: 'wp_');

    return $options;
}, 10, 2);

Hook::add('speedyspec_driver_zip', static function (array $sources, InstallRequest $request): array
{
    if ($request->app !== 'wordpress')
    {
        return $sources;
    }
    $downloads = rtrim(getenv('SPEEDYSPEC_WORDPRESS_DOWNLOADS') ?: 'https://wordpress.org', '/');
    $sources[] = new ZipSource($downloads . '/latest.zip', '.', stripComponents: 1);

    return $sources;
}, 10, 2);

Hook::add('speedyspec_app_setup', static function (?SetupPlan $plan, Install $install, InstallRequest $request): ?SetupPlan
{
    if ($install->app !== 'wordpress')
    {
        return $plan;
    }
    $input = array_intersect_key($request->options, array_flip(SPEEDYSPEC_WORDPRESS_OPTIONS));

    return new SetupPlan([new AppTask(__DIR__ . '/tasks/write-config.php', $input, loadsApp: false)]);
}, 10, 3);

Hook::add('speedyspec_app_database', static function (mixed $answer, Install $install): mixed
{
    if ($install->app !== 'wordpress')
    {
        return $answer;
    }

    return new AppTask(__DIR__ . '/tasks/read-config.php', loadsApp: false, timeoutSeconds: 60);
}, 10, 2);

Hook::add('speedyspec_app_extensions', static function (?ExtensionLayout $layout, Install $install): ?ExtensionLayout
{
    if ($install->app !== 'wordpress')
    {
        return $layout;
    }

    return new ExtensionLayout(
        ['plugin' => 'wp-content/plugins', 'theme' => 'wp-content/themes'],
        Layout::inspect(...),
    );
}, 10, 2);

/*
 * What a backup of WordPress holds: the whole install except its caches, `wp-config.php` kept from the target on
 * restore (it names the target's own database), the site URL in the `siteurl` option, and the option and user meta
 * keys that carry the table prefix (`wp_user_roles`, `wp_capabilities`).
 */
Hook::add('speedyspec_app_backup', static function (?BackupSpec $spec, Install $install): ?BackupSpec
{
    if ($install->app !== 'wordpress')
    {
        return $spec;
    }

    return new BackupSpec(
        ['.'],
        ['wp-content/cache', 'wp-content/upgrade', '.maintenance'],
        ['wp-config.php'],
        new SiteUrl('options', 'option_name', 'siteurl', 'option_value'),
        [new PrefixedColumn('options', 'option_name'), new PrefixedColumn('usermeta', 'meta_key')],
        $install->root === null ? null : Version::of($install->root),
    );
}, 10, 2);
