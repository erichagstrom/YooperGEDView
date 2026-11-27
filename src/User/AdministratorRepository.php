<?php

declare(strict_types=1);

namespace YooperGEDView\User;

use InvalidArgumentException;
use PDO;
use PDOException;

/**
 * Creates the site's administrators in the inherited user tables.
 */
final readonly class AdministratorRepository
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
     * The program creates its tables the first time a page is asked for. False until then.
     */
    public function tablesExist(): bool
    {
        try {
            $this->database->query('SELECT 1 FROM ' . $this->userTable . ' WHERE 1 = 0');
            $this->database->query('SELECT 1 FROM ' . $this->settingTable . ' WHERE 1 = 0');
        } catch (PDOException) {
            return false;
        }

        return true;
    }

    public function administratorExists(): bool
    {
        $statement = $this->database->prepare(
            'SELECT COUNT(*) FROM ' . $this->settingTable . ' WHERE setting_name = ? AND setting_value = ?',
        );
        $statement->execute(['canadmin', 'Y']);

        return (int) $statement->fetchColumn() > 0;
    }

    public function userNameIsTaken(string $userName): bool
    {
        $statement = $this->database->prepare('SELECT COUNT(*) FROM ' . $this->userTable . ' WHERE user_name = ?');
        $statement->execute([$userName]);

        return (int) $statement->fetchColumn() > 0;
    }

    /**
     * Adds a verified administrator with the same settings the old web installer gave its first user.
     */
    public function create(Administrator $administrator, string $passwordHash, int $now): void
    {
        $this->database->beginTransaction();
        try {
            $this->database
                ->prepare('INSERT INTO ' . $this->userTable . ' (user_name, password) VALUES (?, ?)')
                ->execute([$administrator->userName, $passwordHash]);
            $find = $this->database->prepare('SELECT user_id FROM ' . $this->userTable . ' WHERE user_name = ?');
            $find->execute([$administrator->userName]);
            $userId = (int) $find->fetchColumn();
            $settings = [
                'firstname' => $administrator->firstName,
                'lastname' => $administrator->lastName,
                'email' => $administrator->email,
                'canadmin' => 'Y',
                'verified' => 'yes',
                'verified_by_admin' => 'yes',
                'language' => 'english',
                'reg_timestamp' => (string) $now,
                'loggedin' => 'N',
                'sessiontime' => '0',
                'contactmethod' => 'messaging2',
                'visibleonline' => 'Y',
                'editaccount' => 'Y',
                'defaulttab' => '0',
                'sync_gedcom' => 'N',
                'relationship_privacy' => 'N',
                'max_relation_path' => '2',
                'auto_accept' => 'N',
            ];
            $insert = $this->database->prepare(
                'INSERT INTO ' . $this->settingTable . ' (user_id, setting_name, setting_value) VALUES (?, ?, ?)',
            );
            foreach ($settings as $name => $value) {
                $insert->execute([$userId, $name, $value]);
            }
            $this->database->commit();
        } catch (PDOException $exception) {
            $this->database->rollBack();

            throw $exception;
        }
    }
}
