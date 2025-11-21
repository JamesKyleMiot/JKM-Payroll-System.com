<?php
// create_payroll_db.php - create database and tables using `mysql_config.php` settings
require_once __DIR__ . '/mysql_config.php';

// Use helper functions from mysql_config.php for validation and retrieval
if (!function_exists('isMySQLConfigValid') || !isMySQLConfigValid()) {
    echo "mysql_config not found or invalid\n";
    exit(1);
}

$cfg = getMySQLConfig();
$host = $cfg['host'] ?? 'localhost';
$port = $cfg['port'] ?? 3306;
$dbname = $cfg['database'] ?? 'payroll_db';
$user = $cfg['username'] ?? 'root';
$pass = $cfg['password'] ?? '';
$charset = $cfg['charset'] ?? 'utf8mb4';

try {
    // Connect without database to create it
    $dsn = "mysql:host={$host};port={$port};charset={$charset}";
    $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    echo "Connected to MySQL server\n";

    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbname}` CHARACTER SET {$charset} COLLATE {$charset}_unicode_ci");
    echo "Database `{$dbname}` created or already exists\n";

    // Connect to newly created database using existing helper if available
    $db = null;
    try {
        $db = getMySQLConnection();
    } catch (Exception $e) {
        // fallback: create new connection
        $dsn2 = "mysql:host={$host};port={$port};dbname={$dbname};charset={$charset}";
        $db = new PDO($dsn2, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    // Create tables (matching app expectations: hire_date, pay_period_start/pay_period_end)
    $db->exec("CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(50) UNIQUE NOT NULL,
        password VARCHAR(255) NOT NULL,
        email VARCHAR(100),
        full_name VARCHAR(100),
        role VARCHAR(20) DEFAULT 'admin',
        is_active BOOLEAN DEFAULT TRUE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET={$charset}");

    $db->exec("CREATE TABLE IF NOT EXISTS employees (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        email VARCHAR(100),
        position VARCHAR(100),
        department VARCHAR(100),
        hire_date DATE,
        salary DECIMAL(10,2),
        hourly_rate DECIMAL(10,2) DEFAULT 15.00,
        status VARCHAR(20) DEFAULT 'active',
        phone VARCHAR(20),
        address TEXT,
        emergency_contact VARCHAR(100),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET={$charset}");

    $db->exec("CREATE TABLE IF NOT EXISTS time_entries (
        id INT AUTO_INCREMENT PRIMARY KEY,
        employee_id INT NOT NULL,
        entry_date DATE NOT NULL,
        clock_in TIME,
        clock_out TIME,
        total_hours DECIMAL(4,2) DEFAULT 0,
        break_minutes INT DEFAULT 0,
        status VARCHAR(20) DEFAULT 'Open',
        notes TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
        INDEX idx_employee_date (employee_id, entry_date)
    ) ENGINE=InnoDB DEFAULT CHARSET={$charset}");

    $db->exec("CREATE TABLE IF NOT EXISTS payrolls (
        id INT AUTO_INCREMENT PRIMARY KEY,
        employee_id INT NOT NULL,
        pay_period_start DATE NOT NULL,
        pay_period_end DATE NOT NULL,
        regular_hours DECIMAL(5,2) DEFAULT 0,
        overtime_hours DECIMAL(5,2) DEFAULT 0,
        gross_pay DECIMAL(10,2) DEFAULT 0,
        taxes DECIMAL(10,2) DEFAULT 0,
        deductions DECIMAL(10,2) DEFAULT 0,
        net_pay DECIMAL(10,2) DEFAULT 0,
        status VARCHAR(20) DEFAULT 'draft',
        notes TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
        INDEX idx_employee_period (employee_id, pay_period_start, pay_period_end)
    ) ENGINE=InnoDB DEFAULT CHARSET={$charset}");

    echo "Tables created/verified\n";

    // Insert default admin if none exists
    $count = $db->query("SELECT COUNT(*) FROM users")->fetchColumn();
    if ($count == 0) {
        $stmt = $db->prepare("INSERT INTO users (username, email, password, role) VALUES (?, ?, ?, 'admin')");
        $stmt->execute(['admin', 'admin@payroll.local', password_hash('admin123', PASSWORD_DEFAULT)]);
        echo "Inserted default admin user (username: admin, password: admin123)\n";
    } else {
        echo "Users exist, skipping default admin insert\n";
    }

    echo "Done.\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
