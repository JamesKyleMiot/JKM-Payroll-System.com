<?php
// init_db.php - Database initialization
$dbFile = __DIR__ . '/data/payroll_db';

// Create data directory if it doesn't exist
if (!is_dir(__DIR__ . '/data')) {
    mkdir(__DIR__ . '/data', 0755, true);
}

try {
    $db = new PDO('sqlite:' . $dbFile);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Create users table FIRST
    $db->exec("CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username VARCHAR(50) UNIQUE NOT NULL,
        email VARCHAR(100) UNIQUE NOT NULL,
        password VARCHAR(255) NOT NULL,
        role VARCHAR(20) DEFAULT 'admin',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    // Create employees table
    $db->exec("CREATE TABLE IF NOT EXISTS employees (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name VARCHAR(100) NOT NULL,
        position VARCHAR(100),
        department VARCHAR(100),
        start_date DATE,
        hourly_rate DECIMAL(10,2) DEFAULT 15.00,
        status VARCHAR(20) DEFAULT 'Active'
    )");

    // Create time_entries table for Time & Attendance
    $db->exec("CREATE TABLE IF NOT EXISTS time_entries (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        employee_id INTEGER NOT NULL,
        entry_date DATE NOT NULL,
        clock_in TIME,
        clock_out TIME,
        total_hours DECIMAL(4,2) DEFAULT 0,
        break_minutes INTEGER DEFAULT 0,
        status VARCHAR(20) DEFAULT 'Open',
        notes TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(employee_id) REFERENCES employees(id)
    )");

    // Create payrolls table
    $db->exec("CREATE TABLE IF NOT EXISTS payrolls (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        employee_id INTEGER,
        start_date DATE,
        end_date DATE,
        deductions DECIMAL(10,2) DEFAULT 0,
        net_pay DECIMAL(10,2) DEFAULT 0,
        status VARCHAR(20) DEFAULT 'Pending',
        FOREIGN KEY(employee_id) REFERENCES employees(id)
    )");

    // Create default admin user if no users exist
    $userCount = $db->query("SELECT COUNT(*) FROM users")->fetchColumn();
    if ($userCount == 0) {
        $defaultPassword = password_hash('admin123', PASSWORD_DEFAULT);
        $stmt = $db->prepare("INSERT INTO users (username, email, password, role) VALUES (?, ?, ?, ?)");
        $stmt->execute(['admin', 'admin@payroll.com', $defaultPassword, 'admin']);
        echo "<div style='background: #d4edda; color: #155724; padding: 1rem; border-radius: 6px; margin: 1rem; border-left: 4px solid #28a745;'>";
        echo "✅ Default admin user created successfully!<br>";
        echo "<strong>Username:</strong> admin<br>";
        echo "<strong>Password:</strong> admin123<br>";
        echo "<strong>Email:</strong> admin@payroll.com";
        echo "</div>";
    } else {
        echo "<div style='background: #fff3cd; color: #856404; padding: 1rem; border-radius: 6px; margin: 1rem; border-left: 4px solid #ffc107;'>";
        echo "⚠️ Admin user already exists. Skipping user creation.";
        echo "</div>";
    }

    echo "<div style='background: #d1ecf1; color: #0c5460; padding: 1rem; border-radius: 6px; margin: 1rem; border-left: 4px solid #17a2b8;'>";
    echo "✅ Database initialized successfully!<br>";
    echo "<strong>Tables created:</strong> users, employees, payrolls<br>";
    echo "<strong>Database location:</strong> " . $dbFile;
    echo "</div>";

    echo "<div style='text-align: center; margin: 2rem;'>";
    echo "<a href='index.php' style='background: #007bff; color: white; padding: 0.75rem 1.5rem; text-decoration: none; border-radius: 6px; font-weight: 600;'>Go to Login Page</a>";
    echo "</div>";

} catch (Exception $e) {
    echo "<div style='background: #f8d7da; color: #721c24; padding: 1rem; border-radius: 6px; margin: 1rem; border-left: 4px solid #dc3545;'>";
    echo "❌ Error: " . $e->getMessage();
    echo "</div>";
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Database Initialization - Payroll System</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body { font-family: system-ui, Arial, sans-serif; background: #f8f9fa; margin: 0; padding: 2rem; }
        .container { max-width: 600px; margin: 0 auto; }
        h1 { color: #333; text-align: center; margin-bottom: 2rem; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🏢 Payroll System Database Setup</h1>
    </div>
</body>
</html>