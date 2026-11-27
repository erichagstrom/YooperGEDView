<?php

declare(strict_types=1);

namespace YooperGEDView\Tests\Upgrade;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use YooperGEDView\Upgrade\PendingChangesFile;
use YooperGEDView\Upgrade\UnreadableFile;

#[CoversClass(PendingChangesFile::class)]
#[CoversClass(UnreadableFile::class)]
final class PendingChangesFileTest extends TestCase
{
    /**
     * A file as PhpGedView 4.3.0 writes it, with one record changed twice and another once.
     */
    private const string FILE = <<<'PHP'
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
        1 NAME Aino /Kivela/
        1 NOTE It\'s a note with a backslash \\ in it';
        // End of change record.
        $pgv_changes["I2_sample.ged"][] = $change;
        // Start of change record.
        $change = array();
        $change["gid"] = 'I2';
        $change["gedcom"] = 'sample.ged';
        $change["type"] = 'replace';
        $change["status"] = 'submitted';
        $change["user"] = 'bertil';
        $change["time"] = '1700000100';
        $change["undo"] = '0 @I2@ INDI
        1 NAME Aino /Maki/';
        // End of change record.
        $pgv_changes["I2_sample.ged"][] = $change;
        $pgv_changes["F1_sample.ged"] = array();
        // Start of change record.
        $change = array();
        $change["gid"] = 'F1';
        $change["gedcom"] = 'sample.ged';
        $change["type"] = 'delete';
        $change["status"] = 'submitted';
        $change["user"] = 'anna';
        $change["time"] = '1700000200';
        $change["linkpid"] = 'I1';
        $change["undo"] = '';
        // End of change record.
        $pgv_changes["F1_sample.ged"][] = $change;
        ?>
        PHP;

    public function testTheChangesComeOutAsTheProgramKeepsThem(): void
    {
        $changes = (new PendingChangesFile())->read(self::FILE);

        self::assertSame(['I2_sample.ged', 'F1_sample.ged'], array_keys($changes));
        self::assertCount(2, $changes['I2_sample.ged']);
        self::assertSame(
            [
                'gid' => 'I2',
                'gedcom' => 'sample.ged',
                'type' => 'replace',
                'status' => 'submitted',
                'user' => 'anna',
                'time' => '1700000000',
                'undo' => "0 @I2@ INDI\n1 NAME Aino /Kivela/\n1 NOTE It's a note with a backslash \\ in it",
            ],
            $changes['I2_sample.ged'][0],
        );
        self::assertSame('bertil', $changes['I2_sample.ged'][1]['user']);
        self::assertSame('I1', $changes['F1_sample.ged'][0]['linkpid']);
        self::assertSame('', $changes['F1_sample.ged'][0]['undo']);
    }

    public function testAFileWithNoChangesGivesNone(): void
    {
        self::assertSame([], (new PendingChangesFile())->read("<?php\n\$pgv_changes = array();\n"));
        self::assertSame([], (new PendingChangesFile())->read("<?php\n\$pgv_changes = array();\n\$pgv_changes[\"I1_a.ged\"] = array();\n?>"));
    }

    public function testWhatPhpWouldReadIsWhatComesOut(): void
    {
        // The reference: PHP's own reading of the same file. Safe here because the text is this test's own.
        $pgv_changes = [];
        $file = tempnam(sys_get_temp_dir(), 'changes');
        self::assertIsString($file);
        file_put_contents($file, self::FILE);
        require $file;
        unlink($file);

        self::assertSame($pgv_changes, (new PendingChangesFile())->read(self::FILE));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function filesThatAreNotPendingChanges(): array
    {
        $start = "<?php\n\$pgv_changes = array();\n\$pgv_changes[\"I1_a.ged\"] = array();\n\$change = array();\n";

        return [
            'a user name that closes the quotes and calls a function' => [$start . "\$change[\"user\"] = 'x'.print(31337*31337).'';\n"],
            'a function call on its own' => [$start . "phpinfo();\n"],
            'a call hidden in a value' => [$start . "\$change[\"gid\"] = system('id');\n"],
            'another file loaded' => [$start . "include 'x.php';\n"],
            'a text that PHP would work on' => [$start . "\$change[\"gid\"] = \"{\${phpinfo()}}\";\n"],
            'a double-quoted text with a variable' => [$start . "\$change[\"gid\"] = \"\$x\";\n"],
            'a key with a backslash between double quotes' => [$start . "\$change[\"gi\\x64\"] = 'I1';\n"],
            'another variable set' => [$start . "\$other = 'x';\n"],
            'a value that is not text' => [$start . "\$change[\"gid\"] = 12;\n"],
            'a change stored before it is started' => ["<?php\n\$pgv_changes = array();\n\$pgv_changes[\"I1_a.ged\"][] = \$change;\n"],
            'a field set before a change is started' => ["<?php\n\$pgv_changes = array();\n\$change[\"gid\"] = 'I1';\n"],
            'the list emptied half way' => [$start . "\$change[\"gid\"] = 'I1';\n\$pgv_changes[\"I1_a.ged\"][] = \$change;\n\$pgv_changes = array();\n"],
            'a statement left unfinished' => [$start . "\$change[\"gid\"] = 'I1'"],
            'text outside the PHP tags' => ["hello\n" . $start],
            'the rest cut off' => [$start . "__halt_compiler();"],
            'backticks' => [$start . "\$change[\"gid\"] = `id`;\n"],
        ];
    }

    #[DataProvider('filesThatAreNotPendingChanges')]
    public function testAnythingElseInTheFileRefusesTheWholeFile(string $php): void
    {
        $this->expectException(UnreadableFile::class);

        (new PendingChangesFile())->read($php);
    }
}
