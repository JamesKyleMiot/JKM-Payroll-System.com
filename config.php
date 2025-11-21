<?php
// config.php
// Configure these values to match your XAMPP MySQL/MariaDB settings.
// XAMPP defaults: host=127.0.0.1, port=3306, user=root, password usually empty.
return [
    'db_host' => 'localhost',
    'db_port' => 3306,
    // Unified MySQL database name (avoid .db suffix which implies a file)
    'db_name' => 'payroll_db',
    'db_user' => 'root',
    'db_pass' => '', // If you set a root password in XAMPP, put it here
    'db_charset' => 'utf8mb4',
];