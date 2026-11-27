// Settings for the container image, inserted into config.php when the image is built.
// Secrets are read from the environment at run time and are never written into the image.
$DBTYPE = "mysql";
$DBHOST = getenv('DB_HOST');
$DBNAME = getenv('DB_NAME');
$DBUSER = getenv('DB_USER');
$DBPASS = getenv('DB_PASSWORD');
$USE_REGISTRATION_MODULE = false;
$PGV_MEMORY_LIMIT = "256M";
$CONFIGURED = true;
