<?php

declare(strict_types=1);

// Usage: php failed-sign-ins.php <sqlite-file> <user-name>
// Prints how many failed sign-ins the test site has recorded for a user name.

if ($argc !== 3) {
    fwrite(STDERR, "usage: php failed-sign-ins.php <sqlite-file> <user-name>\n");
    exit(1);
}
$database = new PDO('sqlite:' . $argv[1], null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$statement = $database->prepare('SELECT COUNT(*) FROM pgv_sign_in_attempt WHERE user_name = ? AND success = 0');
$statement->execute([$argv[2]]);
echo $statement->fetchColumn(), "\n";
