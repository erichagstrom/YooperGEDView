<?php

declare(strict_types=1);

// The differences between what PhpGedView 4.3.0 shows and what this program shows on the same data that are
// meant, each with its reason. tests/reference/compare.php fails on any difference not listed here, and on any
// entry here that no longer matches anything.
//
// side: "old" for a line only PhpGedView shows, "new" for one only this program shows.
// line: a pattern the line must match. page (optional): a pattern the page's address must match.

return [
    // Meant changes of this program.
    ['side' => 'new', 'page' => '~^login\.php~', 'line' => '~^(Account Information|Lost your password\?|Request new password)$~',
        'why' => 'The lost-password link is shown also when visitors may not ask for an account; on a moved site it is how users set a password.'],
    ['side' => 'old', 'page' => '~^admin\.php~', 'line' => '~^(PHP information|Configuration help|Configuration|Cleanup Index directory|User Information Migration tool|Backup)$~',
        'why' => 'Pages that showed the server\'s settings, or changed and copied files on the server from the browser, were removed.'],
    ['side' => 'old', 'page' => '~^admin\.php~', 'line' => '~^(Configure supported languages|You have not configured the languages your site will support\.|PhpGedView will use its default actions\.|Add files and settings for a new language|Translator tools)$~',
        'why' => 'English is the only language; the pages for managing languages were removed.'],
    ['side' => 'old', 'page' => '~^useradmin\.php~', 'line' => '~^(German|1)$~',
        'why' => 'The reference site has one user who chose German. The move sets them to English, so the list shows no German and one count fewer.'],
    ['side' => 'new', 'page' => '~^useradmin\.php~', 'line' => '~^(English|2)$~',
        'why' => 'The other half of the same: both users now have English.'],

    // The old release fails on these pages and shows its own error text; this program does not.
    ['side' => 'old', 'page' => '~^medialist\.php\?action=filter$~', 'line' => '~usort\(\)|^\d Error occurred on|^\d called from line~',
        'why' => 'PhpGedView sorts a list it never made when no media matches.'],
    ['side' => 'old', 'page' => '~^individual\.php\?action=ajax&pid=I1&tab=(10)?$~', 'line' => '~Undefined offset|^\d Error occurred on|^\d called from line|Call to undefined method~',
        'why' => 'PhpGedView fails when asked for a tab that does not exist.'],
    ['side' => 'new', 'page' => '~^individual\.php\?action=ajax&pid=I1&tab=10$~', 'line' => '~^No tab found$~',
        'why' => 'What this program says instead of failing.'],

    // The two test sites, not the two programs.
    ['side' => 'new', 'page' => '~^admin\.php~', 'line' => '~^Your config\.php file is still writable\.~',
        'why' => 'The moved site runs from a throw-away copy that the test may write to; the reference site\'s files belong to root.'],
];
