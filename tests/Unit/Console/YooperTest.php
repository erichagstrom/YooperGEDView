<?php

declare(strict_types=1);

namespace YooperGEDView\Tests\Console;

use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use YooperGEDView\Console\Yooper;
use YooperGEDView\Database\ConnectionFactory;
use YooperGEDView\Security\PasswordHasher;
use YooperGEDView\User\Administrator;
use YooperGEDView\User\AdministratorRepository;

#[CoversClass(Yooper::class)]
#[CoversClass(ConnectionFactory::class)]
#[CoversClass(Administrator::class)]
#[CoversClass(AdministratorRepository::class)]
final class YooperTest extends TestCase
{
    private const string PASSWORD = 'a long enough password';
    private const int NOW = 1_700_000_000;

    private string $file;

    private string $output = '';

    private string $errors = '';

    protected function setUp(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'yooper');
        self::assertIsString($file);
        $this->file = $file;
    }

    protected function tearDown(): void
    {
        unlink($this->file);
    }

    /**
     * The user tables as app/includes/db_schema/db_schema_11_12.php creates them.
     */
    private function database(bool $withTables = true): PDO
    {
        $database = new PDO('sqlite:' . $this->file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        if ($withTables) {
            $database->exec(
                'CREATE TABLE IF NOT EXISTS pgv_user (user_id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,'
                . ' user_name VARCHAR(32) NOT NULL, password VARCHAR(255) NOT NULL, UNIQUE (user_name))',
            );
            $database->exec(
                'CREATE TABLE IF NOT EXISTS pgv_user_setting (user_id INTEGER NOT NULL, setting_name VARCHAR(32) NOT NULL,'
                . ' setting_value VARCHAR(255) NOT NULL, PRIMARY KEY (user_id, setting_name))',
            );
        }

        return $database;
    }

    /**
     * @param list<string> $arguments
     * @param array<string, string>|null $environment
     */
    private function yooper(array $arguments, string $input = self::PASSWORD . "\n", ?array $environment = null): int
    {
        $in = fopen('php://memory', 'r+');
        $out = fopen('php://memory', 'r+');
        $err = fopen('php://memory', 'r+');
        self::assertIsResource($in);
        self::assertIsResource($out);
        self::assertIsResource($err);
        fwrite($in, $input);
        rewind($in);

        $code = (new Yooper(self::NOW))->run(
            $arguments,
            $environment ?? ['DB_TYPE' => 'sqlite', 'DB_NAME' => $this->file],
            $in,
            $out,
            $err,
        );
        $this->output = (string) stream_get_contents($out, null, 0);
        $this->errors = (string) stream_get_contents($err, null, 0);

        return $code;
    }

    /**
     * @return array<string, string>
     */
    private function settings(PDO $database): array
    {
        $rows = $database->query('SELECT setting_name, setting_value FROM pgv_user_setting ORDER BY setting_name');
        self::assertNotFalse($rows);
        /** @var array<string, string> $settings */
        $settings = $rows->fetchAll(PDO::FETCH_KEY_PAIR);

        return $settings;
    }

    public function testInstallCreatesAVerifiedAdministratorWhoseHashMatchesThePassword(): void
    {
        $database = $this->database();

        $code = $this->yooper(['install', 'anna', '--email=anna@example.org', '--first-name=Anna', '--last-name=Maki']);

        self::assertSame(Yooper::OK, $code, $this->errors);
        self::assertStringContainsString('Administrator "anna" created', $this->output);
        $hash = $database->query("SELECT password FROM pgv_user WHERE user_name = 'anna'");
        self::assertNotFalse($hash);
        self::assertTrue((new PasswordHasher())->verify(self::PASSWORD, (string) $hash->fetchColumn()));
        $settings = $this->settings($database);
        self::assertSame('Y', $settings['canadmin']);
        self::assertSame('yes', $settings['verified']);
        self::assertSame('yes', $settings['verified_by_admin']);
        self::assertSame('anna@example.org', $settings['email']);
        self::assertSame('Anna', $settings['firstname']);
        self::assertSame('Maki', $settings['lastname']);
        self::assertSame((string) self::NOW, $settings['reg_timestamp']);
        self::assertCount(18, $settings);
    }

    public function testThePasswordIsNotShown(): void
    {
        $this->database();

        $this->yooper(['install', 'anna', '--email=anna@example.org']);

        self::assertStringNotContainsString(self::PASSWORD, $this->output . $this->errors);
    }

    public function testASecondAdministratorIsNotCreatedThisWay(): void
    {
        $database = $this->database();
        self::assertSame(Yooper::OK, $this->yooper(['install', 'anna', '--email=anna@example.org']));

        $code = $this->yooper(['install', 'bertil', '--email=bertil@example.org']);

        self::assertSame(Yooper::FAILED, $code);
        self::assertStringContainsString('already has an administrator', $this->errors);
        $count = $database->query('SELECT COUNT(*) FROM pgv_user');
        self::assertNotFalse($count);
        self::assertSame(1, (int) $count->fetchColumn());
    }

    public function testAUserNameAlreadyInUseIsRefused(): void
    {
        $this->database()->exec("INSERT INTO pgv_user (user_name, password) VALUES ('anna', 'x')");

        self::assertSame(Yooper::FAILED, $this->yooper(['install', 'anna', '--email=anna@example.org']));
        self::assertStringContainsString('already a user called "anna"', $this->errors);
    }

    public function testAShortOrMissingPasswordIsRefusedAndNothingIsCreated(): void
    {
        $database = $this->database();

        self::assertSame(Yooper::FAILED, $this->yooper(['install', 'anna', '--email=anna@example.org'], "short\n"));
        self::assertSame(Yooper::FAILED, $this->yooper(['install', 'anna', '--email=anna@example.org'], ''));

        self::assertStringContainsString('at least 12 characters', $this->errors);
        $count = $database->query('SELECT COUNT(*) FROM pgv_user');
        self::assertNotFalse($count);
        self::assertSame(0, (int) $count->fetchColumn());
    }

    public function testADatabaseWithoutTablesGetsAPlainAnswer(): void
    {
        $this->database(false);

        self::assertSame(Yooper::FAILED, $this->yooper(['install', 'anna', '--email=anna@example.org']));
        self::assertStringContainsString('Open the site in a browser once', $this->errors);
    }

    /**
     * @return array<string, array{list<string>, string}>
     */
    public static function wrongUse(): array
    {
        return [
            'no user name' => [['install', '--email=a@example.org'], 'No user name given'],
            'no mail address' => [['install', 'anna'], 'No mail address given'],
            'not a mail address' => [['install', 'anna', '--email=anna'], 'is not a mail address'],
            'two user names' => [['install', 'anna', 'bertil', '--email=a@example.org'], 'Only one user name'],
            'an unknown option' => [['install', 'anna', '--email=a@example.org', '--admin=yes'], 'Unknown option --admin'],
            'an option without a value' => [['install', 'anna', '--email'], 'Options are written --name=value'],
            'a user name with markup' => [['install', '<b>anna', '--email=a@example.org'], 'A user name is 1 to 32 characters'],
            'a user name that is too long' => [['install', str_repeat('a', 33), '--email=a@example.org'], 'A user name is'],
            'a name with markup' => [['install', 'anna', '--email=a@example.org', '--first-name=<i>'], 'A name is 1 to 100'],
            'an unknown command' => [['upgrade'], 'Unknown command "upgrade"'],
        ];
    }

    /**
     * @param list<string> $arguments
     */
    #[DataProvider('wrongUse')]
    public function testWrongUseIsExplainedAndNothingIsTouched(array $arguments, string $message): void
    {
        self::assertSame(Yooper::WRONG_USE, $this->yooper($arguments, self::PASSWORD . "\n", []));
        self::assertStringContainsString($message, $this->errors);
        self::assertStringContainsString('Usage: bin/yooper install', $this->errors);
    }

    public function testWithoutArgumentsTheUsageIsShown(): void
    {
        self::assertSame(Yooper::WRONG_USE, $this->yooper([]));
        self::assertStringContainsString('Usage: bin/yooper install', $this->output);
        self::assertSame(Yooper::OK, $this->yooper(['--help']));
    }

    /**
     * @return array<string, array{array<string, string>, string}>
     */
    public static function unusableSettings(): array
    {
        return [
            'no database name' => [[], 'DB_NAME is not set'],
            'an unknown kind of database' => [['DB_TYPE' => 'oracle', 'DB_NAME' => 'x'], 'DB_TYPE must be'],
            'no host' => [['DB_NAME' => 'x'], 'DB_HOST is not set'],
            'a port that is not a number' => [['DB_NAME' => 'x', 'DB_HOST' => 'db', 'DB_PORT' => 'abc'], 'DB_PORT must be a number'],
            'a host that adds settings' => [['DB_NAME' => 'x', 'DB_HOST' => 'db;unix_socket=/tmp/x'], 'may not hold a semicolon'],
            'no SQLite file' => [['DB_TYPE' => 'sqlite', 'DB_NAME' => '/nonexistent/site.sqlite'], 'There is no SQLite database'],
            'a table prefix with other characters' => [['DB_TYPE' => 'sqlite', 'DB_NAME' => __FILE__, 'DB_PREFIX' => 'a b'], 'A table prefix may hold only'],
        ];
    }

    /**
     * @param array<string, string> $environment
     */
    #[DataProvider('unusableSettings')]
    public function testUnusableDatabaseSettingsAreExplained(array $environment, string $message): void
    {
        self::assertSame(Yooper::FAILED, $this->yooper(['install', 'anna', '--email=anna@example.org'], self::PASSWORD . "\n", $environment));
        self::assertStringContainsString($message, $this->errors);
    }

    public function testAnotherTablePrefixMeansOtherTables(): void
    {
        $environment = ['DB_TYPE' => 'sqlite', 'DB_NAME' => $this->file, 'DB_PREFIX' => 'other_'];
        $this->database();

        self::assertSame(Yooper::FAILED, $this->yooper(['install', 'anna', '--email=anna@example.org'], self::PASSWORD . "\n", $environment));
        self::assertStringContainsString('no tables yet', $this->errors);
    }
}
