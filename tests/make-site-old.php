<?php

declare(strict_types=1);

// Usage: php make-site-old.php <sqlite-file> <index-folder>
// Makes the test site look as PhpGedView 4.3.0 would have left it, for trying "bin/yooper upgrade-from-pgv":
// every password stored as MD5-crypt (of "the-old-password"), every user set to German, and the pending
// edits written as the PHP file PhpGedView kept them in.

if ($argc !== 3 || !is_dir($argv[2])) {
    fwrite(STDERR, "usage: php make-site-old.php <sqlite-file> <index-folder>\n");
    exit(1);
}
$database = new PDO('sqlite:' . $argv[1], null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$database->prepare('UPDATE pgv_user SET password = ?')->execute([crypt('the-old-password', '$1$padpadpa$')]);
$database->exec("UPDATE pgv_user_setting SET setting_value = 'german' WHERE setting_name = 'language'");
$database->exec("UPDATE pgv_site_setting SET site_setting_value = '14' WHERE site_setting_name = 'PGV_SCHEMA_VERSION'");

$changes = <<<'PHP'
    <?php
    $pgv_changes = array();
    $pgv_changes["I3_sample.ged"] = array();
    // Start of change record.
    $change = array();
    $change["gid"] = 'I3';
    $change["gedcom"] = 'sample.ged';
    $change["type"] = 'replace';
    $change["status"] = 'submitted';
    $change["user"] = 'admin';
    $change["time"] = '1700000000';
    $change["undo"] = '0 @I3@ INDI
    1 NAME Carried /Over/
    1 SEX F';
    // End of change record.
    $pgv_changes["I3_sample.ged"][] = $change;
    ?>
    PHP;
if (file_put_contents($argv[2] . '/pgv_changes.php', $changes) === false) {
    fwrite(STDERR, "cannot write pgv_changes.php\n");
    exit(1);
}
if (is_file($argv[2] . '/pgv_changes.json')) {
    unlink($argv[2] . '/pgv_changes.json');
}
