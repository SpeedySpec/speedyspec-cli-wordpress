<?php

declare(strict_types=1);

use SpeedySpec\Cli\Contracts\Extension;
use SpeedySpec\CliWordPress\Headers;
use SpeedySpec\CliWordPress\Layout;

function tree(array $files): string
{
    $root = sys_get_temp_dir() . '/speedyspec-wp-layout-' . bin2hex(random_bytes(6));
    foreach ($files as $path => $body)
    {
        $dir = dirname($root . '/' . $path);
        if (!is_dir($dir))
        {
            mkdir($dir, 0777, true);
        }
        file_put_contents($root . '/' . $path, $body);
    }

    return $root;
}

const HELLO = "<?php\n/**\n * @package Hello_Dolly\n * @version 1.7.2\n */\n/*\nPlugin Name: Hello Dolly\nPlugin URI: http://wordpress.org/plugins/hello-dolly/\nDescription: This is not just a plugin.\nVersion: 1.7.2\n*/\n";
const AKISMET = "<?php\n/**\n * @package Akismet\n */\n/*\n * Plugin Name: Akismet Anti-spam: Spam Protection\n * Version: 5.3.5\n * Requires PHP: 7.2\n */\n";

it('reads plugin headers as WordPress does', function (string $source, ?array $expected)
{
    $root = tree(['p.php' => $source]);
    expect(Headers::plugin($root . '/p.php'))->toBe($expected);
})->with([
    'hello dolly' => [HELLO, ['name' => 'Hello Dolly', 'version' => '1.7.2']],
    'akismet' => [AKISMET, ['name' => 'Akismet Anti-spam: Spam Protection', 'version' => '5.3.5']],
    'crlf' => [str_replace("\n", "\r\n", HELLO), ['name' => 'Hello Dolly', 'version' => '1.7.2']],
    'no version' => ["<?php\n/* Plugin Name: Bare */\n", ['name' => 'Bare', 'version' => null]],
    'not a plugin' => ["<?php\necho 'hi';\n", null],
]);

it('reads a theme stylesheet', function ()
{
    $root = tree(['style.css' => "/*\nTheme Name: Twenty Twenty-Five\nVersion: 1.2\n*/\n"]);
    expect(Headers::theme($root . '/style.css'))->toBe(['name' => 'Twenty Twenty-Five', 'version' => '1.2']);
});

it('finds the three shapes WordPress has', function ()
{
    $root = tree([
        'plugins/akismet/index.php' => "<?php // Silence is golden.\n",
        'plugins/akismet/akismet.php' => AKISMET,
        'plugins/hello.php' => HELLO,
        'plugins/index.php' => "<?php // Silence is golden.\n",
        'plugins/empty/readme.txt' => 'nothing',
        'themes/twentytwentyfive/style.css' => "/*\nTheme Name: Twenty Twenty-Five\nVersion: 1.2\n*/",
        'themes/broken/index.php' => '<?php',
    ]);
    expect(Layout::inspect('plugin', $root . '/plugins/akismet'))
        ->toEqual(new Extension('plugin', 'akismet', 'Akismet Anti-spam: Spam Protection', '5.3.5'))
        ->and(Layout::inspect('plugin', $root . '/plugins/hello.php'))
        ->toEqual(new Extension('plugin', 'hello', 'Hello Dolly', '1.7.2'))
        ->and(Layout::inspect('plugin', $root . '/plugins/index.php'))->toBeNull()
        ->and(Layout::inspect('plugin', $root . '/plugins/empty'))->toBeNull()
        ->and(Layout::inspect('theme', $root . '/themes/twentytwentyfive'))
        ->toEqual(new Extension('theme', 'twentytwentyfive', 'Twenty Twenty-Five', '1.2'))
        ->and(Layout::inspect('theme', $root . '/themes/broken'))->toBeNull();
});
