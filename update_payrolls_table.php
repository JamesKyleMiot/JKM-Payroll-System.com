<?php
// update_payrolls_table.php - Ensure payrolls table has all required columns
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
    echo "\n📊 Current payrolls table structure:\n";
    $columns = $db->query("DESCRIBE payrolls")->fetchAll();
    $existingColumns = [];
    
    foreach ($columns as $col) {
        $existingColumns[] = $col['Field'];
        echo "  - {$col['Field']} ({$col['Type']})\n";
    }
    
    // Required columns with their definitions
    $requiredColumns = [
        'id' => 'INT AUTO_INCREMENT PRIMARY KEY',
        'employee_id' => 'INT NOT NULL',
        'pay_period_start' => 'DATE NOT NULL',
        'pay_period_end' => 'DATE NOT NULL', 
        'regular_hours' => 'DECIMAL(5,2) DEFAULT 0',
        'overtime_hours' => 'DECIMAL(5,2) DEFAULT 0',
        'gross_pay' => 'DECIMAL(10,2) DEFAULT 0',
        'taxes' => 'DECIMAL(10,2) DEFAULT 0',
        'deductions' => 'DECIMAL(10,2) DEFAULT 0',
        'net_pay' => 'DECIMAL(10,2) DEFAULT 0',
        'status' => 'VARCHAR(20) DEFAULT "draft"',
        'notes' => 'TEXT',
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
                
                $sql = "ALTER TABLE payrolls ADD COLUMN `$columnName` $columnDef";
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
    
    // Add foreign key if missing
    echo "\n🔗 Checking foreign key constraint...\n";
    try {
        $fkCheck = $db->query("
            SELECT COUNT(*) as fk_exists 
            FROM information_schema.KEY_COLUMN_USAGE 
            WHERE CONSTRAINT_SCHEMA = 'payroll_db' 
            AND TABLE_NAME = 'payrolls' 
            AND COLUMN_NAME = 'employee_id' 
            AND REFERENCED_TABLE_NAME = 'employees'
        ")->fetchColumn();
        
        if (!$fkCheck) {
            echo "  ➕ Adding foreign key constraint...\n";
            $db->exec("ALTER TABLE payrolls ADD FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE");
            echo "  ✅ Foreign key constraint added\n";
        } else {
            echo "  ✅ Foreign key constraint exists\n";
        }
    } catch (Exception $e) {
        echo "  ⚠️ Foreign key check: " . $e->getMessage() . "\n";
    }
    
    // Final verification
    echo "\n📋 Final payrolls table structure:\n";
    $finalColumns = $db->query("DESCRIBE payrolls")->fetchAll();
    foreach ($finalColumns as $col) {
        echo "  ✅ {$col['Field']} - {$col['Type']}\n";
    }
    
    if ($columnsAdded > 0) {
        echo "\n🎉 SUCCESS! Added $columnsAdded new columns to payrolls table.\n";
    } else {
        echo "\n✅ All required columns already exist in payrolls table.\n";
    }
    
    echo "\n📊 Table record count: " . $db->query("SELECT COUNT(*) FROM payrolls")->fetchColumn() . " records\n";
    echo "\n🚀 Your payroll system is ready!\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    
    if (strpos($e->getMessage(), 'Unknown database') !== false) {
        echo "\n💡 Solution: Run setup_complete_database.php first to create the database.\n";
    }
}
?>