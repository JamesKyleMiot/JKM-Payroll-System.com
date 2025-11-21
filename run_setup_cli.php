<?php
// run_setup_cli.php - include setup_mysql.php and run the setup function
require_once __DIR__ . '/setup_mysql.php';

if (isset($config) && is_array($config)) {
    echo "Running web setup via CLI...\n";
    setupMySQLDatabase($config);
} else {
    echo "Configuration not found in setup_mysql.php\n";
}

echo "Done.\n";
