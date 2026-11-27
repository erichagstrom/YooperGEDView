<?php

declare(strict_types=1);

// Usage: php provision.php <base-url> <site-dir>
// Turns a freshly started test site (tests/serve.sh) into a usable one: creates the administrator and imports
// tests/fixtures/sample.ged through the program's own pages, the way a person would.
// Test credentials only: user "admin", password "test-admin-password". A second user, "legacy", has the same
// password stored the way PhpGedView 4.3.0 stored it (MD5-crypt), which must no longer be accepted.

use YooperGEDView\Security\PasswordHasher;

require __DIR__ . '/../vendor/autoload.php';

const ADMIN_USER = 'admin';
const ADMIN_PASSWORD = 'test-admin-password';
const LEGACY_USER = 'legacy';
const TREE_FILE = 'sample.ged';
const USER_AGENT = 'Mozilla/5.0 (X11; Linux x86_64) Gecko/20100101 Firefox/100.0';

function fail(string $message): never
{
    fwrite(STDERR, 'provision: ' . $message . "\n");
    exit(1);
}

/**
 * @param array<string, string>|null $post
 */
function request(string $url, string $cookieJar, ?array $post = null): string
{
    $curl = curl_init($url);
    if ($curl === false) {
        fail('cannot start a request to ' . $url);
    }
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_COOKIEJAR => $cookieJar,
        CURLOPT_COOKIEFILE => $cookieJar,
        CURLOPT_USERAGENT => USER_AGENT,
        CURLOPT_TIMEOUT => 120,
    ]);
    if ($post !== null) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $body = curl_exec($curl);
    if (!is_string($body)) {
        fail('no answer from ' . $url . ': ' . curl_error($curl));
    }
    if (preg_match('/(Fatal error|Uncaught \w+): .{0,200}/', $body, $match) === 1) {
        fail($url . ' answered with ' . $match[0]);
    }

    return $body;
}

/**
 * The fields a browser would send for the named form, with their default values.
 *
 * @return array{action: string, fields: array<string, string>}
 */
function formDefaults(string $html, string $formName): array
{
    libxml_use_internal_errors(true);
    $document = new DOMDocument();
    $document->loadHTML($html);
    libxml_clear_errors();
    $xpath = new DOMXPath($document);
    $forms = $xpath->query(sprintf("//form[@name='%s']", $formName));
    $form = $forms === false ? null : $forms->item(0);
    if (!$form instanceof DOMElement) {
        fail('form "' . $formName . '" not found');
    }

    $fields = [];
    $controls = $xpath->query('.//input|.//select|.//textarea', $form);
    foreach ($controls === false ? [] : $controls as $control) {
        if (!$control instanceof DOMElement || $control->getAttribute('name') === '') {
            continue;
        }
        $name = $control->getAttribute('name');
        $type = strtolower($control->getAttribute('type'));
        if ($control->nodeName === 'select') {
            $options = $xpath->query('.//option[@selected]', $control);
            $option = $options === false ? null : $options->item(0);
            if (!$option instanceof DOMElement) {
                $options = $xpath->query('.//option', $control);
                $option = $options === false ? null : $options->item(0);
            }
            if ($option instanceof DOMElement) {
                $fields[$name] = $option->hasAttribute('value') ? $option->getAttribute('value') : $option->textContent;
            }
        } elseif ($control->nodeName === 'textarea') {
            $fields[$name] = $control->textContent;
        } elseif (in_array($type, ['submit', 'button', 'image', 'file', 'reset'], true)) {
            continue;
        } elseif (!in_array($type, ['checkbox', 'radio'], true) || $control->hasAttribute('checked')) {
            $fields[$name] = $control->getAttribute('value');
        }
    }

    return ['action' => $form->getAttribute('action'), 'fields' => $fields];
}

function createAdministrator(PDO $database, string $name, string $passwordHash): void
{
    $database->prepare('INSERT INTO pgv_user (user_name, password) VALUES (?, ?)')->execute([$name, $passwordHash]);
    $userId = $database->lastInsertId();
    $settings = [
        'firstname' => 'Test',
        'lastname' => ucfirst($name),
        'canadmin' => 'Y',
        'email' => $name . '@example.com',
        'verified' => 'yes',
        'verified_by_admin' => 'yes',
        'language' => 'english',
        'contactmethod' => 'messaging2',
        'visibleonline' => 'Y',
        'editaccount' => 'Y',
        'defaulttab' => '0',
        'sync_gedcom' => 'N',
        'relationship_privacy' => 'N',
        'max_relation_path' => '2',
        'auto_accept' => 'N',
    ];
    $insert = $database->prepare('INSERT INTO pgv_user_setting (user_id, setting_name, setting_value) VALUES (?, ?, ?)');
    foreach ($settings as $name => $value) {
        $insert->execute([$userId, $name, $value]);
    }
}

function treeIsImported(PDO $database): bool
{
    $statement = $database->query("SELECT setting_value FROM pgv_gedcom_setting WHERE setting_name = 'imported'");

    return $statement !== false && (bool) $statement->fetchColumn();
}

if ($argc !== 3) {
    fail('usage: php provision.php <base-url> <site-dir>');
}
$baseUrl = rtrim($argv[1], '/') . '/';
$siteDir = rtrim($argv[2], '/');
$cookieJar = tempnam(sys_get_temp_dir(), 'provision');
if ($cookieJar === false) {
    fail('cannot create a cookie file');
}

// The first request makes the program create its tables.
request($baseUrl . 'index.php', $cookieJar);
$database = new PDO('sqlite:' . $siteDir . '/index/test.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
createAdministrator($database, ADMIN_USER, (new PasswordHasher())->hash(ADMIN_PASSWORD));
createAdministrator($database, LEGACY_USER, crypt(ADMIN_PASSWORD, '$1$padpadpa$'));

if (!copy(__DIR__ . '/fixtures/' . TREE_FILE, $siteDir . '/index/' . TREE_FILE)) {
    fail('cannot copy the sample tree');
}

request($baseUrl . 'login.php', $cookieJar);
request($baseUrl . 'login.php', $cookieJar, [
    'action' => 'login',
    'username' => ADMIN_USER,
    'password' => ADMIN_PASSWORD,
    'url' => 'editgedcoms.php',
]);

// "Add GEDCOM": the tree's settings form, then the import pages until the program reports the tree as imported.
$form = formDefaults(request($baseUrl . 'editconfig_gedcom.php?source=add_form', $cookieJar), 'configform');
$form['fields']['GEDCOMPATH'] = TREE_FILE;
$form['fields']['gedcom_title'] = 'Sample tree';
$page = request($baseUrl . $form['action'], $cookieJar, $form['fields']);
for ($step = 0; $step < 10 && !treeIsImported($database); $step++) {
    $form = formDefaults($page, 'configform');
    $page = request($baseUrl . $form['action'], $cookieJar, $form['fields']);
}
unlink($cookieJar);

if (!treeIsImported($database)) {
    fail('the sample tree was not imported');
}
$people = $database->query('SELECT COUNT(*) FROM pgv_individuals');
echo 'provisioned: ' . ($people === false ? '?' : (string) $people->fetchColumn()) . " people imported\n";
