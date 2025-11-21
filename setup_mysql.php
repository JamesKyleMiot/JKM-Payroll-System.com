<?php
// setup_mysql.php - Automated MySQL Database Setup for XAMPP
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Database configuration for XAMPP
$config = [
    'host' => 'localhost',
    'username' => 'root',
    'password' => '',  // Default XAMPP MySQL password is empty
    'database' => 'payroll_db',
    'port' => 3306
];

function setupMySQLDatabase($config) {
    // Verify required PHP extensions
    if (!extension_loaded('pdo')) {
        echo "<div class='status error'>❌ PHP PDO extension is not enabled. Please enable PDO in your PHP configuration.</div>\n";
        return false;
    }
    if (!extension_loaded('pdo_mysql')) {
        echo "<div class='status error'>❌ PHP PDO MySQL driver is not enabled. Please enable pdo_mysql in your PHP configuration.</div>\n";
        return false;
    }

    try {
        // Connect to MySQL server (without database first)
        $dsn = "mysql:host={$config['host']};port={$config['port']};charset=utf8mb4";
        $pdo = new PDO($dsn, $config['username'], $config['password']);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        echo "<div class='status success'>✅ Connected to MySQL server successfully!</div>\n";
        
        // Create database if it doesn't exist
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$config['database']}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        echo "<div class='status success'>✅ Database '{$config['database']}' created/verified!</div>\n";
        
        // Connect to the specific database
        $dsn = "mysql:host={$config['host']};port={$config['port']};dbname={$config['database']};charset=utf8mb4";
        $pdo = new PDO($dsn, $config['username'], $config['password']);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Create tables (with dedicated error handling)
        try {
            createTables($pdo);
        } catch (Exception $te) {
            echo "<div class='status error'>❌ Table creation error: " . htmlspecialchars($te->getMessage()) . "</div>\n";
            return false;
        }

        // Insert sample data (with dedicated error handling)
        try {
            insertSampleData($pdo);
        } catch (Exception $se) {
            echo "<div class='status error'>⚠️ Sample data warning: " . htmlspecialchars($se->getMessage()) . "</div>\n";
            // continue even if sample data failed
        }

        // Create MySQL config file (verify write)
        try {
            createMySQLConfig($config);
        } catch (Exception $ce) {
            echo "<div class='status error'>❌ Could not create configuration file: " . htmlspecialchars($ce->getMessage()) . "</div>\n";
            return false;
        }
        
        echo "<div class='status success'>🎉 Database setup completed successfully!</div>\n";
        echo "<div class='info'>You can now use the payroll system with MySQL backend.</div>\n";
        
        return true;
        
    } catch (Exception $e) {
        echo "<div class='status error'>❌ Error: " . $e->getMessage() . "</div>\n";
        return false;
    }
}

function createTables($pdo) {
    $tables = [
        // Users table
        'users' => "CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(50) UNIQUE NOT NULL,
            email VARCHAR(100) UNIQUE NOT NULL,
            password VARCHAR(255) NOT NULL,
            role VARCHAR(20) DEFAULT 'admin',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        
        // Employees table (use hire_date to match mysql_config.php)
        'employees' => "CREATE TABLE IF NOT EXISTS employees (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            position VARCHAR(100),
            department VARCHAR(100),
            hire_date DATE,
            hourly_rate DECIMAL(10,2) DEFAULT 15.00,
            status VARCHAR(20) DEFAULT 'Active',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        
        // Time entries table
        'time_entries' => "CREATE TABLE IF NOT EXISTS time_entries (
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
            FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        
        // Payrolls table (use pay_period_start/pay_period_end to match mysql_config.php)
        'payrolls' => "CREATE TABLE IF NOT EXISTS payrolls (
            id INT AUTO_INCREMENT PRIMARY KEY,
            employee_id INT NOT NULL,
            pay_period_start DATE NOT NULL,
            pay_period_end DATE NOT NULL,
            regular_hours DECIMAL(6,2) DEFAULT 0,
            overtime_hours DECIMAL(6,2) DEFAULT 0,
            gross_pay DECIMAL(10,2) DEFAULT 0,
            taxes DECIMAL(10,2) DEFAULT 0,
            deductions DECIMAL(10,2) DEFAULT 0,
            net_pay DECIMAL(10,2) DEFAULT 0,
            status VARCHAR(20) DEFAULT 'draft',
            notes TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    ];
    
    foreach ($tables as $tableName => $sql) {
        $pdo->exec($sql);
        echo "<div class='status success'>✅ Table '{$tableName}' created successfully!</div>\n";
    }
}

function insertSampleData($pdo) {
    try {
        // Check if admin user exists
        $stmt = $pdo->query("SELECT COUNT(*) FROM users");
        $userCount = $stmt->fetchColumn();
        
        if ($userCount == 0) {
            // Create default admin users
            $users = [
                ['admin', 'admin@payroll.com', password_hash('admin123', PASSWORD_DEFAULT)],
                ['admin2', 'admin2@payroll.local', password_hash('Admin@123', PASSWORD_DEFAULT)],
                ['manager', 'manager@payroll.com', password_hash('manager123', PASSWORD_DEFAULT)]
            ];
            
            $stmt = $pdo->prepare("INSERT INTO users (username, email, password, role) VALUES (?, ?, ?, 'admin')");
            foreach ($users as $user) {
                $stmt->execute($user);
            }
            echo "<div class='status success'>✅ Default admin users created!</div>\n";
        }
        
        // Check if employees exist
        $stmt = $pdo->query("SELECT COUNT(*) FROM employees");
        $empCount = $stmt->fetchColumn();
        
        if ($empCount == 0) {
            // Create sample employees
            $employees = [
                ['John Smith', 'Software Developer', 'IT', '2024-01-15', 25.00],
                ['Sarah Johnson', 'HR Manager', 'Human Resources', '2023-06-01', 22.50],
                ['Mike Davis', 'Accountant', 'Finance', '2024-03-10', 20.00],
                ['Lisa Wilson', 'Marketing Specialist', 'Marketing', '2024-02-20', 18.50],
                ['David Brown', 'Operations Manager', 'Operations', '2023-09-15', 28.00],
                ['Emily Chen', 'UI/UX Designer', 'IT', '2024-04-05', 23.00],
                ['Robert Taylor', 'Sales Representative', 'Sales', '2024-01-30', 17.00],
                ['Amanda Garcia', 'Customer Service', 'Support', '2024-05-12', 16.50]
            ];
            
            $stmt = $pdo->prepare("INSERT INTO employees (name, position, department, hire_date, hourly_rate, status) VALUES (?, ?, ?, ?, ?, 'Active')");
            foreach ($employees as $emp) {
                $stmt->execute($emp);
            }
            echo "<div class='status success'>✅ Sample employees created!</div>\n";
        }
        
        // Create sample time entries for current month
        $stmt = $pdo->query("SELECT COUNT(*) FROM time_entries");
        $timeCount = $stmt->fetchColumn();
        
        if ($timeCount == 0) {
            $employees = $pdo->query("SELECT id FROM employees LIMIT 5")->fetchAll(PDO::FETCH_COLUMN);
            $stmt = $pdo->prepare("INSERT INTO time_entries (employee_id, entry_date, clock_in, clock_out, total_hours, status) VALUES (?, ?, ?, ?, ?, 'Closed')");
            
            // Create entries for last 10 days
            for ($i = 10; $i >= 1; $i--) {
                $date = date('Y-m-d', strtotime("-$i days"));
                foreach ($employees as $empId) {
                    if (rand(1, 10) > 2) { // 80% attendance rate
                        $clockIn = sprintf('%02d:%02d:00', rand(8, 9), rand(0, 59));
                        $clockOut = sprintf('%02d:%02d:00', rand(16, 18), rand(0, 59));
                        $hours = (strtotime($clockOut) - strtotime($clockIn)) / 3600;
                        $stmt->execute([$empId, $date, $clockIn, $clockOut, $hours]);
                    }
                }
            }
            echo "<div class='status success'>✅ Sample time entries created!</div>\n";
        }
        
    } catch (Exception $e) {
        echo "<div class='status error'>⚠️ Sample data warning: " . $e->getMessage() . "</div>\n";
    }
}

function createMySQLConfig($config) {
    $configContent = "<?php\n";
    $configContent .= "// MySQL Database Configuration for XAMPP\n";
    $configContent .= "// Generated automatically by setup_mysql.php\n\n";
    $configContent .= "\$mysql_config = [\n";
    $configContent .= "    'host' => '{$config['host']}',\n";
    $configContent .= "    'username' => '{$config['username']}',\n";
    $configContent .= "    'password' => '{$config['password']}',\n";
    $configContent .= "    'database' => '{$config['database']}',\n";
    $configContent .= "    'port' => {$config['port']},\n";
    $configContent .= "    'charset' => 'utf8mb4'\n";
    $configContent .= "];\n\n";
    $configContent .= "// Create MySQL PDO connection\n";
    $configContent .= "function getMySQLConnection() {\n";
    $configContent .= "    global \$mysql_config;\n";
    $configContent .= "    try {\n";
    $configContent .= "        \$dsn = \"mysql:host={\$mysql_config['host']};port={\$mysql_config['port']};dbname={\$mysql_config['database']};charset={\$mysql_config['charset']}\";\n";
    $configContent .= "        \$pdo = new PDO(\$dsn, \$mysql_config['username'], \$mysql_config['password']);\n";
    $configContent .= "        \$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);\n";
    $configContent .= "        \$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);\n";
    $configContent .= "        return \$pdo;\n";
    $configContent .= "    } catch (Exception \$e) {\n";
    $configContent .= "        die(\"MySQL Connection Error: \" . \$e->getMessage());\n";
    $configContent .= "    }\n";
    $configContent .= "}\n";
    $configContent .= "?>\n";
    
    $written = @file_put_contents(__DIR__ . '/mysql_config.php', $configContent);
    if ($written === false) {
        throw new Exception('Failed to write mysql_config.php. Check file permissions on the project folder.');
    }
    echo "<div class='status success'>✅ MySQL configuration file created!</div>\n";
}

?>
<!DOCTYPE html>
<html>
<head>
    <title>MySQL Database Setup - Payroll System</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body { font-family: system-ui, Arial, sans-serif; background: #f8f9fa; margin: 0; padding: 2rem; }
        .container { max-width: 800px; margin: 0 auto; }
        h1 { color: #333; text-align: center; margin-bottom: 2rem; }
        .status { padding: 1rem; margin: 0.5rem 0; border-radius: 6px; border-left: 4px solid; }
        .success { background: #d4edda; color: #155724; border-left-color: #28a745; }
        .error { background: #f8d7da; color: #721c24; border-left-color: #dc3545; }
        .info { background: #d1ecf1; color: #0c5460; border-left-color: #17a2b8; }
        .setup-btn { background: #007bff; color: white; padding: 1rem 2rem; border: none; border-radius: 6px; cursor: pointer; font-size: 1rem; }
        .setup-btn:hover { background: #0056b3; }
        .config-info { background: white; padding: 1.5rem; border-radius: 8px; margin: 1rem 0; border: 1px solid #dee2e6; }
        .nav-links { text-align: center; margin: 2rem 0; }
        .nav-links a { background: #28a745; color: white; padding: 0.75rem 1.5rem; text-decoration: none; border-radius: 6px; margin: 0 0.5rem; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🏢 Payroll System - MySQL Database Setup</h1>
        
        <div class="config-info">
            <h3>🔧 XAMPP MySQL Configuration</h3>
            <ul>
                <li><strong>Host:</strong> <?= $config['host'] ?></li>
                <li><strong>Port:</strong> <?= $config['port'] ?></li>
                <li><strong>Database:</strong> <?= $config['database'] ?></li>
                <li><strong>Username:</strong> <?= $config['username'] ?></li>
                <li><strong>Password:</strong> <?= empty($config['password']) ? '(empty - default XAMPP)' : '(configured)' ?></li>
            </ul>
        </div>
        
        <?php
        if (isset($_GET['setup']) && $_GET['setup'] === 'run') {
            echo "<h2>🚀 Running Database Setup...</h2>\n";
            $success = setupMySQLDatabase($config);
            
            if ($success) {
                echo "<div class='nav-links'>";
                echo "<a href='index.php'>🏠 Go to Payroll System</a>";
                echo "<a href='setup_mysql.php'>🔄 Setup Again</a>";
                echo "</div>";
                
                echo "<div class='config-info'>";
                echo "<h3>📋 Default Login Credentials</h3>";
                echo "<ul>";
                echo "<li><strong>Username:</strong> admin | <strong>Password:</strong> admin123</li>";
                echo "<li><strong>Username:</strong> admin2 | <strong>Password:</strong> Admin@123</li>";
                echo "<li><strong>Username:</strong> manager | <strong>Password:</strong> manager123</li>";
                echo "</ul>";
                echo "</div>";
            }
        } else {
            echo "<div class='info'>Click the button below to automatically set up the MySQL database with all tables and sample data.</div>";
            echo "<div style='text-align: center; margin: 2rem 0;'>";
            echo "<a href='setup_mysql.php?setup=run' class='setup-btn'>🚀 Setup MySQL Database</a>";
            echo "</div>";
            
            echo "<div class='config-info'>";
            echo "<h3>📋 What This Setup Will Do:</h3>";
            echo "<ul>";
            echo "<li>✅ Create MySQL database: <code>{$config['database']}</code></li>";
            echo "<li>✅ Create all required tables (users, employees, time_entries, payrolls)</li>";
            echo "<li>✅ Insert default admin users with secure passwords</li>";
            echo "<li>✅ Add sample employees with different roles and departments</li>";
            echo "<li>✅ Generate sample time tracking data for testing</li>";
            echo "<li>✅ Create MySQL configuration file for the payroll system</li>";
            echo "</ul>";
            echo "</div>";
        }
        ?>
    </div>
</body>
</html>