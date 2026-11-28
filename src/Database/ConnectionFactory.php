<?php

declare(strict_types=1);

namespace YooperGEDView\Database;

use InvalidArgumentException;
use PDO;

/**
 * Opens the site's database from the settings in the environment, the same ones the container image uses:
 * DB_TYPE (mysql, pgsql or sqlite; mysql when not set), DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASSWORD.
 * For sqlite, DB_NAME is the path of the database file.
 */
final class ConnectionFactory
{
    /**
     * @param array<string, string> $environment
     */
    public function fromEnvironment(array $environment): PDO
    {
        $type = $environment['DB_TYPE'] ?? 'mysql';
        $name = $environment['DB_NAME'] ?? '';
        if ($name === '') {
            throw new InvalidArgumentException('DB_NAME is not set.');
        }
        if ($type === 'sqlite') {
            if (!is_file($name)) {
                throw new InvalidArgumentException('There is no SQLite database at ' . $name . '.');
            }

            return $this->open('sqlite:' . $name, null, null);
        }
        if ($type !== 'mysql' && $type !== 'pgsql') {
            throw new InvalidArgumentException('DB_TYPE must be mysql, pgsql or sqlite.');
        }
        $host = $environment['DB_HOST'] ?? '';
        if ($host === '') {
            throw new InvalidArgumentException('DB_HOST is not set.');
        }
        $port = $environment['DB_PORT'] ?? '';
        if ($port !== '' && !ctype_digit($port)) {
            throw new InvalidArgumentException('DB_PORT must be a number.');
        }
        foreach ([$host, $name] as $part) {
            if (str_contains($part, ';')) {
                throw new InvalidArgumentException('DB_HOST and DB_NAME may not hold a semicolon.');
            }
        }
        $dsn = $type . ':host=' . $host . ';dbname=' . $name . ($port === '' ? '' : ';port=' . $port);
        if ($type === 'mysql') {
            // Said outright, so that text goes to and from the database as UTF-8 whatever the server's default is.
            $dsn .= ';charset=utf8mb4';
        }

        return $this->open($dsn, $environment['DB_USER'] ?? '', $environment['DB_PASSWORD'] ?? '');
    }

    private function open(string $dsn, ?string $user, ?string $password): PDO
    {
        return new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }
}
