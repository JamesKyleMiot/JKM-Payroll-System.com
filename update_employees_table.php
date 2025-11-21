<?php
// update_employees_table.php - Ensure employees table has all required columns
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
    echo "\n📊 Current employees table structure:\n";
    $columns = $db->query("DESCRIBE employees")->fetchAll();
    $existingColumns = [];
    
    foreach ($columns as $col) {
        $existingColumns[] = $col['Field'];
        echo "  - {$col['Field']} ({$col['Type']})\n";
    }
    
    // Required columns with their definitions (in your preferred order)
    $requiredColumns = [
        'id' => 'INT AUTO_INCREMENT PRIMARY KEY',
        'name' => 'VARCHAR(100) NOT NULL',
        'email' => 'VARCHAR(100)',
        'position' => 'VARCHAR(100)',
        'department' => 'VARCHAR(100)',
        'hire_date' => 'DATE',
        'salary' => 'DECIMAL(10,2)',
        'hourly_rate' => 'DECIMAL(10,2) DEFAULT 15.00',
        'status' => 'VARCHAR(20) DEFAULT "Active"',
        'phone' => 'VARCHAR(20)',
        'address' => 'TEXT',
        'emergency_contact' => 'VARCHAR(100)',
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
                
                $sql = "ALTER TABLE employees ADD COLUMN `$columnName` $columnDef";
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
    
    // Add performance index on commonly searched fields
    echo "\n📈 Checking performance indexes...\n";
    try {
        $nameIndexCheck = $db->query("
            SELECT COUNT(*) as index_exists 
            FROM information_schema.statistics 
            WHERE TABLE_SCHEMA = 'payroll_db' 
            AND TABLE_NAME = 'employees' 
            AND INDEX_NAME = 'idx_name_status'
        ")->fetchColumn();
        
        if (!$nameIndexCheck) {
            echo "  ➕ Adding name/status index for better search performance...\n";
            $db->exec("ALTER TABLE employees ADD INDEX idx_name_status (name, status)");
            echo "  ✅ Name/status index added\n";
        } else {
            echo "  ✅ Name/status index exists\n";
        }
    } catch (Exception $e) {
        echo "  ⚠️ Index check: " . $e->getMessage() . "\n";
    }
    
    // Add index on department for reporting
    try {
        $deptIndexCheck = $db->query("
            SELECT COUNT(*) as index_exists 
            FROM information_schema.statistics 
            WHERE TABLE_SCHEMA = 'payroll_db' 
            AND TABLE_NAME = 'employees' 
            AND INDEX_NAME = 'idx_department'
        ")->fetchColumn();
        
        if (!$deptIndexCheck) {
            echo "  ➕ Adding department index for reporting...\n";
            $db->exec("ALTER TABLE employees ADD INDEX idx_department (department)");
            echo "  ✅ Department index added\n";
        } else {
            echo "  ✅ Department index exists\n";
        }
    } catch (Exception $e) {
        echo "  ⚠️ Department index: " . $e->getMessage() . "\n";
    }
    
    // Final verification
    echo "\n📋 Final employees table structure:\n";
    $finalColumns = $db->query("DESCRIBE employees")->fetchAll();
    foreach ($finalColumns as $col) {
        $default = $col['Default'] ? " (default: {$col['Default']})" : "";
        $null = $col['Null'] === 'YES' ? ' NULL' : ' NOT NULL';
        $key = $col['Key'] ? " [{$col['Key']}]" : "";
        echo "  ✅ {$col['Field']} - {$col['Type']}{$null}{$default}{$key}\n";
    }
    
    if ($columnsAdded > 0) {
        echo "\n🎉 SUCCESS! Added $columnsAdded new columns to employees table.\n";
    } else {
        echo "\n✅ All required columns already exist in employees table.\n";
    }
    
    // Show current employee data summary
    echo "\n👥 Current employees in system:\n";
    $employees = $db->query("SELECT id, name, position, department, status, hire_date FROM employees ORDER BY name")->fetchAll();
    
    if (empty($employees)) {
        echo "  ℹ️ No employees found - ready for data entry\n";
    } else {
        foreach ($employees as $emp) {
            $hireDate = $emp['hire_date'] ? date('M Y', strtotime($emp['hire_date'])) : 'N/A';
            echo "  - ID:{$emp['id']} | {$emp['name']} | {$emp['position']} | {$emp['department']} | {$emp['status']} | Hired: $hireDate\n";
        }
    }
    
    echo "\n📊 Table record count: " . count($employees) . " employees\n";
    echo "\n👥 Your employee management system is ready!\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    
    if (strpos($e->getMessage(), 'Unknown database') !== false) {
        echo "\n💡 Solution: Run setup_complete_database.php first to create the database.\n";
    }
}
?>