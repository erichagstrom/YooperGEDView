<?php

declare(strict_types=1);

namespace YooperGEDView\Tests\Report;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use YooperGEDView\Report\ConditionEvaluator;
use YooperGEDView\Report\InvalidCondition;

#[CoversClass(ConditionEvaluator::class)]
#[CoversClass(InvalidCondition::class)]
final class ConditionEvaluatorTest extends TestCase
{
    private const array VARIABLES = [
        'sources' => '1',
        'notes' => '0',
        'printname' => '1',
        'sortby' => 'BIRT:DATE',
        'pid' => 'I1',
        'name' => 'maki',
        'count' => 3,
        'empty' => '',
    ];

    private const array TAGS = [
        'SEX' => 'F',
        'NAME' => 'Aino /Maki/',
        'BIRT:DATE' => '12 MAR 1890',
        'BIRT:PLAC' => '',
        'HUSB' => 'I1',
        'NOTE' => "She said \"hello\" and it's fine",
        'desc' => 'Y',
    ];

    private function holds(string $condition): bool
    {
        return (new ConditionEvaluator())->holds(
            $condition,
            static fn(string $name): string|int|null => self::VARIABLES[$name] ?? null,
            static fn(string $name): string => self::TAGS[$name] ?? '',
        );
    }

    /**
     * Conditions as the bundled reports write them, with what they should come to.
     *
     * @return array<string, array{string, bool}>
     */
    public static function conditions(): array
    {
        return [
            'a tag that has a value' => ["@BIRT:DATE!=''", true],
            'a tag that has none' => ["@BIRT:PLAC!=''", false],
            'a tag that is not in the record' => ["@DEAT:DATE!=''", false],
            'a tag equal to text' => ["@SEX=='F'", true],
            'a tag equal to other text' => ["@SEX=='M'", false],
            'a variable holding "1" equals the number 1' => ['$sources==1', true],
            'a variable holding "0" does not' => ['$notes==1', false],
            'a variable equal to quoted 1' => ["\$printname=='1'", true],
            'a variable equal to text with a colon' => ["\$sortby=='BIRT:DATE'", true],
            'a variable equal to a tag' => ['$pid==@HUSB', true],
            'a tag not equal to a variable' => ['@HUSB!=$pid', false],
            'a variable that was never set equals nothing' => ["\$missing==''", true],
            'a variable that was never set is not 1' => ['$missing==1', false],
            'or, first side true' => ["@BIRT:DATE!='' or @BIRT:PLAC!=''", true],
            'or, both sides false' => ["@DEAT:DATE!='' or @DEAT:PLAC!=''", false],
            'and with brackets' => ["(\$notes==1) and (@NOTE!='')", false],
            'and, both true' => ["(\$sources==1) and (@NOTE!='')", true],
            'brackets around or' => ["(@desc=='Y') or (@BIRT:DATE=='' and @BIRT:PLAC=='')", true],
            'and binds tighter than or' => ["@SEX=='M' and @SEX=='X' or @SEX=='F'", true],
            'three ors' => ["@A!='' or @B!='' or @SEX!=''", true],
            'spaces around the operator' => ["@BIRT:PLAC == ''", true],
            'greater than' => ['$count > 0', true],
            'greater than, false' => ['$count > 3', false],
            'at least' => ['$count >= 3', true],
            'less than as a word' => ['$count LT 5', true],
            'greater than as a word' => ['$count GT 5', false],
            'symbols for and and or' => ["\$count==3 && (@SEX=='M' || @SEX=='F')", true],
            'not' => ['!($count==3)', false],
            'not binds tighter than a comparison' => ["!\$empty == true", true],
            'strictly not false' => ['stristr(@NAME,$name)!==false', true],
            'a name that is not found' => ["stristr(@NAME,'lindqvist')!==false", false],
            'double quotes' => ['@SEX=="F"', true],
            'an escaped quote inside text' => ["@NOTE=='She said \"hello\" and it\\'s fine'", true],
            'text is never a number' => ["@SEX==0", false],
            'a bare value' => ['$count', true],
            'a bare empty value' => ['$empty', false],
            'true and false as words' => ['true and !false', true],
        ];
    }

    #[DataProvider('conditions')]
    public function testAConditionComesToWhatPhpWouldMakeOfIt(string $condition, bool $expected): void
    {
        self::assertSame($expected, $this->holds($condition));
    }

    /**
     * Values that would have run as PHP when the condition was built into a line of code.
     *
     * @return array<string, array{string}>
     */
    public static function valuesWrittenToRunAsCode(): array
    {
        return [
            'a variable in braces' => ['{${phpinfo()}}'],
            'a plain variable' => ['$GLOBALS'],
            'a quote that ends the text early' => ['" || phpinfo() || "'],
            'a backslash before the closing quote' => ['x\\'],
            'a single quote' => ["' or '1'=='1"],
            'backticks' => ['`id`'],
        ];
    }

    #[DataProvider('valuesWrittenToRunAsCode')]
    public function testAValueFromTheTreeIsOnlyEverAValue(string $value): void
    {
        $evaluator = new ConditionEvaluator();
        $variable = static fn(string $name): ?string => null;
        $tag = static fn(string $name): string => $value;

        self::assertTrue($evaluator->holds("@NOTE!=''", $variable, $tag));
        self::assertFalse($evaluator->holds("@NOTE=='something else'", $variable, $tag));
        self::assertTrue($evaluator->holds('@NOTE==@NOTE', $variable, $tag));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function conditionsThatCannotBeRead(): array
    {
        return [
            'nothing' => [''],
            'a function other than stristr' => ['phpinfo()'],
            'a function call inside' => ["@SEX=='F' or system('id')"],
            'a word on its own' => ['BIRT'],
            'what @fact:DATE used to turn into' => ["BIRT:DATE!=''"],
            'a statement' => ["1; echo 'x'"],
            'an assignment' => ['$a = 1'],
            'a bracket left open' => ["(@SEX=='F'"],
            'a bracket too many' => ["@SEX=='F')"],
            'a comparison with nothing after it' => ['@SEX=='],
            'two values side by side' => ["@SEX 'F'"],
            'an array index' => ['$vars["a"]'],
            'a member' => ['$a->b'],
            'text left open' => ["@SEX=='F"],
            'stristr with one value' => ['stristr(@NAME)'],
            'a backtick' => ['`id`'],
        ];
    }

    #[DataProvider('conditionsThatCannotBeRead')]
    public function testAnythingElseIsRefused(string $condition): void
    {
        $this->expectException(InvalidCondition::class);

        $this->holds($condition);
    }

    public function testOnlyTheNamesInTheConditionAreLookedUp(): void
    {
        $asked = [];
        $tag = static function (string $name) use (&$asked): string {
            $asked[] = $name;

            return 'x';
        };

        (new ConditionEvaluator())->holds("@BIRT:DATE!='' and @_PGVS=='x'", static fn(string $name): ?string => null, $tag);

        self::assertSame(['BIRT:DATE', '_PGVS'], $asked);
    }
}
