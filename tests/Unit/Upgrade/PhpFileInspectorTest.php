<?php

declare(strict_types=1);

namespace YooperGEDView\Tests\Upgrade;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use YooperGEDView\Upgrade\PhpFileInspector;

#[CoversClass(PhpFileInspector::class)]
final class PhpFileInspectorTest extends TestCase
{
    /**
     * The shape of a tree's settings file as PhpGedView writes it.
     */
    private const string SETTINGS = <<<'PHP'
        <?php
        /**
         * GEDCOM configuration file
         */
        if (!defined('PGV_PHPGEDVIEW')) {
        	header('HTTP/1.0 403 Forbidden');
        	exit;
        }

        global $PRIV_HIDE, $PRIV_PUBLIC, $PRIV_USER, $PRIV_NONE;

        //-- assign the default language
        $LANGUAGE = "english";
        $CALENDAR_FORMAT = "gregorian";
        $PEDIGREE_GENERATIONS = 4;
        $SHOW_LIVING_NAMES = $PRIV_PUBLIC;
        $MAX_ALIVE_AGE = -1 + 121;
        $HOME_SITE_URL = "http://www." . "example.org/";
        $person_privacy = array();
        $person_privacy["I8"] = $PRIV_HIDE;
        $global_facts["SSN"]["show"] = $PRIV_NONE;
        $WELCOME_TEXT = @$OLD_WELCOME_TEXT;
        if ($PEDIGREE_GENERATIONS != 4) $PEDIGREE_GENERATIONS = 4;
        if (substr ($THEME_DIR, -1) != "/") $THEME_DIR = $THEME_DIR . "/";
        @set_time_limit($TIME_LIMIT);
        ?>
        PHP;

    public function testASettingsFileAsPhpGedViewWritesItHasNoFindings(): void
    {
        self::assertSame([], (new PhpFileInspector())->findings(self::SETTINGS));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function thingsASettingsFileHasNoUseFor(): array
    {
        return [
            'a function call' => ['$X = system("id");', 'calls system()'],
            'a call in capitals' => ['$X = PHPINFO();', 'calls PHPINFO()'],
            'eval' => ['eval($_GET["x"]);', 'runs text as code (eval)'],
            'include' => ['include "/tmp/x.php";', 'loads another file (include)'],
            'require_once' => ['require_once $f;', 'loads another file (require_once)'],
            'backticks' => ['$X = `id`;', 'runs a command (backticks)'],
            'a function named by a variable' => ['$f("id");', 'calls a function named by a variable'],
            'a variable named by a variable' => ['$$name = 1;', 'names a variable by the content of another'],
            'new' => ['$X = new SplFileObject("x");', 'creates an object (new)'],
            'an object used' => ['$X = $o->run;', 'uses an object (->)'],
            'a class used' => ['$X = Foo::BAR;', 'uses a class (::)'],
            'a function defined' => ['function f() {}', 'defines a function'],
            'a short function' => ['$f = fn($x) => $x;', 'defines a function'],
            'echo of a variable' => ['echo $x;', 'prints something (echo)'],
            'print of a variable' => ['print $x;', 'prints something (print)'],
            'print of a text PHP works on' => ['print "a $x b";', 'prints something (print)'],
            'print of a text joined with something' => ['print "a" . $x;', 'prints something (print)'],
            'the file cut short' => ['__halt_compiler();', 'cuts the file short'],
            'a heredoc' => ["\$X = <<<A\nx\nA;\n", 'heredoc'],
        ];
    }

    #[DataProvider('thingsASettingsFileHasNoUseFor')]
    public function testEachIsReportedWithItsLine(string $statement, string $finding): void
    {
        $findings = (new PhpFileInspector())->findings("<?php\n\$LANGUAGE = \"english\";\n" . $statement . "\n");

        self::assertNotSame([], $findings);
        self::assertStringContainsString($finding, implode("\n", $findings));
        self::assertStringStartsWith('line 3: ', $findings[0]);
    }

    public function testTheSettingsTemplatesThisProgramShipsHaveNoFindings(): void
    {
        foreach (['config_gedcom.php', 'privacy.php'] as $template) {
            $php = file_get_contents(__DIR__ . '/../../../app/' . $template);
            self::assertIsString($php);
            self::assertSame([], (new PhpFileInspector())->findings($php), $template);
        }
    }

    public function testTheGuardThatEarlierVersionsOfPhpGedViewWroteHasNoFindings(): void
    {
        $guards = [
            "<?php\nif (stristr(\$_SERVER[\"SCRIPT_NAME\"], basename(__FILE__))!==false) {\n\tprint \"Naughty, Naughty, Naughty!\";\n\texit;\n}\n\$LANGUAGE = \"english\";\n",
            "<?php\nif (preg_match(\"/\\Wconfig_gedcom\\.php/\", \$_SERVER[\"SCRIPT_NAME\"])>0) {\n\tprint \"Got your hand caught in the cookie jar.\";\n\texit;\n}\n",
            "<?php\nif (stristr(\$_SERVER['SCRIPT_NAME'], basename(__FILE__))!==false) { echo 'No.'; exit; }\n",
        ];
        foreach ($guards as $guard) {
            self::assertSame([], (new PhpFileInspector())->findings($guard), $guard);
        }
    }

    public function testTextOutsideThePhpTagsIsReported(): void
    {
        $findings = (new PhpFileInspector())->findings("<html>\n<?php \$X = 1; ?>\n");

        self::assertStringContainsString('text outside the PHP tags', implode("\n", $findings));
    }

    public function testTheSameThingOnTheSameLineIsReportedOnce(): void
    {
        self::assertCount(1, (new PhpFileInspector())->findings('<?php system("a"); system("b");'));
    }

    public function testAFileThatIsNotPhpAtAllIsOneFinding(): void
    {
        self::assertSame(['line 1: has text outside the PHP tags'], (new PhpFileInspector())->findings('just some text'));
    }
}
