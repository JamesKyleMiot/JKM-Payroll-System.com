<?php
// db_connect.php
// Usage: $db = require 'db_connect.php';
// Returns a PDO instance connected to MySQL (throws on failure).

$config = require __DIR__ . '/config.php';

$dsn = sprintf(
    'mysql:host=%s;port=%d;dbname=%s;charset=%s',
    $config['db_host'],
    $config['db_port'],
    $config['db_name'],
    $config['db_charset']
);

$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];

try {
    $pdo = new PDO($dsn, $config['db_user'], $config['db_pass'], $options);
    return $pdo;
} catch (PDOException $e) {
    // In production you might log this and show a friendly error.
    die('Database connection failed: ' . $e->getMessage());
}