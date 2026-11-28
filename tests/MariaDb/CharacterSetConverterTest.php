<?php

declare(strict_types=1);

namespace YooperGEDView\MariaDbTests;

use InvalidArgumentException;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use YooperGEDView\Database\ConnectionFactory;
use YooperGEDView\Upgrade\CharacterSetConverter;
use YooperGEDView\Upgrade\LegacySiteRepository;
use YooperGEDView\Upgrade\UpgradeFromPgv;

/**
 * Converts made-up tables, shaped like the ones an old site has, on a real MariaDB server. It needs an empty
 * database it may do anything in, named by DB_HOST, DB_PORT, DB_NAME, DB_USER and DB_PASSWORD, and fails
 * without one. Every table it makes starts with "cst_".
 */
#[CoversClass(CharacterSetConverter::class)]
#[CoversClass(UpgradeFromPgv::class)]
final class CharacterSetConverterTest extends TestCase
{
    private const string PREFIX = 'cst_';

    /** "Björk" as UTF-8, written out in bytes so that this file's own encoding plays no part. */
    private const string BJORK = "Bj\xC3\xB6rk";

    private PDO $database;

    /** A connection as the old site had it: UTF-8 text sent over a connection that says it is latin1. */
    private PDO $oldSite;

    protected function setUp(): void
    {
        $settings = array_filter(getenv(), static fn(string $name): bool => str_starts_with($name, 'DB_'), ARRAY_FILTER_USE_KEY);
        if (($settings['DB_NAME'] ?? '') === '') {
            self::fail('These tests need a MariaDB database: set DB_HOST, DB_PORT, DB_NAME, DB_USER and DB_PASSWORD. See tests/README.md.');
        }
        $settings['DB_TYPE'] = 'mysql';
        $this->database = (new ConnectionFactory())->fromEnvironment($settings);
        $this->oldSite = (new ConnectionFactory())->fromEnvironment($settings);
        $this->oldSite->exec('SET NAMES latin1');
        $this->dropTables();
        $this->database->exec('ALTER DATABASE CHARACTER SET latin1 COLLATE latin1_swedish_ci');
    }

    protected function tearDown(): void
    {
        $this->dropTables();
    }

    public function testOnlyThisSitesTablesThatAreNotYetUtf8mb4AreInThePlan(): void
    {
        $this->database->exec('CREATE TABLE cst_old (name VARCHAR(40) NOT NULL) ENGINE=MyISAM DEFAULT CHARSET=latin1');
        $this->database->exec('CREATE TABLE cst_done (name VARCHAR(40) NOT NULL) DEFAULT CHARSET=utf8mb4');
        $this->database->exec('CREATE TABLE other_cst_site (name VARCHAR(40) NOT NULL) DEFAULT CHARSET=latin1');
        try {
            self::assertSame(
                [['table' => 'cst_old', 'collation' => 'latin1_swedish_ci', 'target' => 'utf8mb4_swedish_ci', 'relabel' => [], 'mixed' => [], 'nonAscii' => 0]],
                $this->converter()->plan(),
            );
        } finally {
            $this->database->exec('DROP TABLE other_cst_site');
        }
    }

    public function testUtf8StoredUnderTheLatin1LabelIsKeptByteForByteAndReadsRightAfterwards(): void
    {
        $this->database->exec("CREATE TABLE cst_people (id INT NOT NULL PRIMARY KEY, name VARCHAR(40) NOT NULL DEFAULT '', record LONGTEXT NULL, KEY (name)) ENGINE=MyISAM DEFAULT CHARSET=latin1");
        $insert = $this->oldSite->prepare('INSERT INTO cst_people VALUES (?, ?, ?)');
        $insert->execute([1, self::BJORK, '0 @I1@ INDI' . "\n" . '1 NAME Anna /' . self::BJORK . '/']);
        $insert->execute([2, 'Lake', null]);
        self::assertNotSame(self::BJORK, $this->value('SELECT name FROM cst_people WHERE id = 1'), 'read as it is labelled, the name comes out wrong');

        $plan = $this->converter()->plan();
        self::assertSame(['name', 'record'], $plan[0]['relabel']);
        self::assertSame(2, $plan[0]['nonAscii']);
        $this->converter()->convert($plan[0]);

        self::assertSame(self::BJORK, $this->value('SELECT name FROM cst_people WHERE id = 1'));
        self::assertSame(strtoupper(bin2hex(self::BJORK)), $this->value('SELECT HEX(name) FROM cst_people WHERE id = 1'));
        self::assertStringEndsWith('/' . self::BJORK . '/', (string) $this->value('SELECT record FROM cst_people WHERE id = 1'));
        self::assertNull($this->value('SELECT record FROM cst_people WHERE id = 2'));
        self::assertSame([], $this->converter()->plan());
        self::assertSame(
            [['name', 'varchar(40)', 'NO', "''", 'utf8mb4_swedish_ci'], ['record', 'longtext', 'YES', 'NULL', 'utf8mb4_swedish_ci']],
            $this->columns('cst_people'),
        );
    }

    public function testRealLatin1TextIsConvertedInTheOrdinaryWay(): void
    {
        $this->database->exec('CREATE TABLE cst_places (place VARCHAR(40) NOT NULL) ENGINE=MyISAM DEFAULT CHARSET=latin1');
        // Sent as UTF-8 over a UTF-8 connection: the server stores the one latin1 byte for "ö".
        $this->database->prepare('INSERT INTO cst_places VALUES (?)')->execute([self::BJORK]);
        self::assertSame('426AF6726B', $this->value('SELECT HEX(place) FROM cst_places'));

        $plan = $this->converter()->plan();
        self::assertSame([], $plan[0]['relabel']);
        self::assertSame([], $plan[0]['mixed']);
        self::assertSame(1, $plan[0]['nonAscii']);
        $this->converter()->convert($plan[0]);

        self::assertSame(self::BJORK, $this->value('SELECT place FROM cst_places'));
    }

    public function testAColumnWithBothKindsIsReportedAndItsTableIsNotTouched(): void
    {
        $this->database->exec('CREATE TABLE cst_mixed (place VARCHAR(40) NOT NULL) ENGINE=MyISAM DEFAULT CHARSET=latin1');
        $this->database->prepare('INSERT INTO cst_mixed VALUES (?)')->execute([self::BJORK]);
        $this->oldSite->prepare('INSERT INTO cst_mixed VALUES (?)')->execute([self::BJORK]);

        $plan = $this->converter()->plan();
        self::assertSame(['place'], $plan[0]['mixed']);
        self::assertSame([], $plan[0]['relabel']);

        $report = implode("\n", $this->upgrade()->run(true));
        self::assertStringContainsString('! cst_mixed is left alone: place holds UTF-8 in some rows and another character set in others.', $report);
        self::assertStringContainsString('0 of 1 have been converted', $report);
        self::assertSame('latin1_swedish_ci', $this->columns('cst_mixed')[0][4]);

        $this->expectException(InvalidArgumentException::class);
        $this->converter()->convert($plan[0]);
    }

    public function testLettersThatWereDifferentStayDifferentSoAUniqueIndexStillHolds(): void
    {
        $this->database->exec('CREATE TABLE cst_names (name VARCHAR(40) NOT NULL, UNIQUE KEY (name)) ENGINE=MyISAM DEFAULT CHARSET=latin1');
        // "a" and "å" are two letters under Swedish rules, and one under most others.
        $insert = $this->database->prepare('INSERT INTO cst_names VALUES (?)');
        $insert->execute(['Ha']);
        $insert->execute(["H\xC3\xA5"]);

        $this->converter()->convert($this->converter()->plan()[0]);

        self::assertSame('2', $this->value('SELECT COUNT(*) FROM cst_names'));
        self::assertSame('utf8mb4_swedish_ci', $this->columns('cst_names')[0][4]);
    }

    public function testATableTheServerRefusesToConvertIsPutBackAsItWas(): void
    {
        $this->database->exec('CREATE TABLE cst_refused (name VARCHAR(40) NOT NULL, UNIQUE KEY (name)) ENGINE=MyISAM DEFAULT CHARSET=latin1');
        // As mislabelled bytes these are two values. Read as the UTF-8 they are, they are "é" and "É": one value.
        $insert = $this->oldSite->prepare('INSERT INTO cst_refused VALUES (?)');
        $insert->execute(["\xC3\xA9"]);
        $insert->execute(["\xC3\x89"]);
        $before = $this->columns('cst_refused');

        $plan = $this->converter()->plan();
        self::assertSame(['name'], $plan[0]['relabel']);
        try {
            $this->converter()->convert($plan[0]);
            self::fail('The server should have refused: the two values are the same under the new rules.');
        } catch (PDOException) {
            self::assertSame($before, $this->columns('cst_refused'));
            self::assertSame('C389,C3A9', $this->value('SELECT GROUP_CONCAT(HEX(name) ORDER BY 1) FROM cst_refused'));
        }

        $report = implode("\n", $this->upgrade()->run(true));
        self::assertStringContainsString('! cst_refused could not be converted and is as it was: ', $report);
        self::assertSame($before, $this->columns('cst_refused'));
    }

    public function testTheUpgradeSaysWhatItWouldDoThenDoesItAndTheFailingSearchWorks(): void
    {
        $this->database->exec('CREATE TABLE cst_people (name VARCHAR(40) NOT NULL) ENGINE=MyISAM DEFAULT CHARSET=latin1');
        $this->database->exec('CREATE TABLE cst_notes (note TEXT NULL) ENGINE=MyISAM DEFAULT CHARSET=latin1');
        $this->oldSite->prepare('INSERT INTO cst_people VALUES (?)')->execute([self::BJORK]);
        // What the lists by surname letter do: look for a letter that latin1 does not have.
        $search = "SELECT COUNT(*) FROM cst_people WHERE name LIKE '\xC4\x80%' OR name LIKE 'B%'";
        try {
            $this->database->query($search);
            self::fail('The search should fail while the table is latin1.');
        } catch (PDOException $exception) {
            self::assertStringContainsString('Illegal mix of collations', $exception->getMessage());
        }

        $report = implode("\n", $this->upgrade()->run(false));
        self::assertStringContainsString("Text in the database\n  2 tables hold text in a character set other than utf8mb4, the one this program uses (latin1_swedish_ci).", $report);
        self::assertStringContainsString('They would be converted.', $report);
        self::assertStringContainsString('  1 value has letters outside plain ASCII', $report);
        self::assertStringContainsString('  In 1 column those letters are UTF-8 that the old site stored under the wrong label.', $report);
        self::assertStringContainsString('New tables in this database get latin1_swedish_ci. That would be changed to utf8mb4 as well.', $report);
        self::assertStringContainsString('Nothing has been changed.', $report);
        self::assertCount(2, $this->converter()->plan());
        self::assertSame('latin1_swedish_ci', $this->converter()->databaseCollation());

        $report = implode("\n", $this->upgrade()->run(true));
        self::assertStringContainsString('  2 of 2 have been converted to utf8mb4_swedish_ci.', $report);
        self::assertStringContainsString('New tables in this database now get utf8mb4 as well.', $report);
        self::assertStringContainsString('The changes above have been made.', $report);
        self::assertSame([], $this->converter()->plan());
        self::assertSame('utf8mb4_swedish_ci', $this->converter()->databaseCollation());
        self::assertSame('1', $this->value($search));

        self::assertStringNotContainsString('Text in the database', implode("\n", $this->upgrade()->run(false)));
    }

    private function converter(): CharacterSetConverter
    {
        return new CharacterSetConverter($this->database, self::PREFIX);
    }

    /**
     * The upgrade on a site that has, besides the tables a test made, the few it looks at for other reasons.
     * Those are utf8mb4 already, so they play no part in the conversion.
     */
    private function upgrade(): UpgradeFromPgv
    {
        $this->database->exec('CREATE TABLE IF NOT EXISTS cst_site_setting (site_setting_name VARCHAR(32) NOT NULL PRIMARY KEY, site_setting_value VARCHAR(255) NOT NULL) DEFAULT CHARSET=utf8mb4');
        $this->database->exec("REPLACE INTO cst_site_setting VALUES ('PGV_SCHEMA_VERSION', '14')");
        $this->database->exec('CREATE TABLE IF NOT EXISTS cst_user (user_id INT NOT NULL PRIMARY KEY, user_name VARCHAR(32) NOT NULL, password VARCHAR(255) NOT NULL) DEFAULT CHARSET=utf8mb4');
        $this->database->exec('CREATE TABLE IF NOT EXISTS cst_user_setting (user_id INT NOT NULL, setting_name VARCHAR(32) NOT NULL, setting_value VARCHAR(255) NOT NULL) DEFAULT CHARSET=utf8mb4');
        $this->database->exec('CREATE TABLE IF NOT EXISTS cst_remotelinks (r_gid VARCHAR(20)) DEFAULT CHARSET=utf8mb4');
        return new UpgradeFromPgv(new LegacySiteRepository($this->database, self::PREFIX), sys_get_temp_dir() . '/no-such-index-folder', characterSets: $this->converter());
    }

    private function value(string $sql): ?string
    {
        $statement = $this->database->query($sql);
        $value = $statement === false ? null : $statement->fetchColumn();

        return is_scalar($value) ? (string) $value : null;
    }

    /**
     * @return list<list<string>> name, type, nullable, default and collation of each text column
     */
    private function columns(string $table): array
    {
        $statement = $this->database->prepare(
            'SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, COLLATION_NAME FROM information_schema.COLUMNS'
            . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLLATION_NAME IS NOT NULL ORDER BY ORDINAL_POSITION',
        );
        $statement->execute([$table]);
        $columns = [];
        while (is_array($row = $statement->fetch(PDO::FETCH_NUM))) {
            $columns[] = array_map(static fn(mixed $value): string => is_scalar($value) ? (string) $value : 'NULL', $row);
        }

        return $columns;
    }

    private function dropTables(): void
    {
        $statement = $this->database->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'cst\\_%'");
        foreach ($statement === false ? [] : $statement->fetchAll(PDO::FETCH_COLUMN) as $table) {
            if (is_string($table) && preg_match('/^cst_\w+$/', $table) === 1) {
                $this->database->exec('DROP TABLE `' . $table . '`');
            }
        }
    }
}
