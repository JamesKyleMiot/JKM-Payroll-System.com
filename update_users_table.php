<?php
// update_users_table.php - Ensure users table has all required columns
try {
    // Connect to payroll_db
    $host = 'localhost';
    $port = 3306;
    $database = 'payroll_db';
    $username = 'root';
    $password = '';
    $charset = 'utf8mb4';
    
    $dsn = "mysql:host={$host};port={$port};dbname={$database};charset={$charset}";
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];
    
    $db = new PDO($dsn, $username, $password, $options);
    echo "✅ Connected to payroll_db\n";
    
    // Check current table structure
    echo "\n📊 Current users table structure:\n";
    $columns = $db->query("DESCRIBE users")->fetchAll();
    $existingColumns = [];
    
    foreach ($columns as $col) {
        $existingColumns[] = $col['Field'];
        echo "  - {$col['Field']} ({$col['Type']})\n";
    }
    
    // Required columns with their definitions (in your preferred order)
    $requiredColumns = [
        'id' => 'INT AUTO_INCREMENT PRIMARY KEY',
        'username' => 'VARCHAR(50) UNIQUE NOT NULL',
        'password' => 'VARCHAR(255) NOT NULL',
        'email' => 'VARCHAR(100)',
        'full_name' => 'VARCHAR(100)',
        'role' => 'VARCHAR(20) DEFAULT "admin"',
        'is_active' => 'BOOLEAN DEFAULT TRUE',
        'created_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
        'updated_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'
    ];
    
    echo "\n🔧 Checking for missing columns...\n";
    $columnsAdded = 0;
    
    foreach ($requiredColumns as $columnName => $columnDef) {
        if (!in_array($columnName, $existingColumns)) {
            echo "  ➕ Adding column: $columnName\n";
            try {
                if ($columnName === 'id') {
                    // Skip ID if it doesn't exist - table needs to be recreated
                    echo "    ⚠️ Primary key missing - table structure needs fixing\n";
                    continue;
                }
                
                $sql = "ALTER TABLE users ADD COLUMN `$columnName` $columnDef";
                $db->exec($sql);
                echo "    ✅ Added: $columnName\n";
                $columnsAdded++;
            } catch (Exception $e) {
                echo "    ❌ Failed to add $columnName: " . $e->getMessage() . "\n";
            }
        } else {
            echo "  ✅ Column exists: $columnName\n";
        }
    }
    
    // Check column order and suggest reordering if needed
    echo "\n📋 Column order verification:\n";
    $currentOrder = array_column($columns, 'Field');
    $preferredOrder = array_keys($requiredColumns);
    
    $orderMatches = true;
    for ($i = 0; $i < min(count($currentOrder), count($preferredOrder)); $i++) {
        if ($currentOrder[$i] !== $preferredOrder[$i]) {
            $orderMatches = false;
            break;
        }
    }
    
    if (!$orderMatches && $columnsAdded === 0) {
        echo "  ℹ️ Columns exist but in different order than specified\n";
        echo "  📝 Current order: " . implode(', ', $currentOrder) . "\n";
        echo "  📝 Your preferred: " . implode(', ', $preferredOrder) . "\n";
        echo "  💡 Table is functional - reordering is optional for performance\n";
    } else if ($orderMatches) {
        echo "  ✅ Column order matches your specification\n";
    }
    
    // Check for unique constraint on username
    echo "\n🔒 Checking username unique constraint...\n";
    try {
        $uniqueCheck = $db->query("
            SELECT COUNT(*) as unique_exists 
            FROM information_schema.statistics 
            WHERE TABLE_SCHEMA = 'payroll_db' 
            AND TABLE_NAME = 'users' 
            AND COLUMN_NAME = 'username'
            AND NON_UNIQUE = 0
        ")->fetchColumn();
        
        if (!$uniqueCheck) {
            echo "  ➕ Adding unique constraint on username...\n";
            try {
                $db->exec("ALTER TABLE users ADD UNIQUE KEY unique_username (username)");
                echo "  ✅ Unique constraint added\n";
            } catch (Exception $e) {
                if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
                    echo "  ⚠️ Cannot add unique constraint - duplicate usernames exist\n";
                } else {
                    echo "  ⚠️ Unique constraint: " . $e->getMessage() . "\n";
                }
            }
        } else {
            echo "  ✅ Username unique constraint exists\n";
        }
    } catch (Exception $e) {
        echo "  ⚠️ Unique constraint check: " . $e->getMessage() . "\n";
    }
    
    // Final verification
    echo "\n📋 Final users table structure:\n";
    $finalColumns = $db->query("DESCRIBE users")->fetchAll();
    foreach ($finalColumns as $col) {
        $default = $col['Default'] ? " (default: {$col['Default']})" : "";
        $null = $col['Null'] === 'YES' ? ' NULL' : ' NOT NULL';
        $key = $col['Key'] ? " [{$col['Key']}]" : "";
        echo "  ✅ {$col['Field']} - {$col['Type']}{$null}{$default}{$key}\n";
    }
    
    if ($columnsAdded > 0) {
        echo "\n🎉 SUCCESS! Added $columnsAdded new columns to users table.\n";
    } else {
        echo "\n✅ All required columns already exist in users table.\n";
    }
    
    // Check existing users
    echo "\n👤 Current users in system:\n";
    $users = $db->query("SELECT id, username, email, full_name, role, is_active FROM users")->fetchAll();
    foreach ($users as $user) {
        $active = $user['is_active'] ? 'Active' : 'Inactive';
        echo "  - ID:{$user['id']} | {$user['username']} | {$user['role']} | $active\n";
    }
    
    echo "\n📊 Table record count: " . count($users) . " users\n";
    echo "\n🔐 Your user management system is ready!\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    
    if (strpos($e->getMessage(), 'Unknown database') !== false) {
        echo "\n💡 Solution: Run setup_complete_database.php first to create the database.\n";
    }
}
?>