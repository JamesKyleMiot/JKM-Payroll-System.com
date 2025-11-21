<?php
// test_db_status.php - Quick database connection and table check
require_once __DIR__ . '/mysql_config.php';

try {
    $db = getMySQLConnection();
    echo "✅ Database connection: SUCCESS\n";
    
    $tables = ['users', 'employees', 'time_entries', 'payrolls'];
    echo "\n📊 Table Status:\n";
    foreach ($tables as $table) {
        try {
            $count = $db->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
            echo "  - $table: $count records\n";
        } catch (Exception $e) {
            echo "  - $table: ERROR - " . $e->getMessage() . "\n";
        }
    }
    
    // Check admin user
    echo "\n👤 Admin Status:\n";
    $adminCount = $db->query("SELECT COUNT(*) FROM users WHERE username = 'admin'")->fetchColumn();
    if ($adminCount > 0) {
        echo "  ✅ Admin user exists\n";
    } else {
        echo "  ❌ No admin user found\n";
        
        // Create admin user
        echo "  🔧 Creating admin user...\n";
        $stmt = $db->prepare("INSERT INTO users (username, email, full_name, password, role) VALUES (?, ?, ?, ?, 'admin')");
        $stmt->execute(['admin', 'admin@payroll.local', 'System Administrator', password_hash('admin123', PASSWORD_DEFAULT)]);
        echo "  ✅ Admin user created: username=admin, password=admin123\n";
    }
    
    echo "\n✅ Database is ready!\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
?>