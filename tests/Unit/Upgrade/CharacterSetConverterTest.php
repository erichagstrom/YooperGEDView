<?php

declare(strict_types=1);

namespace YooperGEDView\Tests\Upgrade;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use YooperGEDView\Upgrade\CharacterSetConverter;

/**
 * What can be checked without a MariaDB server. The conversion itself is tested in tests/MariaDb.
 */
#[CoversClass(CharacterSetConverter::class)]
final class CharacterSetConverterTest extends TestCase
{
    public function testADatabaseWithoutCharacterSetsPerTableHasNothingToConvert(): void
    {
        self::assertFalse((new CharacterSetConverter(new PDO('sqlite::memory:'), 'pgv_'))->applies());
    }

    public function testATablePrefixThatCouldChangeAQueryIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CharacterSetConverter(new PDO('sqlite::memory:'), 'pgv_`; DROP TABLE x; --');
    }
}
