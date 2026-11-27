<?php

declare(strict_types=1);

// Usage: php build-config.php <config.php> <settings-file>
// Inserts the settings just before config.php loads the rest of the program.

$configFile = $argv[1] ?? '';
$settingsFile = $argv[2] ?? '';
$marker = "require_once './includes/session.php';";

$config = is_file($configFile) ? file_get_contents($configFile) : false;
$settings = is_file($settingsFile) ? file_get_contents($settingsFile) : false;
if ($config === false || $settings === false || substr_count($config, $marker) !== 1) {
    fwrite(STDERR, "unexpected or missing config.php or settings file\n");
    exit(1);
}

if (file_put_contents($configFile, str_replace($marker, $settings . $marker, $config)) === false) {
    fwrite(STDERR, "cannot write config.php\n");
    exit(1);
}
