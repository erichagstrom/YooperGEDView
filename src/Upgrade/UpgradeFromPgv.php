<?php

declare(strict_types=1);

namespace YooperGEDView\Upgrade;

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
    ) {}

    /**
     * @param bool $apply false to look only; true to make the changes
     *
     * @return list<string> the report, line by line
     */
    public function run(bool $apply): array
    {
        $done = static fn(string $would, string $did): string => $apply ? $did : $would;
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
            $report[] = '  ' . count($weak) . ' have a password stored the way PhpGedView stored it (MD5-crypt or older). Those no longer work here,';
            $report[] = '  and that form is weak enough to be worth removing from the copy. ' . $done('They would be removed.', 'They have been removed.');
            if ($apply) {
                foreach ($weak as $user) {
                    $this->site->removePassword($user['id']);
                }
            }
        }
        if ($otherFormat !== []) {
            $report[] = '  ' . count($otherFormat) . ' have a password stored in another form (bcrypt, for example), set by something other than PhpGedView.';
            $report[] = '  This program takes only its own form, so they no longer work here either. They are left as they are.';
        }
        if ($none !== []) {
            $report[] = '  ' . count($none) . ' have no password stored: nobody can sign in as them until one is set.';
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
            $report[] = '  ' . count($otherLanguage) . ' users chose a language other than English, the only one this program has. ' . $done('They would be set to English.', 'They have been set to English.');
            if ($apply) {
                foreach ($otherLanguage as $user) {
                    $this->site->setLanguage($user['id'], 'english');
                }
            }
        }

        $report[] = '';
        $report[] = 'Pending edits';
        array_push($report, ...$this->pendingEdits($apply));

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
        $report[] = $apply
            ? 'The changes above have been made. Start the program on this database and index folder.'
            : 'Nothing has been changed. Run again with --apply, on a copy of the site, to make the changes.';

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
