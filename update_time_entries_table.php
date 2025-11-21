<?php
// update_time_entries_table.php - Ensure time_entries table has all required columns
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
    echo "\n📊 Current time_entries table structure:\n";
    $columns = $db->query("DESCRIBE time_entries")->fetchAll();
    $existingColumns = [];
    
    foreach ($columns as $col) {
        $existingColumns[] = $col['Field'];
        echo "  - {$col['Field']} ({$col['Type']})\n";
    }
    
    // Required columns with their definitions (in your preferred order)
    $requiredColumns = [
        'id' => 'INT AUTO_INCREMENT PRIMARY KEY',
        'entry_date' => 'DATE NOT NULL',
        'employee_id' => 'INT NOT NULL',
        'clock_in' => 'TIME',
        'clock_out' => 'TIME',
        'total_hours' => 'DECIMAL(4,2) DEFAULT 0',
        'break_minutes' => 'INT DEFAULT 0',
        'status' => 'VARCHAR(20) DEFAULT "Open"',
        'notes' => 'TEXT',
        'updated_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
        'created_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP'
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
                
                $sql = "ALTER TABLE time_entries ADD COLUMN `$columnName` $columnDef";
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
    }
    
    // Add foreign key if missing
    echo "\n🔗 Checking foreign key constraint...\n";
    try {
        $fkCheck = $db->query("
            SELECT COUNT(*) as fk_exists 
            FROM information_schema.KEY_COLUMN_USAGE 
            WHERE CONSTRAINT_SCHEMA = 'payroll_db' 
            AND TABLE_NAME = 'time_entries' 
            AND COLUMN_NAME = 'employee_id' 
            AND REFERENCED_TABLE_NAME = 'employees'
        ")->fetchColumn();
        
        if (!$fkCheck) {
            echo "  ➕ Adding foreign key constraint...\n";
            $db->exec("ALTER TABLE time_entries ADD FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE");
            echo "  ✅ Foreign key constraint added\n";
        } else {
            echo "  ✅ Foreign key constraint exists\n";
        }
    } catch (Exception $e) {
        echo "  ⚠️ Foreign key check: " . $e->getMessage() . "\n";
    }
    
    // Add index for better performance
    echo "\n📈 Checking performance indexes...\n";
    try {
        $indexCheck = $db->query("
            SELECT COUNT(*) as index_exists 
            FROM information_schema.statistics 
            WHERE TABLE_SCHEMA = 'payroll_db' 
            AND TABLE_NAME = 'time_entries' 
            AND INDEX_NAME = 'idx_employee_date'
        ")->fetchColumn();
        
        if (!$indexCheck) {
            echo "  ➕ Adding performance index...\n";
            $db->exec("ALTER TABLE time_entries ADD INDEX idx_employee_date (employee_id, entry_date)");
            echo "  ✅ Performance index added\n";
        } else {
            echo "  ✅ Performance index exists\n";
        }
    } catch (Exception $e) {
        echo "  ⚠️ Index check: " . $e->getMessage() . "\n";
    }
    
    // Final verification
    echo "\n📋 Final time_entries table structure:\n";
    $finalColumns = $db->query("DESCRIBE time_entries")->fetchAll();
    foreach ($finalColumns as $col) {
        $default = $col['Default'] ? " (default: {$col['Default']})" : "";
        $null = $col['Null'] === 'YES' ? ' NULL' : ' NOT NULL';
        echo "  ✅ {$col['Field']} - {$col['Type']}{$null}{$default}\n";
    }
    
    if ($columnsAdded > 0) {
        echo "\n🎉 SUCCESS! Added $columnsAdded new columns to time_entries table.\n";
    } else {
        echo "\n✅ All required columns already exist in time_entries table.\n";
    }
    
    echo "\n📊 Table record count: " . $db->query("SELECT COUNT(*) FROM time_entries")->fetchColumn() . " records\n";
    echo "\n🕐 Your time tracking system is ready!\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    
    if (strpos($e->getMessage(), 'Unknown database') !== false) {
        echo "\n💡 Solution: Run setup_complete_database.php first to create the database.\n";
    }
}
?>