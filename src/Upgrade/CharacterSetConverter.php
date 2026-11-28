<?php

declare(strict_types=1);

namespace YooperGEDView\Upgrade;

use InvalidArgumentException;
use PDO;
use PDOException;

/**
 * Brings the tables of a MySQL or MariaDB site to utf8mb4, the character set this program talks to the
 * database in. PhpGedView's tables are usually latin1, and what is in them depends on how the old site
 * connected:
 *
 * - Most sent UTF-8 text over a latin1 connection, so each column holds UTF-8 bytes that the database takes for
 *   latin1 characters. Read back over a utf8mb4 connection, "å" comes out as "Ã¥". Such a column is relabelled:
 *   its bytes are kept and declared to be the UTF-8 they are.
 * - Some connected in UTF-8, and the database turned the text into real latin1 on the way in. Such a column is
 *   converted in the ordinary way.
 *
 * Which it is, is told from the data: text that reads as valid UTF-8 byte for byte is the first kind. A column
 * with both kinds in it cannot be put right by rule, and its table is left alone and reported.
 *
 * Until a table is utf8mb4, comparing its text with a letter outside latin1 fails in the database, which is what
 * the lists by surname letter do.
 */
final readonly class CharacterSetConverter
{
    /** The server's error number for "Specified key was too long". */
    private const int KEY_TOO_LONG = 1071;

    private const array BINARY_TYPES = ['tinytext' => 'tinyblob', 'text' => 'blob', 'mediumtext' => 'mediumblob', 'longtext' => 'longblob'];

    /**
     * @param string $prefix the site's table prefix, from its settings; never from a request
     */
    public function __construct(private PDO $database, private string $prefix)
    {
        if (preg_match('/^[A-Za-z0-9_]*$/', $prefix) !== 1) {
            throw new InvalidArgumentException('A table prefix may hold only letters, digits and underscores.');
        }
    }

    /**
     * False for databases that have no character sets per table to bring into line (SQLite, PostgreSQL).
     */
    public function applies(): bool
    {
        return $this->database->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    }

    /**
     * The site's tables that are not utf8mb4, with what would be done to each.
     *
     * @return list<array{table: string, collation: string, target: string, relabel: list<string>, mixed: list<string>, nonAscii: int}>
     */
    public function plan(): array
    {
        $plan = [];
        foreach ($this->rows(
            'SELECT TABLE_NAME, TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = ? ORDER BY TABLE_NAME',
            ['BASE TABLE'],
        ) as [$table, $collation]) {
            if (!str_starts_with($table, $this->prefix) || preg_match('/^\w+$/', $table) !== 1 || str_starts_with($collation, 'utf8mb4')) {
                continue;
            }
            $entry = ['table' => $table, 'collation' => $collation, 'target' => $this->targetCollation($collation), 'relabel' => [], 'mixed' => [], 'nonAscii' => 0];
            foreach ($this->textColumns($table) as $column) {
                if ($column['charset'] !== 'latin1') {
                    continue;
                }
                $name = $column['name'];
                [$nonAscii, $utf8] = $this->rows(
                    'SELECT COALESCE(SUM(BINARY `' . $name . '` <> BINARY CONVERT(`' . $name . '` USING ascii)), 0),'
                    . ' COALESCE(SUM(BINARY `' . $name . '` <> BINARY CONVERT(`' . $name . '` USING ascii)'
                    . ' AND CAST(CONVERT(CAST(`' . $name . '` AS BINARY) USING utf8mb4) AS BINARY) = CAST(`' . $name . '` AS BINARY)), 0)'
                    . ' FROM `' . $table . '`',
                    [],
                )[0];
                $entry['nonAscii'] += (int) $nonAscii;
                if ((int) $utf8 > 0 && (int) $utf8 === (int) $nonAscii) {
                    $entry['relabel'][] = $name;
                } elseif ((int) $utf8 > 0) {
                    $entry['mixed'][] = $name;
                }
            }
            $plan[] = $entry;
        }

        return $plan;
    }

    /**
     * Converts one table of the plan. A table with a mixed column must not be passed.
     *
     * @param array{table: string, collation: string, target: string, relabel: list<string>, mixed: list<string>, nonAscii: int} $entry
     *
     * @return bool true when the table also had to be moved to the InnoDB storage engine: a letter takes up to
     *              four bytes in utf8mb4, and a unique index on a long column no longer fits in what the older
     *              MyISAM engine allows an index (1000 bytes)
     *
     * @throws PDOException when the database refuses, for example because two values become equal under the
     *                      new comparison rules and a unique index forbids that. The table is then as it was.
     */
    public function convert(array $entry): bool
    {
        if ($entry['mixed'] !== []) {
            throw new InvalidArgumentException('Table ' . $entry['table'] . ' has text of both kinds in one column.');
        }
        try {
            $this->convertInPlace($entry);

            return false;
        } catch (PDOException $exception) {
            $engine = $this->rows('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$entry['table']])[0][0] ?? '';
            if (($exception->errorInfo[1] ?? null) !== self::KEY_TOO_LONG || preg_match('/^\w+$/', $engine) !== 1 || strcasecmp($engine, 'InnoDB') === 0) {
                throw $exception;
            }
        }
        $this->database->exec('ALTER TABLE `' . $entry['table'] . '` ENGINE=InnoDB');
        try {
            $this->convertInPlace($entry);
        } catch (PDOException $exception) {
            $this->database->exec('ALTER TABLE `' . $entry['table'] . '` ENGINE=' . $engine);

            throw $exception;
        }

        return true;
    }

    /**
     * @param array{table: string, collation: string, target: string, relabel: list<string>, mixed: list<string>, nonAscii: int} $entry
     */
    private function convertInPlace(array $entry): void
    {
        $table = $entry['table'];
        if ($entry['relabel'] !== []) {
            $toBinary = [];
            $toText = [];
            $back = [];
            foreach ($this->textColumns($table) as $column) {
                if (!in_array($column['name'], $entry['relabel'], true)) {
                    continue;
                }
                $null = $column['nullable'] ? ' NULL' : ' NOT NULL';
                $default = $column['default'] === null ? '' : ' DEFAULT ' . $column['default'];
                $toBinary[] = 'MODIFY `' . $column['name'] . '` ' . $this->binaryType($column['type']) . $null;
                $toText[] = 'MODIFY `' . $column['name'] . '` ' . $column['type'] . ' CHARACTER SET utf8mb4 COLLATE ' . $entry['target'] . $null
                    . $default;
                $back[] = 'MODIFY `' . $column['name'] . '` ' . $column['type'] . ' CHARACTER SET ' . $column['charset'] . ' COLLATE ' . $column['collation'] . $null
                    . $default;
            }
            // The bytes are kept: first declared to be no text at all, then to be the UTF-8 they are.
            $this->database->exec('ALTER TABLE `' . $table . '` ' . implode(', ', $toBinary));
            try {
                $this->database->exec('ALTER TABLE `' . $table . '` ' . implode(', ', $toText));
            } catch (PDOException $exception) {
                // Refused, so the table goes back to what it was, not left with columns that are no longer text.
                $this->database->exec('ALTER TABLE `' . $table . '` ' . implode(', ', $back));

                throw $exception;
            }
        }
        // Everything else in the table is converted in the ordinary way; columns already utf8mb4 are untouched.
        $this->database->exec('ALTER TABLE `' . $table . '` CONVERT TO CHARACTER SET utf8mb4 COLLATE ' . $entry['target']);
    }

    /**
     * The comparison rules that new tables in this database get, such as "latin1_swedish_ci".
     */
    public function databaseCollation(): string
    {
        return $this->rows('SELECT DEFAULT_COLLATION_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = DATABASE()', [])[0][0] ?? '';
    }

    /**
     * The comparison rules that new tables should get, so that the ones this program adds later match the
     * converted ones: text under two sets of rules cannot always be compared.
     *
     * @param list<array{table: string, collation: string, target: string, relabel: list<string>, mixed: list<string>, nonAscii: int}> $plan
     *
     * @return ?string null when the database's default is right as it is
     */
    public function databaseTarget(array $plan): ?string
    {
        $current = $this->databaseCollation();
        $target = $plan !== [] ? $plan[0]['target'] : (str_starts_with($current, 'utf8mb4') ? $current : $this->targetCollation($current));

        return $current === '' || $current === $target ? null : $target;
    }

    /**
     * @param string $collation from databaseTarget()
     *
     * @throws PDOException when the database refuses, for example because the account may not alter the database
     */
    public function convertDatabase(string $collation): void
    {
        if (preg_match('/^utf8mb4_\w+$/', $collation) !== 1) {
            throw new InvalidArgumentException('Not a utf8mb4 collation: ' . $collation);
        }
        $this->database->exec('ALTER DATABASE CHARACTER SET utf8mb4 COLLATE ' . $collation);
    }

    /**
     * The comparison rules closest to the old ones: Swedish rules stay Swedish, so that "å" and "a" stay apart
     * where they were apart, and nothing becomes a duplicate that was not one.
     */
    private function targetCollation(string $collation): string
    {
        return match (true) {
            str_ends_with($collation, '_swedish_ci') => 'utf8mb4_swedish_ci',
            str_ends_with($collation, '_bin') => 'utf8mb4_bin',
            str_ends_with($collation, '_general_ci') => 'utf8mb4_general_ci',
            default => 'utf8mb4_unicode_ci',
        };
    }

    /**
     * @return list<array{name: string, type: string, charset: string, collation: string, nullable: bool, default: ?string}>
     */
    private function textColumns(string $table): array
    {
        $columns = [];
        $statement = $this->database->prepare(
            'SELECT COLUMN_NAME, COLUMN_TYPE, CHARACTER_SET_NAME, IS_NULLABLE, COLUMN_DEFAULT, DATA_TYPE, COLLATION_NAME FROM information_schema.COLUMNS'
            . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CHARACTER_SET_NAME IS NOT NULL ORDER BY ORDINAL_POSITION',
        );
        $statement->execute([$table]);
        while (is_array($row = $statement->fetch(PDO::FETCH_NUM))) {
            [$name, $type, $charset, $nullable, $default, $dataType, $collation] = array_map(static fn(mixed $value): ?string => is_scalar($value) ? (string) $value : null, $row);
            // Lists of fixed values (enum, set) hold names the program wrote itself: converted, never relabelled.
            if ($name === null || $type === null || preg_match('/^\w+$/', $name) !== 1 || preg_match('/^\w+$/', (string) $charset . $collation) !== 1 || !in_array($dataType, ['char', 'varchar', 'tinytext', 'text', 'mediumtext', 'longtext'], true)) {
                continue;
            }
            $columns[] = ['name' => $name, 'type' => $type, 'charset' => (string) $charset, 'collation' => (string) $collation, 'nullable' => $nullable === 'YES', 'default' => $default];
        }

        return $columns;
    }

    /**
     * The type that holds the same bytes with no character set: varchar(20) becomes varbinary(20), text becomes blob.
     */
    private function binaryType(string $type): string
    {
        if (preg_match('/^(?:var)?char\((\d+)\)$/', $type, $match) === 1) {
            return 'varbinary(' . $match[1] . ')';
        }

        return self::BINARY_TYPES[$type] ?? throw new InvalidArgumentException('No binary form is known for the type ' . $type . '.');
    }

    /**
     * @param list<string> $values
     *
     * @return list<list<string>>
     */
    private function rows(string $sql, array $values): array
    {
        $statement = $this->database->prepare($sql);
        $statement->execute($values);
        $rows = [];
        while (is_array($row = $statement->fetch(PDO::FETCH_NUM))) {
            $rows[] = array_map(static fn(mixed $value): string => is_scalar($value) ? (string) $value : '', $row);
        }

        return $rows;
    }
}
