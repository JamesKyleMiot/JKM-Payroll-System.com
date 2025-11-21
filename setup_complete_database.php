<?php
// setup_complete_database.php - Complete payroll_db setup with proper structure and data
require_once __DIR__ . '/mysql_config.php';

try {
    echo "🔧 Setting up payroll_db database...\n\n";
    
    // Get database connection
    $db = getMySQLConnection();
    echo "✅ Connected to MySQL server\n";
    
    // Ensure database exists
    $db->exec("CREATE DATABASE IF NOT EXISTS payroll_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    echo "✅ Database 'payroll_db' ready\n";
    
    // Select the database
    $db->exec("USE payroll_db");
    
    // Disable foreign key checks temporarily
    $db->exec("SET FOREIGN_KEY_CHECKS = 0");
    
    // Create users table
    $db->exec("DROP TABLE IF EXISTS users");
    $db->exec("CREATE TABLE users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(50) UNIQUE NOT NULL,
        password VARCHAR(255) NOT NULL,
        email VARCHAR(100),
        full_name VARCHAR(100),
        role VARCHAR(20) DEFAULT 'admin',
        is_active BOOLEAN DEFAULT TRUE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "✅ Users table created\n";
    
    // Create employees table
    $db->exec("DROP TABLE IF EXISTS employees");
    $db->exec("CREATE TABLE employees (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        email VARCHAR(100),
        position VARCHAR(100),
        department VARCHAR(100),
        hire_date DATE,
        salary DECIMAL(10,2),
        hourly_rate DECIMAL(10,2) DEFAULT 15.00,
        status VARCHAR(20) DEFAULT 'Active',
        phone VARCHAR(20),
        address TEXT,
        emergency_contact VARCHAR(100),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "✅ Employees table created\n";
    
    // Create time_entries table
    $db->exec("DROP TABLE IF EXISTS time_entries");
    $db->exec("CREATE TABLE time_entries (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "✅ Time entries table created\n";
    
    // Create payrolls table
    $db->exec("DROP TABLE IF EXISTS payrolls");
    $db->exec("CREATE TABLE payrolls (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "✅ Payrolls table created\n";
    
    // Re-enable foreign key checks
    $db->exec("SET FOREIGN_KEY_CHECKS = 1");
    
    // Insert admin user
    echo "\n👤 Setting up admin user...\n";
    $stmt = $db->prepare("INSERT INTO users (username, email, full_name, password, role) VALUES (?, ?, ?, ?, 'admin')");
    $stmt->execute([
        'admin', 
        'admin@payroll.local', 
        'System Administrator', 
        password_hash('admin123', PASSWORD_DEFAULT)
    ]);
    echo "✅ Admin user created: username=admin, password=admin123\n";
    
    // Insert sample employees
    echo "\n👥 Adding sample employees...\n";
    $employees = [
        ['John Smith', 'john@company.com', 'Senior Developer', 'IT', '2024-01-15', 28.50],
        ['Jane Doe', 'jane@company.com', 'HR Manager', 'Human Resources', '2023-06-01', 32.00], 
        ['Mike Johnson', 'mike@company.com', 'Financial Analyst', 'Finance', '2024-03-10', 25.75],
        ['Sarah Wilson', 'sarah@company.com', 'UI/UX Designer', 'Marketing', '2024-02-20', 26.00],
        ['David Brown', 'david@company.com', 'Operations Manager', 'Operations', '2023-11-15', 30.25]
    ];
    
    $stmt = $db->prepare("INSERT INTO employees (name, email, position, department, hire_date, hourly_rate, status) VALUES (?, ?, ?, ?, ?, ?, 'Active')");
    
    foreach ($employees as $emp) {
        $stmt->execute($emp);
        echo "  ✅ Added: {$emp[0]} - {$emp[2]} (\$" . number_format($emp[5], 2) . "/hr)\n";
    }
    
    // Verify setup
    echo "\n📊 Database verification:\n";
    $tables = ['users', 'employees', 'time_entries', 'payrolls'];
    foreach ($tables as $table) {
        $count = $db->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
        echo "  - $table: $count records\n";
    }
    
    echo "\n🎉 SUCCESS! Your payroll_db database is completely set up!\n";
    echo "\n🔐 Login Information:\n";
    echo "   URL: http://localhost/Payroll\n";
    echo "   Username: admin\n";
    echo "   Password: admin123\n";
    echo "\n✨ Features Available:\n";
    echo "   - Employee Management\n";
    echo "   - Time Clock In/Out\n";
    echo "   - Payroll Processing\n";
    echo "   - Reports & Analytics\n";
    echo "   - User Management\n";
    
} catch (Exception $e) {
    echo "❌ Error setting up database: " . $e->getMessage() . "\n";
    exit(1);
}
?>