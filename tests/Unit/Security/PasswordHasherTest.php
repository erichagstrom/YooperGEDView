<?php

declare(strict_types=1);

namespace YooperGEDView\Tests\Security;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use YooperGEDView\Security\PasswordHasher;

#[CoversClass(PasswordHasher::class)]
final class PasswordHasherTest extends TestCase
{
    private const string PASSWORD = 'correct horse battery staple';

    public function testAHashIsArgon2idAndVerifies(): void
    {
        $hasher = new PasswordHasher();
        $hash = $hasher->hash(self::PASSWORD);

        self::assertStringStartsWith('$argon2id$', $hash);
        self::assertTrue($hasher->verify(self::PASSWORD, $hash));
        self::assertFalse($hasher->needsRehash($hash));
    }

    public function testTheSamePasswordGivesADifferentHashEachTime(): void
    {
        $hasher = new PasswordHasher();

        self::assertNotSame($hasher->hash(self::PASSWORD), $hasher->hash(self::PASSWORD));
    }

    public function testAWrongPasswordIsRefused(): void
    {
        $hasher = new PasswordHasher();

        self::assertFalse($hasher->verify('wrong password', $hasher->hash(self::PASSWORD)));
    }

    public function testAnEmptyPasswordCannotBeStored(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new PasswordHasher())->hash('');
    }

    /**
     * Each of these is a correct hash of the password in a format the program no longer accepts.
     */
    #[DataProvider('hashesInOtherFormats')]
    public function testACorrectPasswordIsRefusedWhenTheHashIsNotArgon2id(string $hash): void
    {
        self::assertFalse((new PasswordHasher())->verify(self::PASSWORD, $hash));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function hashesInOtherFormats(): array
    {
        return [
            'MD5-crypt, as PhpGedView stored it' => [crypt(self::PASSWORD, '$1$padpadpa$')],
            'bcrypt' => [password_hash(self::PASSWORD, PASSWORD_BCRYPT, ['cost' => 4])],
            'argon2i' => [password_hash(self::PASSWORD, PASSWORD_ARGON2I, ['memory_cost' => 1024, 'time_cost' => 1])],
            'the password itself' => [self::PASSWORD],
            'nothing' => [''],
        ];
    }

    public function testAHashMadeWithWeakerSettingsNeedsReplacing(): void
    {
        $hasher = new PasswordHasher();
        $weak = password_hash(self::PASSWORD, PASSWORD_ARGON2ID, ['memory_cost' => 1024, 'time_cost' => 1]);

        self::assertTrue($hasher->verify(self::PASSWORD, $weak));
        self::assertTrue($hasher->needsRehash($weak));
    }
}
