<?php

declare(strict_types=1);

namespace YooperGEDView\Security;

use InvalidArgumentException;
use PDO;
use RuntimeException;

/**
 * The record of every sign-in attempt: who was named, from which address, when, and whether it succeeded.
 * It is both the audit log of sign-ins and what the limit on failed attempts counts.
 */
final readonly class SignInAttemptRepository
{
    private string $table;

    /**
     * @param string $tablePrefix the site's table prefix, from its settings; never from a request
     */
    public function __construct(private PDO $database, string $tablePrefix)
    {
        if (preg_match('/^[A-Za-z0-9_]*$/', $tablePrefix) !== 1) {
            throw new InvalidArgumentException('A table prefix may hold only letters, digits and underscores.');
        }
        $this->table = $tablePrefix . 'sign_in_attempt';
    }

    public function add(string $userName, string $address, bool $success, int $time): void
    {
        $statement = $this->database->prepare(
            'INSERT INTO ' . $this->table . ' (user_name, ip_address, attempt_time, success) VALUES (?, ?, ?, ?)',
        );
        $statement->execute([$userName, $address, $time, $success ? 1 : 0]);
    }

    /**
     * Failed attempts for a user name since the given time, not counting any made before that name's last
     * successful sign-in.
     */
    public function countFailuresForUser(string $userName, int $since): int
    {
        return $this->count(
            'SELECT COUNT(*) FROM ' . $this->table . ' WHERE user_name = ? AND success = 0 AND attempt_time >= ?'
            . ' AND attempt_id > COALESCE((SELECT MAX(attempt_id) FROM ' . $this->table
            . ' WHERE user_name = ? AND success = 1), 0)',
            [$userName, $since, $userName],
        );
    }

    /**
     * Failed attempts from an address since the given time, whichever user names they tried.
     */
    public function countFailuresFromAddress(string $address, int $since): int
    {
        return $this->count(
            'SELECT COUNT(*) FROM ' . $this->table . ' WHERE ip_address = ? AND success = 0 AND attempt_time >= ?',
            [$address, $since],
        );
    }

    public function deleteBefore(int $time): void
    {
        $statement = $this->database->prepare('DELETE FROM ' . $this->table . ' WHERE attempt_time < ?');
        $statement->execute([$time]);
    }

    /**
     * @param list<int|string> $values
     */
    private function count(string $sql, array $values): int
    {
        $statement = $this->database->prepare($sql);
        $statement->execute($values);
        $count = $statement->fetchColumn();
        if (!is_numeric($count)) {
            throw new RuntimeException('The sign-in attempts could not be counted.');
        }

        return (int) $count;
    }
}
