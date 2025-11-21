<?php
// MySQL Database Configuration for XAMPP
// Generated automatically by setup_mysql.php

$mysql_config = [
    'host' => 'localhost',
    'username' => 'root',
    'password' => '',
    'database' => 'payroll_db',
    'port' => 3306,
    'charset' => 'utf8mb4'
];

// Create MySQL PDO connection
function getMySQLConnection() {
    // Use helper to get config and validate
    $cfg = getMySQLConfig();
    if (!is_array($cfg)) {
        throw new Exception('MySQL configuration is missing or invalid.');
    }

    $host = $cfg['host'] ?? 'localhost';
    $port = $cfg['port'] ?? 3306;
    $db = $cfg['database'] ?? null;
    $user = $cfg['username'] ?? 'root';
    $pass = $cfg['password'] ?? '';
    $charset = $cfg['charset'] ?? 'utf8mb4';

    if (!$db) {
        throw new Exception('MySQL database name is not configured.');
    }

    // Build DSN without charset parameter (some environments reject charset in DSN)
    $dsn = "mysql:host={$host};port={$port};dbname={$db}";

    // PDO options - set INIT_COMMAND to ensure connection uses desired charset
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];
    if (defined('PDO::MYSQL_ATTR_INIT_COMMAND')) {
        $options[PDO::MYSQL_ATTR_INIT_COMMAND] = "SET NAMES '{$charset}'";
    }

    try {
        $pdo = new PDO($dsn, $user, $pass, $options);
        return $pdo;
    } catch (Exception $e) {
        // Re-throw with clearer message
        throw new Exception('MySQL Connection Error: ' . $e->getMessage());
    }
}

/**
 * Optionally run supporting setup scripts (create DB, show counts, insert sample data).
 * This does NOT run automatically; call runPayrollSetupScripts() when you explicitly
 * want those scripts executed (for example from CLI tooling).
 */
function runPayrollSetupScripts(array $scriptPaths = []) {
    if (empty($scriptPaths)) {
        $base = __DIR__ . DIRECTORY_SEPARATOR;
        $scriptPaths = [
            $base . 'create_payroll_db.php',
            $base . 'mysql_table_counts.php',
            $base . 'insert_test_employee.php'
        ];
    }

    foreach ($scriptPaths as $scriptPath) {
        if (!file_exists($scriptPath)) {
            echo "Script not found: {$scriptPath}\n";
            continue;
        }

        include $scriptPath;
    }
}
/**
 * Return the raw MySQL configuration array.
 */
function getMySQLConfig() {
    global $mysql_config;
    return $mysql_config;
}

/**
 * Validate the mysql_config array has required keys.
 */
function isMySQLConfigValid() {
    $cfg = getMySQLConfig();
    if (!is_array($cfg)) return false;
    $required = ['host','username','database','port','charset'];
    foreach ($required as $k) {
        if (!array_key_exists($k, $cfg) || $cfg[$k] === null) return false;
    }
    return true;
}

// End of mysql_config.php - no automatic execution here

