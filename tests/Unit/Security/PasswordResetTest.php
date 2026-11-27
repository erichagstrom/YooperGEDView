<?php

declare(strict_types=1);

namespace YooperGEDView\Tests\Security;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use YooperGEDView\Security\PasswordReset;
use YooperGEDView\Security\PasswordResetRepository;

#[CoversClass(PasswordReset::class)]
#[CoversClass(PasswordResetRepository::class)]
final class PasswordResetTest extends TestCase
{
    private const int NOW = 1_700_000_000;
    private const int ANNA = 7;
    private const int BERTIL = 8;

    private PDO $database;

    private PasswordReset $reset;

    protected function setUp(): void
    {
        // The same columns as app/includes/db_schema/db_schema_15_16.php creates.
        $this->database = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->database->exec(
            'CREATE TABLE pgv_password_reset (token_hash VARCHAR(64) NOT NULL PRIMARY KEY, user_id INTEGER NOT NULL,'
            . ' created_time INTEGER NOT NULL)',
        );
        $this->reset = new PasswordReset(new PasswordResetRepository($this->database, 'pgv_'));
    }

    private function token(int $userId, int $now = self::NOW): string
    {
        $token = $this->reset->request($userId, $now);
        self::assertNotNull($token);

        return $token;
    }

    public function testATokenIsLongRandomAndLeadsToItsUser(): void
    {
        $token = $this->token(self::ANNA);

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
        self::assertNotSame($token, $this->token(self::BERTIL));
        self::assertSame(self::ANNA, $this->reset->userFor($token, self::NOW + 10));
    }

    public function testTheTokenItselfIsNotStored(): void
    {
        $token = $this->token(self::ANNA);

        $stored = $this->database->query('SELECT token_hash FROM pgv_password_reset');
        self::assertNotFalse($stored);
        self::assertSame([hash('sha256', $token)], $stored->fetchAll(PDO::FETCH_COLUMN));
    }

    public function testATokenWorksForAnHourAndNoLonger(): void
    {
        $token = $this->token(self::ANNA);

        self::assertSame(self::ANNA, $this->reset->userFor($token, self::NOW + 3600));
        self::assertNull($this->reset->userFor($token, self::NOW + 3601));
        self::assertNull($this->reset->complete($token, self::NOW + 3601));
    }

    public function testATokenWorksOnce(): void
    {
        $token = $this->token(self::ANNA);

        self::assertSame(self::ANNA, $this->reset->complete($token, self::NOW + 60));
        self::assertNull($this->reset->userFor($token, self::NOW + 61));
        self::assertNull($this->reset->complete($token, self::NOW + 61));
    }

    public function testLookingATokenUpDoesNotUseIt(): void
    {
        $token = $this->token(self::ANNA);

        $this->reset->userFor($token, self::NOW);

        self::assertSame(self::ANNA, $this->reset->complete($token, self::NOW));
    }

    public function testAskingAgainTooSoonGivesNoNewToken(): void
    {
        $first = $this->token(self::ANNA);

        self::assertNull($this->reset->request(self::ANNA, self::NOW + 299));
        self::assertSame(self::ANNA, $this->reset->userFor($first, self::NOW + 299));
        self::assertNotNull($this->reset->request(self::BERTIL, self::NOW + 299));
    }

    public function testANewTokenReplacesTheUsersEarlierOne(): void
    {
        $first = $this->token(self::ANNA);
        $second = $this->token(self::ANNA, self::NOW + 300);

        self::assertNull($this->reset->userFor($first, self::NOW + 301));
        self::assertSame(self::ANNA, $this->reset->userFor($second, self::NOW + 301));
    }

    public function testUsingATokenForgetsOnlyThatUsersTokens(): void
    {
        $annas = $this->token(self::ANNA);
        $bertils = $this->token(self::BERTIL);

        $this->reset->complete($annas, self::NOW);

        self::assertSame(self::BERTIL, $this->reset->userFor($bertils, self::NOW));
    }

    public function testTokensThatHaveRunOutAreClearedAway(): void
    {
        $this->token(self::ANNA);

        $this->token(self::BERTIL, self::NOW + 3601);

        $users = $this->database->query('SELECT user_id FROM pgv_password_reset');
        self::assertNotFalse($users);
        self::assertSame([self::BERTIL], $users->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function thingsThatAreNotTokens(): array
    {
        return [
            'nothing' => [''],
            'a made-up token' => [str_repeat('a', 64)],
            'too short' => ['abc123'],
            'capitals' => [str_repeat('A', 64)],
            'an attempt at SQL' => ["' OR '1'='1"],
        ];
    }

    #[DataProvider('thingsThatAreNotTokens')]
    public function testAnythingElseLeadsNowhere(string $token): void
    {
        $this->token(self::ANNA);

        self::assertNull($this->reset->userFor($token, self::NOW));
        self::assertNull($this->reset->complete($token, self::NOW));
    }

    public function testATablePrefixWithOtherCharactersIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new PasswordResetRepository($this->database, 'pgv_; --');
    }
}
