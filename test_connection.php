<?php
// test_connection.php - Simple test to verify payroll_db connection
echo "<h2>🔧 Testing payroll_db Connection</h2>";

try {
    // Test direct connection to payroll_db
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
    
    echo "<p style='color: green;'>✅ <strong>Connection Successful!</strong></p>";
    
    // Test tables
    echo "<h3>📊 Table Status:</h3>";
    $requiredTables = ['employees', 'users', 'time_entries', 'payrolls'];
    
    foreach ($requiredTables as $table) {
        try {
            $count = $db->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
            echo "<p style='color: green;'>✅ Table '$table': $count records</p>";
        } catch (Exception $e) {
            echo "<p style='color: red;'>❌ Table '$table': " . $e->getMessage() . "</p>";
        }
    }
    
    // Test admin user
    try {
        $adminExists = $db->query("SELECT COUNT(*) FROM users WHERE username = 'admin'")->fetchColumn();
        if ($adminExists > 0) {
            echo "<p style='color: green;'>✅ Admin user exists</p>";
        } else {
            echo "<p style='color: orange;'>⚠️ No admin user found</p>";
        }
    } catch (Exception $e) {
        echo "<p style='color: red;'>❌ Admin check failed: " . $e->getMessage() . "</p>";
    }
    
    echo "<hr><p style='color: green; font-weight: bold;'>🎉 Your payroll_db is ready!</p>";
    echo "<p><a href='index.php' style='background: #007cba; color: white; padding: 10px 20px; text-decoration: none; border-radius: 4px;'>🚀 Open Payroll System</a></p>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ <strong>Connection Failed:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
    
    if (strpos($e->getMessage(), 'Unknown database') !== false) {
        echo "<p style='color: orange;'>⚠️ The 'payroll_db' database doesn't exist yet.</p>";
        echo "<p><a href='setup_complete_database.php' style='background: #28a745; color: white; padding: 10px 20px; text-decoration: none; border-radius: 4px;'>🔧 Create Database Now</a></p>";
    } else {
        echo "<p><strong>Possible Solutions:</strong></p>";
        echo "<ul>";
        echo "<li>Make sure XAMPP is running</li>";
        echo "<li>Start MySQL service in XAMPP Control Panel</li>";
        echo "<li>Check if port 3306 is available</li>";
        echo "</ul>";
        echo "<p><a href='setup_complete_database.php' style='background: #dc3545; color: white; padding: 10px 20px; text-decoration: none; border-radius: 4px;'>🔧 Try Setup Database</a></p>";
    }
}
?>