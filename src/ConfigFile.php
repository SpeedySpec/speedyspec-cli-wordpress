<?php

declare(strict_types=1);

namespace SpeedySpec\CliWordPress;

/**
 * Writes and reads `wp-config.php` without running WordPress.
 *
 * The file is made from the release's own `wp-config-sample.php`, line by line, the way WordPress's web installer
 * (`wp-admin/setup-config.php`) makes it, so the result is what a person would get from the installer. Reading it back
 * runs only the file itself, with `ABSPATH` pointing at an empty `wp-settings.php`.
 */
final class ConfigFile
{
    /**
     * The constants the sample defines with a placeholder, and the install option each takes its value from.
     */
    private const DATABASE = [
        'DB_NAME' => 'db-name',
        'DB_USER' => 'db-user',
        'DB_PASSWORD' => 'db-password',
        'DB_HOST' => 'db-host',
    ];

    private const SALTS = [
        'AUTH_KEY',
        'SECURE_AUTH_KEY',
        'LOGGED_IN_KEY',
        'NONCE_KEY',
        'AUTH_SALT',
        'SECURE_AUTH_SALT',
        'LOGGED_IN_SALT',
        'NONCE_SALT',
    ];

    /**
     * Makes eight fresh secret keys and salts.
     *
     * Each is 64 characters from the set WordPress's own generator uses, which holds no quote or backslash.
     *
     * @return list<string>
     *   The keys and salts, in the order the sample defines them.
     */
    public static function salts(): array
    {
        $characters = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()-_ []{}<>~`+=,.;:/?|';
        $last = strlen($characters) - 1;
        $salts = [];
        foreach (self::SALTS as $_)
        {
            $salt = '';
            for ($i = 0; $i < 64; $i++)
            {
                $salt .= $characters[random_int(0, $last)];
            }
            $salts[] = $salt;
        }

        return $salts;
    }

    /**
     * Makes the contents of `wp-config.php` from the sample.
     *
     * A sample that lacks one of the lines this expects is refused rather than written half-filled, so a change to
     * WordPress's sample fails loudly. Messages never include the values.
     *
     * @param array<string, string> $values
     *   The install options: `db-name`, `db-user`, `db-password`, `db-host` and `table-prefix`.
     * @param list<string> $salts
     *   Eight keys and salts, from {@link ConfigFile::salts()}.
     */
    public static function render(string $sample, array $values, array $salts): string
    {
        $prefix = $values['table-prefix'] ?? 'wp_';
        if (preg_match('/^[A-Za-z0-9_]+$/', $prefix) !== 1)
        {
            throw new \InvalidArgumentException('the table prefix must be letters, digits and underscores');
        }
        $done = [];
        $salt = 0;
        $lines = preg_split('/(?<=\n)/', $sample) ?: [];
        foreach ($lines as $index => $line)
        {
            $ending = str_ends_with($line, "\r\n") ? "\r\n" : (str_ends_with($line, "\n") ? "\n" : '');
            if (preg_match('/^define\(\s*\'([A-Z_]+)\',(\s+)/', $line, $match) === 1)
            {
                [, $constant, $padding] = $match;
                if (isset(self::DATABASE[$constant]) && !isset($done[$constant]))
                {
                    $value = (string) ($values[self::DATABASE[$constant]] ?? '');
                    $lines[$index] = "define( '{$constant}'," . $padding . "'" . addcslashes($value, "\\'") . "' );" . $ending;
                    $done[$constant] = true;
                }
                elseif (in_array($constant, self::SALTS, true) && !isset($done[$constant]))
                {
                    $lines[$index] = "define( '{$constant}'," . $padding . "'" . $salts[$salt++] . "' );" . $ending;
                    $done[$constant] = true;
                }
            }
            elseif (str_starts_with($line, '$table_prefix =') && !isset($done['$table_prefix']))
            {
                $lines[$index] = "\$table_prefix = '" . $prefix . "';" . $ending;
                $done['$table_prefix'] = true;
            }
        }
        foreach ([...array_keys(self::DATABASE), ...self::SALTS, '$table_prefix'] as $expected)
        {
            if (!isset($done[$expected]))
            {
                throw new \UnexpectedValueException(sprintf('wp-config-sample.php has no %s line to fill in', $expected));
            }
        }
        if (str_contains(implode('', $lines), "'database_name_here'") || str_contains(implode('', $lines), "'password_here'"))
        {
            throw new \UnexpectedValueException('wp-config-sample.php still holds a placeholder after filling it in');
        }

        return implode('', $lines);
    }

    /**
     * Splits `DB_HOST` the way WordPress's database class does: a host, then a port or a socket after a colon.
     *
     * @return array{host: string, port: int, socket: ?string}
     *   The host (`localhost` for a socket alone), the port (3306 unless given) and the socket path, if any.
     */
    public static function parseHost(string $value): array
    {
        $host = $value;
        $port = 3306;
        $socket = null;
        if (preg_match('/^\[([^\]]+)\](?::(\d+))?$/', $value, $match) === 1)
        {
            $host = $match[1];
            $port = isset($match[2]) ? (int) $match[2] : 3306;
        }
        elseif (($colon = strpos($value, ':')) !== false)
        {
            $host = substr($value, 0, $colon);
            $rest = substr($value, $colon + 1);
            if (ctype_digit($rest))
            {
                $port = (int) $rest;
            }
            elseif ($rest !== '')
            {
                $socket = $rest;
            }
        }

        return ['host' => $host === '' ? 'localhost' : $host, 'port' => $port, 'socket' => $socket];
    }
}
