<?php

declare(strict_types=1);

namespace YooperGEDView\User;

use InvalidArgumentException;
use PDO;

/**
 * Reads and changes existing accounts in the inherited user tables.
 */
final readonly class UserRepository
{
    private string $userTable;

    private string $settingTable;

    /**
     * @param string $tablePrefix the site's table prefix, from its settings; never from a request
     */
    public function __construct(private PDO $database, string $tablePrefix)
    {
        if (preg_match('/^[A-Za-z0-9_]*$/', $tablePrefix) !== 1) {
            throw new InvalidArgumentException('A table prefix may hold only letters, digits and underscores.');
        }
        $this->userTable = $tablePrefix . 'user';
        $this->settingTable = $tablePrefix . 'user_setting';
    }

    /**
     * The id of the user with this name; null when there is none.
     */
    public function idOf(string $userName): ?int
    {
        $statement = $this->database->prepare('SELECT user_id FROM ' . $this->userTable . ' WHERE user_name = ?');
        $statement->execute([$userName]);
        $id = $statement->fetchColumn();

        return is_numeric($id) ? (int) $id : null;
    }

    public function setPasswordHash(int $userId, string $passwordHash): void
    {
        $this->database
            ->prepare('UPDATE ' . $this->userTable . ' SET password = ? WHERE user_id = ?')
            ->execute([$passwordHash, $userId]);
    }

    /**
     * Sets one of the user's settings, whether or not it had a value before.
     */
    public function setSetting(int $userId, string $name, string $value): void
    {
        $this->database
            ->prepare('DELETE FROM ' . $this->settingTable . ' WHERE user_id = ? AND setting_name = ?')
            ->execute([$userId, $name]);
        $this->database
            ->prepare('INSERT INTO ' . $this->settingTable . ' (user_id, setting_name, setting_value) VALUES (?, ?, ?)')
            ->execute([$userId, $name, $value]);
    }
}
