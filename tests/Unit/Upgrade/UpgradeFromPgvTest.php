<?php

declare(strict_types=1);

namespace YooperGEDView\Tests\Upgrade;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use YooperGEDView\Console\Yooper;
use YooperGEDView\Security\PasswordHasher;
use YooperGEDView\Upgrade\LegacySiteRepository;
use YooperGEDView\Upgrade\UpgradeFromPgv;

/**
 * Runs the upgrade, through bin/yooper's own code, on a made-up site shaped like one PhpGedView 4.3.0 left.
 */
#[CoversClass(UpgradeFromPgv::class)]
#[CoversClass(LegacySiteRepository::class)]
final class UpgradeFromPgvTest extends TestCase
{
    private const string OLD_HASH = '$1$padpadpa$Ht1vVEOcZKBkLBvHlBNuE1';

    private const string CHANGES = <<<'PHP'
        <?php
        $pgv_changes = array();
        $pgv_changes["I2_sample.ged"] = array();
        // Start of change record.
        $change = array();
        $change["gid"] = 'I2';
        $change["gedcom"] = 'sample.ged';
        $change["type"] = 'replace';
        $change["status"] = 'submitted';
        $change["user"] = 'anna';
        $change["time"] = '1700000000';
        $change["undo"] = '0 @I2@ INDI
        1 NAME Aino /Kivela/';
        // End of change record.
        $pgv_changes["I2_sample.ged"][] = $change;
        ?>
        PHP;

    private const string SETTINGS = "<?php\nif (!defined('PGV_PHPGEDVIEW')) {\n\theader('HTTP/1.0 403 Forbidden');\n\texit;\n}\n\$LANGUAGE = \"english\";\n\$PEDIGREE_GENERATIONS = 4;\n";

    private string $folder;

    private PDO $database;

    private string $newHash;

    protected function setUp(): void
    {
        $folder = sys_get_temp_dir() . '/yooper-upgrade-' . bin2hex(random_bytes(6));
        mkdir($folder . '/index', 0o700, true);
        $this->folder = $folder;
        $this->newHash = (new PasswordHasher())->hash('a password set after the move');

        $this->database = new PDO('sqlite:' . $folder . '/site.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->database->exec('CREATE TABLE pgv_site_setting (site_setting_name VARCHAR(32) NOT NULL PRIMARY KEY, site_setting_value VARCHAR(255) NOT NULL)');
        $this->database->exec("INSERT INTO pgv_site_setting VALUES ('PGV_SCHEMA_VERSION', '14')");
        $this->database->exec('CREATE TABLE pgv_user (user_id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, user_name VARCHAR(32) NOT NULL, password VARCHAR(255) NOT NULL)');
        $this->database->exec('CREATE TABLE pgv_user_setting (user_id INTEGER NOT NULL, setting_name VARCHAR(32) NOT NULL, setting_value VARCHAR(255) NOT NULL, PRIMARY KEY (user_id, setting_name))');
        $this->database->exec('CREATE TABLE pgv_remotelinks (r_gid VARCHAR(20), r_linkid VARCHAR(255), r_file INTEGER)');
        $this->user('anna', self::OLD_HASH, ['canadmin' => 'Y', 'email' => 'anna@example.org', 'language' => 'english']);
        $this->user('bertil', self::OLD_HASH, ['email' => '', 'language' => 'german']);
        $this->user('cecilia', $this->newHash, ['email' => 'cecilia@example.org', 'language' => 'finnish']);

        file_put_contents($folder . '/index/pgv_changes.php', self::CHANGES);
        file_put_contents($folder . '/index/sample.ged_conf.php', self::SETTINGS);
        file_put_contents($folder . '/index/sample.ged_priv.php', self::SETTINGS);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->folder . '/index/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->folder . '/index');
        unlink($this->folder . '/site.sqlite');
        rmdir($this->folder);
    }

    /**
     * @param array<string, string> $settings
     */
    private function user(string $name, string $hash, array $settings): void
    {
        $this->database->prepare('INSERT INTO pgv_user (user_name, password) VALUES (?, ?)')->execute([$name, $hash]);
        $id = $this->database->lastInsertId();
        foreach ($settings as $setting => $value) {
            $this->database->prepare('INSERT INTO pgv_user_setting VALUES (?, ?, ?)')->execute([$id, $setting, $value]);
        }
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{int, string, string} exit code, output, errors
     */
    private function upgrade(array $arguments = [], bool $withIndex = true): array
    {
        $in = fopen('php://memory', 'r+');
        $out = fopen('php://memory', 'r+');
        $err = fopen('php://memory', 'r+');
        self::assertIsResource($in);
        self::assertIsResource($out);
        self::assertIsResource($err);
        $code = (new Yooper(1_700_000_000))->run(
            ['upgrade-from-pgv', ...($withIndex ? ['--index=' . $this->folder . '/index'] : []), ...$arguments],
            ['DB_TYPE' => 'sqlite', 'DB_NAME' => $this->folder . '/site.sqlite'],
            $in,
            $out,
            $err,
        );

        return [$code, (string) stream_get_contents($out, null, 0), (string) stream_get_contents($err, null, 0)];
    }

    /**
     * @return array<string, string> password by user name
     */
    private function passwords(): array
    {
        $rows = $this->database->query('SELECT user_name, password FROM pgv_user');
        self::assertNotFalse($rows);
        /** @var array<string, string> $passwords */
        $passwords = $rows->fetchAll(PDO::FETCH_KEY_PAIR);

        return $passwords;
    }

    /**
     * @return list<string>
     */
    private function languages(): array
    {
        $rows = $this->database->query("SELECT setting_value FROM pgv_user_setting WHERE setting_name = 'language' ORDER BY user_id");
        self::assertNotFalse($rows);

        return array_map(strval(...), $rows->fetchAll(PDO::FETCH_COLUMN));
    }

    public function testWithoutApplyItReportsAndChangesNothing(): void
    {
        [$code, $output, $errors] = $this->upgrade();

        self::assertSame(Yooper::OK, $code, $errors);
        self::assertStringContainsString('The tables are at version 14.', $output);
        self::assertStringContainsString('3 in all.', $output);
        self::assertStringContainsString('2 have a password stored the way PhpGedView stored it (MD5-crypt or older).', $output);
        self::assertStringContainsString('They would be removed.', $output);
        self::assertStringContainsString('anna (administrator): by the "lost password" link', $output);
        self::assertStringContainsString('bertil: no mail address: bin/yooper set-password', $output);
        self::assertStringNotContainsString('cecilia', $output);
        self::assertStringContainsString('2 users chose a language other than English', $output);
        self::assertStringContainsString('1 pending changes to 1 records would be carried over', $output);
        self::assertStringContainsString('sample.ged_conf.php: settings only.', $output);
        self::assertStringContainsString('sample.ged_priv.php: settings only.', $output);
        self::assertStringContainsString('Nothing has been changed.', $output);

        self::assertSame(['anna' => self::OLD_HASH, 'bertil' => self::OLD_HASH, 'cecilia' => $this->newHash], $this->passwords());
        self::assertSame(['english', 'german', 'finnish'], $this->languages());
        self::assertFileExists($this->folder . '/index/pgv_changes.php');
        self::assertFileDoesNotExist($this->folder . '/index/pgv_changes.json');
    }

    public function testWithApplyItRemovesOldPasswordsSetsEnglishAndCarriesPendingEditsOver(): void
    {
        [$code, $output, $errors] = $this->upgrade(['--apply']);

        self::assertSame(Yooper::OK, $code, $errors);
        self::assertStringContainsString('They have been removed.', $output);
        self::assertStringContainsString('They have been set to English.', $output);
        self::assertStringContainsString('have been carried over to pgv_changes.json', $output);
        self::assertStringContainsString('The changes above have been made.', $output);

        self::assertSame(['anna' => '*', 'bertil' => '*', 'cecilia' => $this->newHash], $this->passwords());
        self::assertSame(['english', 'english', 'english'], $this->languages());
        self::assertFileDoesNotExist($this->folder . '/index/pgv_changes.php');
        self::assertFileExists($this->folder . '/index/pgv_changes.php.carried-over');
        self::assertSame(
            ['I2_sample.ged' => [[
                'gid' => 'I2',
                'gedcom' => 'sample.ged',
                'type' => 'replace',
                'status' => 'submitted',
                'user' => 'anna',
                'time' => '1700000000',
                'undo' => "0 @I2@ INDI\n1 NAME Aino /Kivela/",
            ]]],
            json_decode((string) file_get_contents($this->folder . '/index/pgv_changes.json'), true),
        );
    }

    public function testOtherFormsOfPasswordAreToldApartAndOnlyTheWeakOnesAreRemoved(): void
    {
        $bcrypt = password_hash('set by another tool', PASSWORD_BCRYPT, ['cost' => 4]);
        $this->user('dagny', $bcrypt, ['canadmin' => 'Y', 'email' => 'dagny@example.org']);
        $this->user('edvin', 'x', ['email' => 'edvin@example.org']);
        $this->user('frida', '', ['email' => '']);
        $this->user('gustav', '5f4dcc3b5aa765d61d8327deb882cf99', ['email' => 'gustav@example.org']);

        [, $output] = $this->upgrade(['--apply']);

        self::assertStringContainsString('7 in all.', $output);
        self::assertStringContainsString('3 have a password stored the way PhpGedView stored it (MD5-crypt or older).', $output);
        self::assertStringContainsString('1 has a password stored in another form (bcrypt, for example)', $output);
        self::assertStringContainsString('They are left as they are.', $output);
        self::assertStringContainsString('2 have no password stored', $output);
        self::assertStringContainsString('dagny (administrator): by the "lost password" link', $output);
        self::assertStringContainsString('edvin (no password stored): by the "lost password" link', $output);
        self::assertStringContainsString('frida (no password stored): no mail address: bin/yooper set-password', $output);

        $passwords = $this->passwords();
        self::assertSame($bcrypt, $passwords['dagny']);
        self::assertSame('x', $passwords['edvin']);
        self::assertSame('', $passwords['frida']);
        self::assertSame('*', $passwords['gustav']);
        self::assertSame('*', $passwords['anna']);
    }

    public function testARemovedPasswordCanNeverBeSignedInWith(): void
    {
        $this->upgrade(['--apply']);

        self::assertFalse((new PasswordHasher())->verify('*', $this->passwords()['anna']));
        self::assertFalse((new PasswordHasher())->verify('', $this->passwords()['anna']));
    }

    public function testRunningItAgainChangesNothingMore(): void
    {
        $this->upgrade(['--apply']);
        $before = [$this->passwords(), $this->languages(), file_get_contents($this->folder . '/index/pgv_changes.json')];

        [$code, $output] = $this->upgrade(['--apply']);

        self::assertSame(Yooper::OK, $code);
        self::assertStringNotContainsString('stored the way PhpGedView stored it', $output);
        self::assertStringContainsString('2 have no password stored', $output);
        self::assertStringContainsString('anna (administrator) (no password stored)', $output);
        self::assertStringContainsString('No pgv_changes.php: nothing to carry over.', $output);
        self::assertSame($before, [$this->passwords(), $this->languages(), file_get_contents($this->folder . '/index/pgv_changes.json')]);
    }

    public function testPendingEditsWithCodeInThemAreNotCarriedOver(): void
    {
        $hostile = str_replace("'anna';", "'x'.print(31337*31337).'';", self::CHANGES);
        file_put_contents($this->folder . '/index/pgv_changes.php', $hostile);

        [$code, $output] = $this->upgrade(['--apply']);

        self::assertSame(Yooper::OK, $code);
        self::assertStringContainsString('! pgv_changes.php is not as PhpGedView writes it, and is NOT carried over', $output);
        self::assertStringContainsString('someone put code into the old site', $output);
        self::assertFileDoesNotExist($this->folder . '/index/pgv_changes.json');
        self::assertSame($hostile, file_get_contents($this->folder . '/index/pgv_changes.php'));
    }

    public function testPendingEditsAlreadyCarriedOverAreLeftAlone(): void
    {
        file_put_contents($this->folder . '/index/pgv_changes.json', '{"kept":[]}');

        [, $output] = $this->upgrade(['--apply']);

        self::assertStringContainsString('pgv_changes.json is already there', $output);
        self::assertSame('{"kept":[]}', file_get_contents($this->folder . '/index/pgv_changes.json'));
        self::assertFileExists($this->folder . '/index/pgv_changes.php');
    }

    public function testASettingsFileWithCodeInItIsPointedOutWithTheLine(): void
    {
        file_put_contents($this->folder . '/index/other.ged_conf.php', self::SETTINGS . "\$X = system('id');\n");

        [, $output] = $this->upgrade();

        self::assertStringContainsString('! other.ged_conf.php holds more than settings.', $output);
        self::assertStringContainsString('line 8: calls system()', $output);
        self::assertStringContainsString('sample.ged_conf.php: settings only.', $output);
    }

    public function testFilesAndLinksThatAreNoLongerUsedAreListed(): void
    {
        file_put_contents($this->folder . '/index/lang_settings.php', '<?php');
        file_put_contents($this->folder . '/index/banned.php', '<?php');
        $this->database->exec("INSERT INTO pgv_remotelinks VALUES ('I1', 'x', 1)");

        [, $output] = $this->upgrade();

        self::assertStringContainsString('These files in the index folder can be deleted: lang_settings.php, banned.php.', $output);
        self::assertStringContainsString('1 links to records on other PhpGedView sites.', $output);
    }

    public function testAnOlderOrUnknownTableLayoutIsPointedOut(): void
    {
        $this->database->exec("UPDATE pgv_site_setting SET site_setting_value = '9'");
        self::assertStringContainsString('! The tables are at version 9, older than PhpGedView 4.3.0 (14).', $this->upgrade()[1]);

        $this->database->exec('DELETE FROM pgv_site_setting');
        self::assertStringContainsString('! No table layout version is recorded.', $this->upgrade()[1]);

        $this->database->exec('DROP TABLE pgv_site_setting');
        self::assertStringContainsString('! No table layout version is recorded.', $this->upgrade()[1]);
    }

    public function testAnIndexFolderWithNothingInIt(): void
    {
        foreach (glob($this->folder . '/index/*') ?: [] as $file) {
            unlink($file);
        }

        [$code, $output] = $this->upgrade();

        self::assertSame(Yooper::OK, $code);
        self::assertStringContainsString('No pgv_changes.php: nothing to carry over.', $output);
        self::assertStringContainsString('None found in', $output);
    }

    public function testWrongUseAndThingsThatAreNotThere(): void
    {
        [$code, , $errors] = $this->upgrade([], false);
        self::assertSame(Yooper::WRONG_USE, $code);
        self::assertStringContainsString('--index=<folder>', $errors);

        [$code, , $errors] = $this->upgrade(['--force']);
        self::assertSame(Yooper::WRONG_USE, $code);
        self::assertStringContainsString('takes --index=<folder>', $errors);

        [$code, , $errors] = $this->upgrade(['--index=' . $this->folder . '/nowhere'], false);
        self::assertSame(Yooper::FAILED, $code);
        self::assertStringContainsString('There is no folder at', $errors);

        $this->database->exec('DROP TABLE pgv_user');
        [$code, , $errors] = $this->upgrade();
        self::assertSame(Yooper::FAILED, $code);
        self::assertStringContainsString('has no user tables', $errors);
    }
}
