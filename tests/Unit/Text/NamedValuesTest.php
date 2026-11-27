<?php

declare(strict_types=1);

namespace YooperGEDView\Tests\Text;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use YooperGEDView\Text\NamedValues;

#[CoversClass(NamedValues::class)]
final class NamedValuesTest extends TestCase
{
    private function values(): NamedValues
    {
        return new NamedValues([
            'pgv_lang' => ['edit' => 'Edit', 'empty' => '', 'nested' => ['a' => 'b'], 'with space' => 'spaced'],
            'factarray' => ['BIRT' => 'Birth'],
            'MEDIA_DIRECTORY' => 'media/',
            'count' => 3,
            'flag' => false,
            'nothing' => null,
        ]);
    }

    public function testASingleValueIsFoundByItsName(): void
    {
        self::assertSame('media/', $this->values()->find('MEDIA_DIRECTORY'));
        self::assertSame(3, $this->values()->find('count'));
        self::assertFalse($this->values()->find('flag'));
    }

    public function testAnEntryOfAListIsFoundByNameAndKey(): void
    {
        self::assertSame('Edit', $this->values()->find("pgv_lang['edit']"));
        self::assertSame('Birth', $this->values()->find("factarray['BIRT']"));
        self::assertSame('', $this->values()->find("pgv_lang['empty']"));
        self::assertSame('spaced', $this->values()->find("pgv_lang['with space']"));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function referencesThatLeadNowhere(): array
    {
        return [
            'a name that was not handed over' => ['DBPASS'],
            'the list of all variables' => ["GLOBALS['DBPASS']"],
            'a key that is not in the list' => ["pgv_lang['missing']"],
            'a key on a single value' => ["MEDIA_DIRECTORY['x']"],
            'a whole list' => ['pgv_lang'],
            'a list inside a list' => ["pgv_lang['nested']"],
            'two keys' => ["pgv_lang['nested']['a']"],
            'a value that is not set' => ['nothing'],
            'a key without quotes' => ['pgv_lang[edit]'],
            'a key in double quotes' => ['pgv_lang["edit"]'],
            'a function call' => ['phpinfo()'],
            'a member' => ['a->b'],
            'code after the name' => ["pgv_lang['edit']; phpinfo()"],
            'a dollar sign' => ['$MEDIA_DIRECTORY'],
            'nothing' => [''],
        ];
    }

    #[DataProvider('referencesThatLeadNowhere')]
    public function testAnythingElseFindsNothing(string $reference): void
    {
        self::assertNull($this->values()->find($reference));
    }
}
