<?php

declare(strict_types=1);

namespace YooperGEDView\Security;

use InvalidArgumentException;
use PDO;

/**
 * The outstanding requests for a new password. Only a hash of each one-time token is kept, so that reading the
 * table does not let anyone use a link.
 */
final readonly class PasswordResetRepository
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
        $this->table = $tablePrefix . 'password_reset';
    }

    public function add(int $userId, string $tokenHash, int $createdTime): void
    {
        $this->database
            ->prepare('INSERT INTO ' . $this->table . ' (token_hash, user_id, created_time) VALUES (?, ?, ?)')
            ->execute([$tokenHash, $userId, $createdTime]);
    }

    /**
     * @return array{userId: int, createdTime: int}|null
     */
    public function find(string $tokenHash): ?array
    {
        $statement = $this->database->prepare(
            'SELECT user_id, created_time FROM ' . $this->table . ' WHERE token_hash = ?',
        );
        $statement->execute([$tokenHash]);
        $row = $statement->fetch(PDO::FETCH_NUM);
        if (!is_array($row) || !is_numeric($row[0]) || !is_numeric($row[1])) {
            return null;
        }

        return ['userId' => (int) $row[0], 'createdTime' => (int) $row[1]];
    }

    /**
     * When the user's newest request was made; 0 when there is none.
     */
    public function newestTimeForUser(int $userId): int
    {
        $statement = $this->database->prepare('SELECT MAX(created_time) FROM ' . $this->table . ' WHERE user_id = ?');
        $statement->execute([$userId]);

        return (int) $statement->fetchColumn();
    }

    public function deleteForUser(int $userId): void
    {
        $this->database->prepare('DELETE FROM ' . $this->table . ' WHERE user_id = ?')->execute([$userId]);
    }

    public function deleteBefore(int $time): void
    {
        $this->database->prepare('DELETE FROM ' . $this->table . ' WHERE created_time < ?')->execute([$time]);
    }
}
