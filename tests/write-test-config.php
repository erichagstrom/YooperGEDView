<?php

declare(strict_types=1);

// Usage: php write-test-config.php <site-dir>
// Adds test settings (no self-registration) to a throw-away copy of app/config.php. The database is an SQLite
// file in the site's index folder, unless DB_TYPE is "mysql" in the environment: then it is the one named by
// DB_HOST, DB_PORT, DB_NAME, DB_USER and DB_PASSWORD.

$siteDir = $argv[1] ?? '';
$file = $siteDir . '/config.php';
$marker = "require_once './includes/session.php';";

$text = is_file($file) ? file_get_contents($file) : false;
if ($text === false || substr_count($text, $marker) !== 1) {
    fwrite(STDERR, "unexpected or missing config.php\n");
    exit(1);
}

$database = <<<'PHP'
    $DBTYPE = "sqlite";
    $DBNAME = __DIR__ . "/index/test.sqlite";

    PHP;
if (getenv('DB_TYPE') === 'mysql') {
    $database = '';
    foreach (['DBTYPE' => 'DB_TYPE', 'DBHOST' => 'DB_HOST', 'DBPORT' => 'DB_PORT', 'DBNAME' => 'DB_NAME', 'DBUSER' => 'DB_USER', 'DBPASS' => 'DB_PASSWORD'] as $setting => $variable) {
        $database .= '$' . $setting . ' = ' . var_export((string) getenv($variable), true) . ";\n";
    }
}

$settings = $database . <<<'PHP'
    $USE_REGISTRATION_MODULE = false;
    $MAX_VIEWS = "100000";
    $PGV_MEMORY_LIMIT = "256M";
    $CONFIGURED = true;

    PHP;

if (file_put_contents($file, str_replace($marker, $settings . $marker, $text)) === false) {
    fwrite(STDERR, "cannot write config.php\n");
    exit(1);
}
