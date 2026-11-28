<?php

declare(strict_types=1);

namespace YooperGEDView\Upgrade;

use PDOException;
use YooperGEDView\Security\PasswordHasher;

/**
 * Looks over a copy of a PhpGedView 4.3.0 site, says what moving it to this program involves, and, when told
 * to, makes the changes that have to be made before the program is started on it. It works on the database
 * and on the site's "index" folder. It never runs a file from the old site.
 */
final readonly class UpgradeFromPgv
{
    /** The table layout version of PhpGedView 4.3.0. */
    private const int EXPECTED_SCHEMA = 14;

    /** Files PhpGedView kept in the index folder that this program has no use for. */
    private const array UNUSED_FILES = ['lang_settings.php', 'banned.php', 'search_engines.php', 'gedcoms.php', 'authenticate.php'];

    public function __construct(
        private LegacySiteRepository $site,
        private string $indexFolder,
        private PendingChangesFile $pendingChanges = new PendingChangesFile(),
        private PhpFileInspector $inspector = new PhpFileInspector(),
        private PasswordHasher $hasher = new PasswordHasher(),
        private ?CharacterSetConverter $characterSets = null,
    ) {}

    /**
     * @param bool $apply false to look only; true to make the changes
     *
     * @return list<string> the report, line by line
     */
    public function run(bool $apply): array
    {
        $done = static fn(string $would, string $did): string => $apply ? $did : $would;
        $has = static fn(int $count): string => $count . ($count === 1 ? ' has' : ' have');
        $report = [];

        $report[] = 'Database';
        $version = $this->site->schemaVersion();
        if ($version === null) {
            $report[] = '  ! No table layout version is recorded. Is this the database of a PhpGedView site?';
        } elseif ($version < self::EXPECTED_SCHEMA) {
            $report[] = '  ! The tables are at version ' . $version . ', older than PhpGedView 4.3.0 (' . self::EXPECTED_SCHEMA . '). Open the old site once on 4.3.0 first, so that it brings them up to date.';
        } else {
            $report[] = '  The tables are at version ' . $version . '. This program brings them up to date itself, the first time a page is opened.';
        }

        $users = $this->site->users();
        $weak = array_values(array_filter($users, fn(array $user): bool => $this->kindOfPassword($user['hash']) === 'weak'));
        $otherFormat = array_values(array_filter($users, fn(array $user): bool => $this->kindOfPassword($user['hash']) === 'other'));
        $none = array_values(array_filter($users, fn(array $user): bool => $this->kindOfPassword($user['hash']) === 'none'));
        $report[] = '';
        $report[] = 'Accounts';
        $report[] = '  ' . count($users) . ' in all.';
        if ($weak !== []) {
            $report[] = '  ' . $has(count($weak)) . ' a password stored the way PhpGedView stored it (MD5-crypt or older). Those no longer work here,';
            $report[] = '  and that form is weak enough to be worth removing from the copy. ' . $done('They would be removed.', 'They have been removed.');
            if ($apply) {
                foreach ($weak as $user) {
                    $this->site->removePassword($user['id']);
                }
            }
        }
        if ($otherFormat !== []) {
            $report[] = '  ' . $has(count($otherFormat)) . ' a password stored in another form (bcrypt, for example), set by something other than PhpGedView.';
            $report[] = '  This program takes only its own form, so they no longer work here either. They are left as they are.';
        }
        if ($none !== []) {
            $report[] = '  ' . $has(count($none)) . ' no password stored: nobody can sign in as them until one is set.';
        }
        $withoutPassword = array_values(array_filter($users, fn(array $user): bool => !$this->hasher->accepts($user['hash'])));
        if ($withoutPassword !== []) {
            $report[] = '  Each of these users needs a new password before they can sign in:';
            foreach ($withoutPassword as $user) {
                $how = $user['email'] === '' ? 'no mail address: bin/yooper set-password' : 'by the "lost password" link on the sign-in page';
                $was = $this->kindOfPassword($user['hash']) === 'none' ? ' (no password stored)' : '';
                $report[] = '    ' . $user['name'] . ($user['administrator'] ? ' (administrator)' : '') . $was . ': ' . $how;
            }
            $report[] = '  An administrator can always be given one with: bin/yooper set-password <user-name>';
        }

        $otherLanguage = array_values(array_filter($users, static fn(array $user): bool => $user['language'] !== '' && $user['language'] !== 'english'));
        if ($otherLanguage !== []) {
            $report[] = '';
            $report[] = 'Languages';
            $report[] = '  ' . count($otherLanguage) . (count($otherLanguage) === 1 ? ' user' : ' users') . ' chose a language other than English, the only one this program has. ' . $done('They would be set to English.', 'They have been set to English.');
            if ($apply) {
                foreach ($otherLanguage as $user) {
                    $this->site->setLanguage($user['id'], 'english');
                }
            }
        }

        $report[] = '';
        $report[] = 'Pending edits';
        $pendingEdits = $this->pendingEdits($apply);
        array_push($report, ...$pendingEdits);
        $changed = $apply && ($weak !== [] || $otherLanguage !== [] || str_contains(implode("\n", $pendingEdits), 'have been carried over'));

        $characterSets = $this->characterSets($apply);
        if ($characterSets !== []) {
            $report[] = '';
            $report[] = 'Text in the database';
            array_push($report, ...$characterSets);
            $changed = $changed || $apply;
        }

        $report[] = '';
        $report[] = 'Settings files of the trees';
        $settingsFiles = array_merge($this->filesMatching('*_conf.php'), $this->filesMatching('*_priv.php'));
        if ($settingsFiles === []) {
            $report[] = '  None found in ' . $this->indexFolder . '.';
        }
        foreach ($settingsFiles as $file) {
            $findings = $this->inspector->findings((string) file_get_contents($file));
            if ($findings === []) {
                $report[] = '  ' . basename($file) . ': settings only.';
            } else {
                $report[] = '  ! ' . basename($file) . ' holds more than settings. This program loads the file, so read it before going on:';
                foreach ($findings as $finding) {
                    $report[] = '      ' . $finding;
                }
            }
        }

        $unused = array_values(array_filter(self::UNUSED_FILES, fn(string $name): bool => is_file($this->indexFolder . '/' . $name)));
        $remoteLinks = $this->site->remoteLinkCount();
        if ($unused !== [] || $remoteLinks > 0) {
            $report[] = '';
            $report[] = 'No longer used';
            if ($unused !== []) {
                $report[] = '  These files in the index folder can be deleted: ' . implode(', ', $unused) . '.';
            }
            if ($remoteLinks > 0) {
                $report[] = '  ' . $remoteLinks . ' links to records on other PhpGedView sites. That feature is gone; the links are left in the database.';
            }
        }

        $report[] = '';
        $report[] = match (true) {
            !$apply => 'Nothing has been changed. Run again with --apply, on a copy of the site, to make the changes.',
            $changed => 'The changes above have been made. Start the program on this database and index folder.',
            default => 'Nothing needed changing. The program can be started on this database and index folder.',
        };

        return $report;
    }

    /**
     * What is stored as a user's password: "accepted" (this program's own form), "weak" (MD5-crypt, as
     * PhpGedView 4.3.0 wrote it, or the still older DES, MD5 and SHA-1 forms), "other" (another form of hash,
     * such as bcrypt), or "none" (a placeholder: nothing typed could ever have matched it).
     *
     * @return 'accepted'|'weak'|'other'|'none'
     */
    private function kindOfPassword(string $stored): string
    {
        if ($this->hasher->accepts($stored)) {
            return 'accepted';
        }
        if (str_starts_with($stored, '$1$') || preg_match('~^(?:[./0-9A-Za-z]{13}|[0-9a-f]{32}|[0-9a-f]{40})$~', $stored) === 1) {
            return 'weak';
        }

        return str_starts_with($stored, '$') && strlen($stored) >= 20 ? 'other' : 'none';
    }

    /**
     * @return list<string> nothing when every table already holds its text as utf8mb4, or the database has no such thing
     */
    private function characterSets(bool $apply): array
    {
        if ($this->characterSets === null || !$this->characterSets->applies()) {
            return [];
        }
        $plan = $this->characterSets->plan();
        $databaseCollation = $this->characterSets->databaseCollation();
        $lines = [];
        if ($plan !== []) {
            $relabelled = array_sum(array_map(static fn(array $entry): int => count($entry['relabel']), $plan));
            $rows = array_sum(array_map(static fn(array $entry): int => $entry['nonAscii'], $plan));
            $lines[] = '  ' . count($plan) . (count($plan) === 1 ? ' table holds' : ' tables hold') . ' text in a character set other than utf8mb4, the one this program uses'
                . ' (' . implode(', ', array_unique(array_map(static fn(array $entry): string => $entry['collation'], $plan))) . ').';
            $lines[] = '  Until they are converted, some pages fail, the lists by surname letter among them. ' . ($apply ? 'Converting:' : 'They would be converted.');
            $lines[] = '  ' . $rows . ($rows === 1 ? ' value has' : ' values have') . ' letters outside plain ASCII; all other text reads the same either way.';
            if ($relabelled > 0) {
                $lines[] = '  In ' . $relabelled . ($relabelled === 1 ? ' column' : ' columns') . ' those letters are UTF-8 that the old site stored under the wrong label. They are kept byte for byte and given the right label.';
            }
        }
        $failed = 0;
        foreach ($plan as $entry) {
            if ($entry['mixed'] !== []) {
                $failed++;
                $lines[] = '  ! ' . $entry['table'] . ' is left alone: ' . implode(', ', $entry['mixed']) . ' holds UTF-8 in some rows and another character set in others.';
                $lines[] = '    No rule can tell which is right. Correct those rows on the old site, copy it again, and run this again.';

                continue;
            }
            if (!$apply) {
                continue;
            }
            try {
                $this->characterSets->convert($entry);
            } catch (PDOException $exception) {
                $failed++;
                $lines[] = '  ! ' . $entry['table'] . ' could not be converted and is as it was: ' . $exception->getMessage();
            }
        }
        if ($apply && $plan !== []) {
            $lines[] = '  ' . (count($plan) - $failed) . ' of ' . count($plan) . ' have been converted to ' . $plan[0]['target'] . '.';
        }
        if ($databaseCollation !== '' && !str_starts_with($databaseCollation, 'utf8mb4')) {
            if (!$apply) {
                $lines[] = '  New tables in this database get ' . $databaseCollation . '. That would be changed to utf8mb4 as well.';
            } else {
                try {
                    $this->characterSets->convertDatabase();
                    $lines[] = '  New tables in this database now get utf8mb4 as well.';
                } catch (PDOException $exception) {
                    $lines[] = '  ! New tables in this database still get ' . $databaseCollation . ': ' . $exception->getMessage();
                }
            }
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function pendingEdits(bool $apply): array
    {
        $old = $this->indexFolder . '/pgv_changes.php';
        $new = $this->indexFolder . '/pgv_changes.json';
        if (!is_file($old)) {
            return ['  No pgv_changes.php: nothing to carry over.'];
        }
        if (is_file($new)) {
            return ['  pgv_changes.json is already there, so pgv_changes.php is left alone. It can be deleted.'];
        }
        try {
            $changes = $this->pendingChanges->read((string) file_get_contents($old));
        } catch (UnreadableFile $exception) {
            return [
                '  ! pgv_changes.php is not as PhpGedView writes it, and is NOT carried over: ' . $exception->getMessage(),
                '    PhpGedView ran this file on every page. Read it: it may show that someone put code into the old site.',
            ];
        }
        $count = array_sum(array_map(count(...), $changes));
        if (!$apply) {
            return ['  ' . $count . ' pending changes to ' . count($changes) . ' records would be carried over to pgv_changes.json.'];
        }
        $json = json_encode($changes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false || file_put_contents($new, $json) === false || !rename($old, $old . '.carried-over')) {
            return ['  ! The pending changes could not be written to ' . $new . '. Is the folder writable?'];
        }

        return ['  ' . $count . ' pending changes to ' . count($changes) . ' records have been carried over to pgv_changes.json. The old file is now pgv_changes.php.carried-over.'];
    }

    /**
     * @return list<string>
     */
    private function filesMatching(string $pattern): array
    {
        $files = glob($this->indexFolder . '/' . $pattern);

        return $files === false ? [] : array_values(array_filter($files, is_file(...)));
    }
}
