<?php

declare(strict_types=1);

namespace YooperGEDView\Upgrade;

use InvalidArgumentException;
use PDO;
use PDOException;

/**
 * What the upgrade needs to read and change in the tables of a site that ran PhpGedView.
 */
final readonly class LegacySiteRepository
{
    /** Stored in place of an old password hash: nothing typed can ever match it. */
    public const string NO_PASSWORD = '*';

    /**
     * @param string $prefix the site's table prefix, from its settings; never from a request
     */
    public function __construct(private PDO $database, private string $prefix)
    {
        if (preg_match('/^[A-Za-z0-9_]*$/', $prefix) !== 1) {
            throw new InvalidArgumentException('A table prefix may hold only letters, digits and underscores.');
        }
    }

    /**
     * The version of the table layout the old program recorded; null when it recorded none.
     */
    public function schemaVersion(): ?int
    {
        try {
            $statement = $this->database->prepare(
                'SELECT site_setting_value FROM ' . $this->prefix . 'site_setting WHERE site_setting_name = ?',
            );
            $statement->execute(['PGV_SCHEMA_VERSION']);
            $version = $statement->fetchColumn();
        } catch (PDOException) {
            return null;
        }

        return is_numeric($version) ? (int) $version : null;
    }

    /**
     * Every account, with the settings the upgrade looks at.
     *
     * @return list<array{id: int, name: string, hash: string, email: string, administrator: bool, language: string}>
     */
    public function users(): array
    {
        $settings = [];
        foreach ($this->rows('SELECT user_id, setting_name, setting_value FROM ' . $this->prefix . "user_setting WHERE setting_name IN ('email', 'canadmin', 'language')") as [$userId, $name, $value]) {
            $settings[$userId][$name] = $value;
        }
        $users = [];
        foreach ($this->rows('SELECT user_id, user_name, password FROM ' . $this->prefix . 'user ORDER BY user_name') as [$userId, $name, $hash]) {
            $own = $settings[$userId] ?? [];
            $users[] = [
                'id' => (int) $userId,
                'name' => $name,
                'hash' => $hash,
                'email' => $own['email'] ?? '',
                'administrator' => ($own['canadmin'] ?? '') === 'Y',
                'language' => $own['language'] ?? '',
            ];
        }

        return $users;
    }

    public function removePassword(int $userId): void
    {
        $this->database
            ->prepare('UPDATE ' . $this->prefix . 'user SET password = ? WHERE user_id = ?')
            ->execute([self::NO_PASSWORD, $userId]);
    }

    public function setLanguage(int $userId, string $language): void
    {
        $this->database
            ->prepare('UPDATE ' . $this->prefix . "user_setting SET setting_value = ? WHERE user_id = ? AND setting_name = 'language'")
            ->execute([$language, $userId]);
    }

    /**
     * Links to records on other PhpGedView sites, a feature this program no longer has.
     */
    public function remoteLinkCount(): int
    {
        try {
            $count = $this->database->query('SELECT COUNT(*) FROM ' . $this->prefix . 'remotelinks');
        } catch (PDOException) {
            return 0;
        }

        return $count === false ? 0 : (int) $count->fetchColumn();
    }

    /**
     * The rows of a query that selects three columns, each value as text.
     *
     * @return list<array{string, string, string}>
     */
    private function rows(string $sql): array
    {
        $statement = $this->database->query($sql);
        $rows = [];
        while ($statement !== false && is_array($row = $statement->fetch(PDO::FETCH_NUM))) {
            $values = array_map(static fn(mixed $value): string => is_scalar($value) ? (string) $value : '', $row);
            $rows[] = [$values[0] ?? '', $values[1] ?? '', $values[2] ?? ''];
        }

        return $rows;
    }
}
