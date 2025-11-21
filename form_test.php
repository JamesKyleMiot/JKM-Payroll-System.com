<!DOCTYPE html>
<html>
<head>
    <title>Employee Form Test</title>
</head>
<body>
    <h2>Direct Employee Add Test</h2>
    
    <?php
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        echo "<div style='background: #f0f8ff; padding: 10px; border: 1px solid #0066cc; margin: 10px 0;'>";
        echo "<h3>Form Data Received:</h3>";
        echo "<pre>" . print_r($_POST, true) . "</pre>";
        
        if (isset($_POST['action']) && $_POST['action'] === 'add_employee') {
            try {
                require_once __DIR__ . '/mysql_config.php';
                
                // Same logic as index.php
                $db = getMySQLConnection();
                $dbType = 'mysql';
                
                // Include the insertEmployee function
                function insertEmployee($db, $data, $dbType = 'mysql') {
                    $name = $data['name'] ?? '';
                    $position = $data['position'] ?? null;
                    $department = $data['department'] ?? null;
                    $date = $data['date'] ?: null;
                    $hourly_rate = isset($data['hourly_rate']) ? floatval($data['hourly_rate']) : null;

                    $dateCol = $dbType === 'mysql' ? 'hire_date' : 'start_date';

                    if ($hourly_rate !== null) {
                        try {
                            $sql = "INSERT INTO employees (name, position, department, $dateCol, hourly_rate, status) VALUES (?, ?, ?, ?, ?, 'Active')";
                            $stmt = $db->prepare($sql);
                            $stmt->execute([$name, $position, $department, $date, $hourly_rate]);
                            return $db->lastInsertId();
                        } catch (Exception $e) {
                            // fallback to without hourly_rate
                        }
                    }

                    $sql = "INSERT INTO employees (name, position, department, $dateCol, status) VALUES (?, ?, ?, ?, 'Active')";
                    $stmt = $db->prepare($sql);
                    $stmt->execute([$name, $position, $department, $date]);
                    return $db->lastInsertId();
                }
                
                $name = trim($_POST['name'] ?? '');
                $position = trim($_POST['position'] ?? '');
                $department = trim($_POST['department'] ?? '');
                $start_date = trim($_POST['start_date'] ?? '');
                $hourly_rate = floatval($_POST['hourly_rate'] ?? 15.00);
                
                if ($name != '') {
                    $newId = insertEmployee($db, [
                        'name' => $name,
                        'position' => $position,
                        'department' => $department,
                        'date' => $start_date,
                        'hourly_rate' => $hourly_rate,
                    ], $dbType);
                    
                    echo "<div style='background: #d4edda; color: #155724; padding: 10px; border: 1px solid #c3e6cb; margin: 10px 0;'>";
                    echo "<strong>SUCCESS!</strong> Employee added with ID: $newId<br>";
                    
                    // Show the new record
                    $stmt = $db->prepare("SELECT * FROM employees WHERE id = ?");
                    $stmt->execute([$newId]);
                    $employee = $stmt->fetch(PDO::FETCH_ASSOC);
                    echo "<h4>New Employee Record:</h4>";
                    echo "<pre>" . print_r($employee, true) . "</pre>";
                    echo "</div>";
                    
                } else {
                    echo "<div style='background: #f8d7da; color: #721c24; padding: 10px; border: 1px solid #f5c6cb; margin: 10px 0;'>";
                    echo "<strong>ERROR:</strong> Name is required.";
                    echo "</div>";
                }
                
            } catch (Exception $e) {
                echo "<div style='background: #f8d7da; color: #721c24; padding: 10px; border: 1px solid #f5c6cb; margin: 10px 0;'>";
                echo "<strong>ERROR:</strong> " . $e->getMessage();
                echo "</div>";
            }
        }
        echo "</div>";
    }
    ?>
    
    <form method="post" action="form_test.php" style="max-width: 400px; margin: 20px 0;">
        <input type="hidden" name="action" value="add_employee" />
        
        <div style="margin-bottom: 10px;">
            <label>Name *</label><br>
            <input name="name" required style="width: 100%; padding: 5px;" />
        </div>
        
        <div style="margin-bottom: 10px;">
            <label>Position</label><br>
            <input name="position" style="width: 100%; padding: 5px;" />
        </div>
        
        <div style="margin-bottom: 10px;">
            <label>Department</label><br>
            <input name="department" style="width: 100%; padding: 5px;" />
        </div>
        
        <div style="margin-bottom: 10px;">
            <label>Start Date</label><br>
            <input type="date" name="start_date" value="<?= date('Y-m-d') ?>" style="width: 100%; padding: 5px;" />
        </div>
        
        <div style="margin-bottom: 10px;">
            <label>Hourly Rate ($)</label><br>
            <input type="number" name="hourly_rate" step="0.01" min="0" value="15.00" style="width: 100%; padding: 5px;" />
        </div>
        
        <button type="submit" style="background: #007bff; color: white; padding: 10px 20px; border: none; cursor: pointer;">
            Add Employee
        </button>
    </form>
    
    <hr>
    
    <h3>Current Employee Count:</h3>
    <?php
    try {
        require_once __DIR__ . '/mysql_config.php';
        $db = getMySQLConnection();
        $count = $db->query("SELECT COUNT(*) FROM employees")->fetchColumn();
        echo "<p>Total employees in database: <strong>$count</strong></p>";
        
        // Show recent employees
        $recent = $db->query("SELECT id, name, position, department, hire_date, created_at FROM employees ORDER BY created_at DESC LIMIT 5")->fetchAll();
        echo "<h4>Recent employees:</h4>";
        echo "<ul>";
        foreach ($recent as $emp) {
            echo "<li>#{$emp['id']}: {$emp['name']} - {$emp['position']} ({$emp['hire_date']})</li>";
        }
        echo "</ul>";
        
    } catch (Exception $e) {
        echo "<p style='color: red;'>Error checking employees: " . $e->getMessage() . "</p>";
    }
    ?>
    
    <p><a href="index.php">← Back to Main App</a></p>
</body>
</html>