<?php

declare(strict_types=1);

// Usage: php write-test-config.php <site-dir>
// Adds test settings (SQLite database, no self-registration) to a throw-away copy of app/config.php.

$siteDir = $argv[1] ?? '';
$file = $siteDir . '/config.php';
$marker = "require_once './includes/session.php';";

$text = is_file($file) ? file_get_contents($file) : false;
if ($text === false || substr_count($text, $marker) !== 1) {
    fwrite(STDERR, "unexpected or missing config.php\n");
    exit(1);
}

$settings = <<<'PHP'
    $DBTYPE = "sqlite";
    $DBNAME = __DIR__ . "/index/test.sqlite";
    $USE_REGISTRATION_MODULE = false;
    $MAX_VIEWS = "100000";
    $PGV_MEMORY_LIMIT = "256M";
    $CONFIGURED = true;

    PHP;

if (file_put_contents($file, str_replace($marker, $settings . $marker, $text)) === false) {
    fwrite(STDERR, "cannot write config.php\n");
    exit(1);
}
