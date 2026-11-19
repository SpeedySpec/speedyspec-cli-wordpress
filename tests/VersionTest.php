<?php

declare(strict_types=1);

use SpeedySpec\CliWordPress\Version;

function versionRoot(?string $versionFile): string
{
    $root = sys_get_temp_dir() . '/speedyspec-wp-version-' . bin2hex(random_bytes(6));
    mkdir($root . '/wp-includes', 0777, true);
    if ($versionFile !== null)
    {
        file_put_contents($root . '/wp-includes/version.php', $versionFile);
    }

    return $root;
}

it('reads the version WordPress declares, without running the file', function ()
{
    expect(Version::of(versionRoot((string) file_get_contents(__DIR__ . '/fixtures/version.php'))))->toBe('7.1.5');
});

it('has no version without the file or the line', function ()
{
    expect(Version::of(versionRoot(null)))->toBeNull()
        ->and(Version::of(versionRoot("<?php\n\$wp_db_version = 1;\n")))->toBeNull();
});
