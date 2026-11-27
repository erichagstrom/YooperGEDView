<?php

declare(strict_types=1);

namespace YooperGEDView\Tests\Security;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use YooperGEDView\Security\SignInAttemptRepository;
use YooperGEDView\Security\SignInThrottle;

#[CoversClass(SignInThrottle::class)]
#[CoversClass(SignInAttemptRepository::class)]
final class SignInThrottleTest extends TestCase
{
    private const int NOW = 1_700_000_000;
    private const string ADDRESS = '192.0.2.1';
    private const string OTHER_ADDRESS = '192.0.2.2';

    private PDO $database;

    protected function setUp(): void
    {
        // The same columns as app/includes/db_schema/db_schema_14_15.php creates.
        $this->database = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->database->exec(
            'CREATE TABLE pgv_sign_in_attempt (attempt_id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,'
            . ' user_name VARCHAR(64) NOT NULL, ip_address VARCHAR(45) NOT NULL, attempt_time INTEGER NOT NULL,'
            . ' success INTEGER NOT NULL)',
        );
    }

    private function throttle(int $perUser = 3, int $perAddress = 5, int $window = 900): SignInThrottle
    {
        return new SignInThrottle(new SignInAttemptRepository($this->database, 'pgv_'), $perUser, $perAddress, $window);
    }

    public function testNothingIsBlockedBeforeAnyFailure(): void
    {
        self::assertFalse($this->throttle()->isBlocked('anna', self::ADDRESS, self::NOW));
    }

    public function testAUserNameIsBlockedOnceItReachesTheLimitWhateverTheAddress(): void
    {
        $throttle = $this->throttle();
        $throttle->recordFailure('anna', self::ADDRESS, self::NOW);
        $throttle->recordFailure('anna', self::ADDRESS, self::NOW + 1);
        self::assertFalse($throttle->isBlocked('anna', self::ADDRESS, self::NOW + 2));

        $throttle->recordFailure('anna', self::OTHER_ADDRESS, self::NOW + 2);

        self::assertTrue($throttle->isBlocked('anna', self::ADDRESS, self::NOW + 3));
        self::assertTrue($throttle->isBlocked('anna', '192.0.2.3', self::NOW + 3));
        self::assertFalse($throttle->isBlocked('bertil', '192.0.2.3', self::NOW + 3));
    }

    public function testUserNamesAreComparedWithoutCaseOrSurroundingSpaces(): void
    {
        $throttle = $this->throttle();
        $throttle->recordFailure('Anna', self::ADDRESS, self::NOW);
        $throttle->recordFailure(' ANNA ', self::ADDRESS, self::NOW);
        $throttle->recordFailure('anna', self::ADDRESS, self::NOW);

        self::assertTrue($throttle->isBlocked('aNNa', self::OTHER_ADDRESS, self::NOW));
    }

    public function testAnAddressIsBlockedOnceItReachesItsLimitWhateverTheUserName(): void
    {
        $throttle = $this->throttle();
        foreach (['a', 'b', 'c', 'd', 'e'] as $name) {
            self::assertFalse($throttle->isBlocked($name, self::ADDRESS, self::NOW));
            $throttle->recordFailure($name, self::ADDRESS, self::NOW);
        }

        self::assertTrue($throttle->isBlocked('anna', self::ADDRESS, self::NOW));
        self::assertFalse($throttle->isBlocked('anna', self::OTHER_ADDRESS, self::NOW));
    }

    public function testABlockEndsWhenTheFailuresLeaveTheWindow(): void
    {
        $throttle = $this->throttle();
        foreach ([0, 100, 200] as $offset) {
            $throttle->recordFailure('anna', self::ADDRESS, self::NOW + $offset);
        }

        self::assertTrue($throttle->isBlocked('anna', self::ADDRESS, self::NOW + 900));
        self::assertFalse($throttle->isBlocked('anna', self::ADDRESS, self::NOW + 901));
    }

    public function testASuccessfulSignInClearsTheUsersEarlierFailuresButNotTheAddresss(): void
    {
        $throttle = $this->throttle(3, 4);
        $throttle->recordFailure('anna', self::ADDRESS, self::NOW);
        $throttle->recordFailure('anna', self::ADDRESS, self::NOW);
        $throttle->recordSuccess('anna', self::ADDRESS, self::NOW);
        $throttle->recordFailure('anna', self::ADDRESS, self::NOW);

        self::assertFalse($throttle->isBlocked('anna', self::ADDRESS, self::NOW));

        $throttle->recordFailure('anna', self::OTHER_ADDRESS, self::NOW);
        $throttle->recordFailure('bertil', self::ADDRESS, self::NOW);

        self::assertFalse($throttle->isBlocked('anna', self::OTHER_ADDRESS, self::NOW));
        self::assertTrue($throttle->isBlocked('cecilia', self::ADDRESS, self::NOW));
    }

    public function testEveryAttemptIsKeptAsARecord(): void
    {
        $throttle = $this->throttle();
        $throttle->recordFailure('Anna', self::ADDRESS, self::NOW);
        $throttle->recordSuccess('anna', self::OTHER_ADDRESS, self::NOW + 5);

        $rows = $this->database->query(
            'SELECT user_name, ip_address, attempt_time, success FROM pgv_sign_in_attempt ORDER BY attempt_id',
        );
        self::assertNotFalse($rows);
        self::assertSame(
            [
                ['user_name' => 'anna', 'ip_address' => self::ADDRESS, 'attempt_time' => self::NOW, 'success' => 0],
                ['user_name' => 'anna', 'ip_address' => self::OTHER_ADDRESS, 'attempt_time' => self::NOW + 5, 'success' => 1],
            ],
            $rows->fetchAll(PDO::FETCH_ASSOC),
        );
    }

    public function testRecordsOlderThanNinetyDaysAreDeletedAtTheNextSuccessfulSignIn(): void
    {
        $throttle = $this->throttle();
        $throttle->recordFailure('old', self::ADDRESS, self::NOW - 91 * 86400);
        $throttle->recordFailure('recent', self::ADDRESS, self::NOW - 89 * 86400);
        $throttle->recordSuccess('anna', self::ADDRESS, self::NOW);

        $names = $this->database->query('SELECT user_name FROM pgv_sign_in_attempt ORDER BY attempt_id');
        self::assertNotFalse($names);
        self::assertSame(['recent', 'anna'], $names->fetchAll(PDO::FETCH_COLUMN));
    }

    public function testAVeryLongUserNameIsCutToFitTheColumn(): void
    {
        $this->throttle()->recordFailure(str_repeat('é', 300), self::ADDRESS, self::NOW);

        $length = $this->database->query('SELECT LENGTH(user_name) FROM pgv_sign_in_attempt');
        self::assertNotFalse($length);
        self::assertSame(64, $length->fetchColumn());
    }

    public function testLimitsMustBePositive(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->throttle(0);
    }

    public function testATablePrefixWithOtherCharactersIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SignInAttemptRepository($this->database, 'pgv_; DROP TABLE x; --');
    }
}
