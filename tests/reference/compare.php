<?php

declare(strict_types=1);

// Usage: php compare.php <address of the reference site> <address of the moved site> <the moved site's administrator password>
// Requests the smoke test's pages from PhpGedView 4.3.0 (tests/reference/up.sh) and from this program running
// on the same data (tests/reference/moved.sh), as a visitor and as the administrator, and compares what a
// person would read on each: the words on the page, not its HTML. Every difference must be explained in
// differences.php, and every explanation there must still be needed. Anything else fails.
//
// What this is for: PHP 8 changed the meaning of some comparisons without raising any error, so that code
// written for PHP 5 quietly takes another branch. A page that then shows something else is how that is seen.

require __DIR__ . '/../browser.php';

const OLD_ADMIN_PASSWORD = 'test-admin-password';

/**
 * What differs between any two sites, or any two moments, and says nothing about the program: each is replaced
 * by a fixed word before the pages are compared. The site's own address is replaced too.
 */
const NOT_COMPARED = [
    // The time of day, and a full time stamp.
    '/\b\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d[+-]\d\d:\d\d\b/' => 'TIMESTAMP',
    '/\b\d{1,2}:\d\d:\d\d ?[ap]m\b/i' => 'TIME',
    // The made-up identifier each calendar entry gets when it is asked for.
    '/\bUID:PhpGedView-[0-9a-f-]{36}\b/' => 'UID',
    // Where on the server the site's index folder is.
    '~(?<=\s|^)/\S*/index/~' => 'INDEX/',
];

function fail(string $message): never
{
    fwrite(STDERR, 'compare: ' . $message . "\n");
    exit(2);
}

/**
 * The lines of text a person would read on a page, in order: no markup, scripts, styles or hidden fields.
 *
 * @return list<string>
 */
function readableLines(string $html, string $base): array
{
    if (trim($html) === '') {
        return [];
    }
    libxml_use_internal_errors(true);
    $document = new DOMDocument();
    $document->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors();
    $xpath = new DOMXPath($document);
    $hidden = $xpath->query('//script | //style | //noscript | //head | //input[@type="hidden"]');
    foreach ($hidden === false ? [] : iterator_to_array($hidden) as $node) {
        $node->parentNode?->removeChild($node);
    }
    // The text of buttons and the choices of lists are read too.
    $buttons = $xpath->query('//input[@type="submit" or @type="button"]');
    foreach ($buttons === false ? [] : iterator_to_array($buttons) as $node) {
        if ($node instanceof DOMElement) {
            $node->appendChild($document->createTextNode("\n[" . $node->getAttribute('value') . "]\n"));
        }
    }
    $breaks = $xpath->query('//br | //tr | //p | //div | //li | //td | //th | //option | //h1 | //h2 | //h3 | //table');
    foreach ($breaks === false ? [] : iterator_to_array($breaks) as $node) {
        $node->appendChild($document->createTextNode("\n"));
    }
    $lines = [];
    foreach (explode("\n", $document->textContent) as $line) {
        $line = trim((string) preg_replace('/[\s\x{00A0}]+/u', ' ', $line));
        $line = (string) preg_replace(array_keys(NOT_COMPARED), array_values(NOT_COMPARED), str_replace($base, 'SITE/', $line));
        if ($line !== '') {
            $lines[] = $line;
        }
    }

    return $lines;
}

/**
 * The lines one page has that the other lacks, each as often as it has more of them.
 *
 * @param list<string> $lines
 * @param list<string> $others
 *
 * @return list<string>
 */
function onlyIn(array $lines, array $others): array
{
    $left = array_count_values($others);
    $only = [];
    foreach ($lines as $line) {
        if (($left[$line] ?? 0) > 0) {
            $left[$line]--;
        } else {
            $only[] = $line;
        }
    }

    return $only;
}

/**
 * Some lines of a page, each with the two lines that come before it, to show where on the page it is.
 *
 * @param list<string> $lines the page
 * @param list<string> $only the lines of it to show
 */
function inContext(string $mark, array $lines, array $only): string
{
    $text = '';
    $wanted = array_count_values($only);
    foreach ($lines as $number => $line) {
        if (($wanted[$line] ?? 0) > 0 && strlen($text) < 4000) {
            $wanted[$line]--;
            $text .= '      ' . mb_substr(($lines[$number - 2] ?? '') . ' / ' . ($lines[$number - 1] ?? '(top of page)'), 0, 160) . "\n    " . $mark . ' ' . mb_substr($line, 0, 200) . "\n";
        }
    }

    return $text;
}

function signIn(string $base, string $cookieJar, string $password): void
{
    $form = formDefaults(request($base . 'login.php?url=index.php', $cookieJar), 'loginform');
    $form['fields']['username'] = 'admin';
    $form['fields']['password'] = $password;
    if (!str_contains(request($base . 'login.php', $cookieJar, $form['fields']), 'logout=1')) {
        fail('could not sign in to ' . $base);
    }
}

/**
 * @return list<string>
 */
function pages(string $file): array
{
    $lines = file(__DIR__ . '/../' . $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    return $lines === false ? fail('cannot read ' . $file) : array_values(array_filter($lines, static fn(string $line): bool => !str_starts_with($line, '#')));
}

if ($argc !== 4) {
    fail('usage: php compare.php <reference site> <moved site> <moved administrator password>');
}
$old = rtrim($argv[1], '/') . '/';
$new = rtrim($argv[2], '/') . '/';
/** @var list<array{side: 'old'|'new', page?: string, line: string, why: string}> $explained */
$explained = require __DIR__ . '/differences.php';
$used = array_fill(0, count($explained), false);

$jars = [];
foreach (['old-visitor', 'new-visitor', 'old-admin', 'new-admin'] as $name) {
    $jar = tempnam(sys_get_temp_dir(), 'compare');
    $jars[$name] = $jar === false ? fail('cannot create a cookie file') : $jar;
}
signIn($old, $jars['old-admin'], OLD_ADMIN_PASSWORD);
signIn($new, $jars['new-admin'], $argv[3]);

$visits = [];
foreach (pages('pages-visitor.txt') as $page) {
    $visits[] = ['visitor', $page];
}
foreach (array_merge(pages('pages-visitor.txt'), pages('pages-admin.txt')) as $page) {
    $visits[] = ['admin', $page];
}

// Both programs work some things out the first time a page needs them and keep the answer, such as whether a
// person is taken to be alive. So every page is asked for once on both sites before any is compared: otherwise
// the site that has been looked at more knows more, and its statistics differ.
foreach ($visits as [$who, $page]) {
    request($old . $page, $jars['old-' . $who]);
    request($new . $page, $jars['new-' . $who]);
}

/** @var array<string, list<string>> $unexplained difference => the visits it was seen on */
$unexplained = [];
/** @var array<string, string> $context visit => its unexplained differences, each with the line before it */
$context = [];
$same = 0;
foreach ($visits as [$who, $page]) {
    $oldLines = readableLines(request($old . $page, $jars['old-' . $who]), $old);
    $newLines = readableLines(request($new . $page, $jars['new-' . $who]), $new);
    $differences = array_merge(
        array_map(static fn(string $line): array => ['old', $line], onlyIn($oldLines, $newLines)),
        array_map(static fn(string $line): array => ['new', $line], onlyIn($newLines, $oldLines)),
    );
    $left = ['old' => [], 'new' => []];
    foreach ($differences as [$side, $line]) {
        foreach ($explained as $number => $rule) {
            if ($rule['side'] === $side && preg_match($rule['line'], $line) === 1 && (!isset($rule['page']) || preg_match($rule['page'], $page) === 1)) {
                $used[$number] = true;

                continue 2;
            }
        }
        $left[$side][] = $line;
        $unexplained[($side === 'old' ? 'only PhpGedView shows: ' : 'only this program shows: ') . mb_substr($line, 0, 200)][] = $who . ' ' . $page;
    }
    if ($left === ['old' => [], 'new' => []]) {
        $same++;
    } elseif (count($context) < 12) {
        $context[$who . ' ' . $page] = inContext('-', $oldLines, $left['old']) . inContext('+', $newLines, $left['new']);
    }
}
array_map(unlink(...), $jars);

uasort($unexplained, static fn(array $a, array $b): int => count($b) <=> count($a));
foreach ($unexplained as $difference => $seenOn) {
    echo 'DIFF  ', $difference, "\n      on ", count($seenOn), ' of ', count($visits), ', such as: ', implode(' | ', array_slice($seenOn, 0, 2)), "\n";
}
foreach ($context as $visit => $text) {
    echo "\n--- ", $visit, ": the lines that differ, each after the two lines before it (- PhpGedView, + this program)\n", $text;
}
$stale = 0;
foreach ($explained as $number => $rule) {
    if (!$used[$number]) {
        $stale++;
        echo 'STALE an explanation in differences.php is no longer needed: ', $rule['line'], ' (', $rule['why'], ")\n";
    }
}
echo count($visits), ' pages compared: ', $same, ' read the same or differ only as explained, ', count($unexplained), ' unexplained differences, ', $stale, " explanations no longer needed\n";
exit($unexplained === [] && $stale === 0 ? 0 : 1);
