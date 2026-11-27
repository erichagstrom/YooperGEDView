<?php

declare(strict_types=1);

// Usage: php rename-user.php <sqlite-file> <user-name> <new-user-name>
// Changes a user name in the test site, as the account page lets a user do for themselves.

if ($argc !== 4) {
    fwrite(STDERR, "usage: php rename-user.php <sqlite-file> <user-name> <new-user-name>\n");
    exit(1);
}
$database = new PDO('sqlite:' . $argv[1], null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$statement = $database->prepare('UPDATE pgv_user SET user_name = ? WHERE user_name = ?');
$statement->execute([$argv[3], $argv[2]]);
if ($statement->rowCount() !== 1) {
    fwrite(STDERR, "no user called {$argv[2]}\n");
    exit(1);
}
