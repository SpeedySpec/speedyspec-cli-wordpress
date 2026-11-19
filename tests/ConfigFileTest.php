<?php

declare(strict_types=1);

use SpeedySpec\CliWordPress\ConfigFile;

function sample(): string
{
    return (string) file_get_contents(__DIR__ . '/fixtures/wp-config-sample.php');
}

function values(array $overrides = []): array
{
    return $overrides + [
        'db-name' => 'blog',
        'db-user' => 'blogger',
        'db-password' => 'hunter2',
        'db-host' => 'localhost',
        'table-prefix' => 'wp_',
    ];
}

/**
 * Runs read-config.php against a root holding `$config`, in a PHP process of its own (it defines constants).
 */
function readBack(string $config): array
{
    $root = sys_get_temp_dir() . '/speedyspec-wp-' . bin2hex(random_bytes(6));
    mkdir($root);
    file_put_contents($root . '/wp-config.php', $config);
    $task = realpath(__DIR__ . '/../tasks/read-config.php');
    $code = 'chdir($argv[1]); $task = require $argv[2]; echo json_encode($task([]));';
    $out = shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' ' . escapeshellarg($root) . ' ' . escapeshellarg($task));
    array_map('unlink', glob($root . '/*'));
    rmdir($root);

    return json_decode((string) $out, true, 512, JSON_THROW_ON_ERROR);
}

it('writes the database settings, the prefix and eight distinct salts', function ()
{
    $salts = ConfigFile::salts();
    $config = ConfigFile::render(sample(), values(), $salts);
    expect($config)->toContain("define( 'DB_NAME', 'blog' );")
        ->and($config)->toContain("define( 'DB_USER', 'blogger' );")
        ->and($config)->toContain("define( 'DB_PASSWORD', 'hunter2' );")
        ->and($config)->toContain("\$table_prefix = 'wp_';")
        ->and($config)->not->toContain('put your unique phrase here')
        ->and(count(array_unique($salts)))->toBe(8);
    foreach ($salts as $salt)
    {
        expect(strlen($salt))->toBe(64)->and($config)->toContain($salt);
    }
});

it('keeps values that break PHP strings', function (string $value)
{
    $config = ConfigFile::render(sample(), values(['db-password' => $value, 'db-name' => $value]), ConfigFile::salts());
    PhpToken::tokenize($config, TOKEN_PARSE);
    $read = readBack($config);
    expect($read['password'])->toBe($value)->and($read['database'])->toBe($value);
})->with([
    'quote' => ["it's"],
    'backslash' => ['back\\slash\\'],
    'dollar' => ['$HOME and {$x}'],
    'double quote' => ['say "hi"'],
    'newline' => ["two\nlines"],
    'close tag' => ['before ?> after'],
]);

it('refuses a sample it does not recognize', function ()
{
    $without = preg_replace("/^define\\( 'DB_PASSWORD'.*\\R/m", '', sample());
    ConfigFile::render($without, values(), ConfigFile::salts());
})->throws(UnexpectedValueException::class, 'DB_PASSWORD');

it('refuses a table prefix that is not letters, digits and underscores', function ()
{
    ConfigFile::render(sample(), values(['table-prefix' => "wp'; drop"]), ConfigFile::salts());
})->throws(InvalidArgumentException::class, 'table prefix');

it('reads the host forms WordPress accepts', function (string $host, array $expected)
{
    expect(ConfigFile::parseHost($host))->toBe($expected);
})->with([
    'name' => ['localhost', ['host' => 'localhost', 'port' => 3306, 'socket' => null]],
    'name and port' => ['db.example:3307', ['host' => 'db.example', 'port' => 3307, 'socket' => null]],
    'name and socket' => ['localhost:/tmp/mysql.sock', ['host' => 'localhost', 'port' => 3306, 'socket' => '/tmp/mysql.sock']],
    'socket only' => [':/var/run/mysqld/mysqld.sock', ['host' => 'localhost', 'port' => 3306, 'socket' => '/var/run/mysqld/mysqld.sock']],
    'ipv6 and port' => ['[::1]:3307', ['host' => '::1', 'port' => 3307, 'socket' => null]],
]);

it('reads back what it wrote, with the charset', function ()
{
    $read = readBack(ConfigFile::render(sample(), values(['db-host' => '127.0.0.1:3307', 'table-prefix' => 'site_']), ConfigFile::salts()));
    expect($read)->toBe([
        'host' => '127.0.0.1',
        'port' => 3307,
        'socket' => null,
        'database' => 'blog',
        'user' => 'blogger',
        'password' => 'hunter2',
        'tablePrefix' => 'site_',
        'charset' => 'utf8mb4',
    ]);
});
