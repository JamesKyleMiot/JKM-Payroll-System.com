<?php
// index.php
session_start();

// Always use Philippine time (Asia/Manila)
if (function_exists('date_default_timezone_set')) {
    date_default_timezone_set('Asia/Manila');
}
@ini_set('date.timezone', 'Asia/Manila');

// Simple debug logger for troubleshooting web form submissions
function writeDebugLog($msg) {
    $logFile = __DIR__ . '/debug.log';
    $time = date('Y-m-d H:i:s');
    error_log("[{$time}] " . $msg . "\n", 3, $logFile);
}

// MySQL payroll_db Connection
function getDatabaseConnection() {
    try {
        // Direct MySQL connection to payroll_db
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
        
        // Test connection
        $db->query('SELECT 1');
        
        // Verify required tables exist
        $requiredTables = ['employees', 'users', 'time_entries', 'payrolls'];
        foreach ($requiredTables as $table) {
            try {
                $db->query("SELECT 1 FROM `$table` LIMIT 1");
            } catch (Exception $e) {
                throw new Exception("Table '$table' not found or inaccessible in payroll_db database");
            }
        }
        
        return ['db' => $db, 'type' => 'mysql'];
        
    } catch (Exception $e) {
        // Check if it's a database not found error and offer setup
        if (strpos($e->getMessage(), 'Unknown database') !== false) {
            die("<h3>Database Setup Required</h3><p>The 'payroll_db' database doesn't exist.</p><p><a href='setup_complete_database.php' style='background: #007cba; color: white; padding: 10px 20px; text-decoration: none; border-radius: 4px;'>🔧 Set Up Database Now</a></p>");
        } else {
            die("<h3>Database Connection Error</h3><p>" . htmlspecialchars($e->getMessage()) . "</p><p>Please check:</p><ul><li>XAMPP is running</li><li>MySQL service is started</li><li>Database 'payroll_db' exists</li></ul><p><a href='setup_complete_database.php' style='background: #28a745; color: white; padding: 10px 20px; text-decoration: none; border-radius: 4px;'>🔧 Set Up Database</a></p>");
        }
    }
}

$dbConnection = getDatabaseConnection();
$db = $dbConnection['db'];
$dbType = $dbConnection['type'];

// Database migration function for both MySQL and SQLite
function migrateDatabase($db, $dbType = 'sqlite') {
    try {
        if ($dbType === 'mysql') {
            // MySQL column check
            $stmt = $db->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employees' AND COLUMN_NAME = 'hourly_rate'");
            $stmt->execute();
            $hasHourlyRate = $stmt->fetchColumn();
            
            if (!$hasHourlyRate) {
                $db->exec("ALTER TABLE employees ADD COLUMN hourly_rate DECIMAL(10,2) DEFAULT 15.00");
            }
            
            // Create time_entries table for MySQL
            $db->exec("CREATE TABLE IF NOT EXISTS time_entries (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } else {
            // SQLite column check
            $columns = $db->query("PRAGMA table_info(employees)")->fetchAll(PDO::FETCH_ASSOC);
            $hasHourlyRate = false;
            foreach ($columns as $column) {
                if ($column['name'] === 'hourly_rate') {
                    $hasHourlyRate = true;
                    break;
                }
            }
            
            if (!$hasHourlyRate) {
                $db->exec("ALTER TABLE employees ADD COLUMN hourly_rate DECIMAL(10,2) DEFAULT 15.00");
            }
            
            // Create time_entries table for SQLite
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
        }
    } catch (Exception $e) {
        error_log("Migration warning: " . $e->getMessage());
    }
}

// Verify payroll_db structure - ensure hourly_rate column exists
try {
    $stmt = $db->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = 'payroll_db' AND TABLE_NAME = 'employees' AND COLUMN_NAME = 'hourly_rate'");
    $stmt->execute();
    if (!$stmt->fetchColumn()) {
        $db->exec("ALTER TABLE employees ADD COLUMN hourly_rate DECIMAL(10,2) DEFAULT 15.00");
        writeDebugLog("Added hourly_rate column to payroll_db.employees");
    }
    // Ensure contact-related columns exist
    $columnsToAdd = [
        ['name' => 'email', 'ddl' => "ALTER TABLE employees ADD COLUMN email VARCHAR(191) NULL"],
        ['name' => 'phone', 'ddl' => "ALTER TABLE employees ADD COLUMN phone VARCHAR(50) NULL"],
        ['name' => 'address', 'ddl' => "ALTER TABLE employees ADD COLUMN address TEXT NULL"],
        ['name' => 'emergency_contact', 'ddl' => "ALTER TABLE employees ADD COLUMN emergency_contact VARCHAR(100) NULL"],
        // emergency_phone removed per request
    ];
    foreach ($columnsToAdd as $col) {
        $cstmt = $db->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = 'payroll_db' AND TABLE_NAME = 'employees' AND COLUMN_NAME = ?");
        $cstmt->execute([$col['name']]);
        if (!$cstmt->fetchColumn()) {
            try { $db->exec($col['ddl']); writeDebugLog("Added column '{$col['name']}' to employees"); } catch (Exception $e) { writeDebugLog("Add column '{$col['name']}' failed: ".$e->getMessage()); }
        }
    }
} catch (Exception $e) {
    writeDebugLog("Table verification: " . $e->getMessage());
}

// Create compensation data for existing employees
function createSampleCompensationData($db) {
    try {
        // Check if employees table exists and has data
        $emp_count_stmt = $db->query("SELECT COUNT(*) FROM employees");
        $total_employees = $emp_count_stmt->fetchColumn();
        
        if ($total_employees == 0) {
            return; // No employees to create compensation data for
        }
        
        // Get existing employees (now including any we just created)
        $emp_stmt = $db->query("SELECT id, name, COALESCE(hourly_rate, 20.00) as hourly_rate FROM employees WHERE status = 'Active' LIMIT 5");
        $employees = $emp_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($employees)) {
            error_log("No active employees found even after creation attempt");
            return false;
        }
        
        $start_date = date('Y-m-01'); // First day of current month
        $end_date = date('Y-m-t');   // Last day of current month
        
        // Create sample time entries for the month
        foreach ($employees as $emp) {
            // Create 15-20 work days for each employee
            for ($i = 1; $i <= 20; $i++) {
                $work_date = date('Y-m-' . sprintf('%02d', $i));
                if (date('N', strtotime($work_date)) <= 5) { // Weekdays only
                    
                    // Check if time entry already exists
                    $check_stmt = $db->prepare("SELECT id FROM time_entries WHERE employee_id = ? AND entry_date = ?");
                    $check_stmt->execute([$emp['id'], $work_date]);
                    
                    if (!$check_stmt->fetch()) {
                        // Random work hours (7-9 hours)
                        $hours = rand(7, 9) + (rand(0, 3) * 0.5); // 7.0 to 9.5 hours
                        $clock_in = '08:00:00';
                        $clock_out_time = strtotime($clock_in) + ($hours * 3600);
                        $clock_out = date('H:i:s', $clock_out_time);
                        
                        $time_stmt = $db->prepare("
                            INSERT INTO time_entries (employee_id, entry_date, clock_in, clock_out, total_hours, status) 
                            VALUES (?, ?, ?, ?, ?, 'Closed')
                        ");
                        $time_stmt->execute([$emp['id'], $work_date, $clock_in, $clock_out, $hours]);
                    }
                }
            }
            
            // Create sample payroll record
            $payroll_check = $db->prepare("SELECT id FROM payrolls WHERE employee_id = ? AND pay_period_start = ? AND pay_period_end = ?");
            $payroll_check->execute([$emp['id'], $start_date, $end_date]);
            
            if (!$payroll_check->fetch()) {
                $hourly_rate = floatval($emp['hourly_rate'] ?: 20.00);
                $regular_hours = rand(160, 180); // Typical monthly hours
                $overtime_hours = rand(0, 20);
                $gross_pay = ($regular_hours * $hourly_rate) + ($overtime_hours * $hourly_rate * 1.5);
                $taxes = $gross_pay * 0.10; // 10% tax
                $deductions = $gross_pay * 0.09; // 9% deductions (SSS, PhilHealth, Pag-IBIG)
                $net_pay = $gross_pay - $taxes - $deductions;
                
                $payroll_stmt = $db->prepare("
                    INSERT INTO payrolls (employee_id, pay_period_start, pay_period_end, regular_hours, overtime_hours, 
                                         gross_pay, taxes, deductions, net_pay, status) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'approved')
                ");
                $payroll_stmt->execute([$emp['id'], $start_date, $end_date, $regular_hours, $overtime_hours, 
                                       $gross_pay, $taxes, $deductions, $net_pay]);
            }
        }
        
        return true;
    } catch (Exception $e) {
        error_log("Sample data creation error: " . $e->getMessage());
        return false;
    }
}

// Generate Employee Compensation Report
function generateCompensationReport($db, $report_type = 'monthly', $start_date = null, $end_date = null, $dbType = 'mysql') {
    if (!$start_date) $start_date = date('Y-m-01');
    if (!$end_date) $end_date = date('Y-m-t');
    
    $report = [
        'summary' => [
            'total_employees' => 0,
            'total_hours' => 0,
            'total_compensation' => 0,
            'average_hourly_rate' => 0
        ],
        'employees' => []
    ];
    
    // Get all active employees with their compensation data
    $sql = "SELECT e.id, e.name, e.position, e.department, COALESCE(e.start_date, e.hire_date) as start_date,
                   COALESCE(e.hourly_rate, 15.00) as hourly_rate,
                   COUNT(t.id) as days_worked,
                   COALESCE(SUM(t.total_hours), 0) as total_hours,
                   COALESCE(SUM(t.total_hours * e.hourly_rate), 0) as gross_pay,
                   COALESCE(AVG(t.total_hours), 0) as avg_daily_hours
            FROM employees e
            LEFT JOIN time_entries t ON e.id = t.employee_id 
                AND t.entry_date BETWEEN ? AND ? 
                AND t.status = 'Closed'
            WHERE e.status = 'Active'
            GROUP BY e.id, e.name, e.position, e.department, COALESCE(e.start_date, e.hire_date), e.hourly_rate
            ORDER BY e.name";
    
    try {
        $stmt = $db->prepare($sql);
        $stmt->execute([$start_date, $end_date]);
        $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Debug: Log query results
        error_log("Compensation report: Found " . count($employees) . " employees");
        
        // If no data found, create sample data
        if (empty($employees) || array_sum(array_column($employees, 'total_hours')) == 0) {
            error_log("Creating sample compensation data...");
            $created = createSampleCompensationData($db);
            if ($created) {
                error_log("Sample data created successfully, re-running query");
                // Re-run the query after creating sample data
                $stmt->execute([$start_date, $end_date]);
                $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
                error_log("After sample data: Found " . count($employees) . " employees");
            } else {
                error_log("Failed to create sample compensation data");
            }
        }
        
        foreach ($employees as $emp) {
            // Calculate additional compensation metrics
            $overtime_hours = max(0, $emp['total_hours'] - 40); // Assuming 40hr work week
            $regular_hours = min($emp['total_hours'], 40);
            $overtime_pay = $overtime_hours * $emp['hourly_rate'] * 1.5; // Time and a half
            $regular_pay = $regular_hours * $emp['hourly_rate'];
            $total_pay = $regular_pay + $overtime_pay;
            
            // Get payroll deductions for this period (MySQL payroll_db)
            $deduction_sql = "SELECT COALESCE(SUM(deductions), 0) as total_deductions
                             FROM payrolls p
                             WHERE p.employee_id = ? 
                             AND p.pay_period_start >= ? AND p.pay_period_end <= ?";
            $deduction_stmt = $db->prepare($deduction_sql);
            $deduction_stmt->execute([$emp['id'], $start_date, $end_date]);
            $deductions = $deduction_stmt->fetchColumn() ?: 0;
            
            $employee_data = [
                'id' => $emp['id'],
                'name' => $emp['name'],
                'position' => $emp['position'],
                'department' => $emp['department'],
                'start_date' => $emp['start_date'],
                'hourly_rate' => floatval($emp['hourly_rate']),
                'days_worked' => intval($emp['days_worked']),
                'total_hours' => floatval($emp['total_hours']),
                'regular_hours' => $regular_hours,
                'overtime_hours' => $overtime_hours,
                'regular_pay' => $regular_pay,
                'overtime_pay' => $overtime_pay,
                'gross_pay' => $total_pay,
                'deductions' => floatval($deductions),
                'net_pay' => $total_pay - $deductions,
                'avg_daily_hours' => floatval($emp['avg_daily_hours']),
                'attendance_rate' => $emp['days_worked'] > 0 ? ($emp['days_worked'] / 30) * 100 : 0 // Rough estimate
            ];
            
            $report['employees'][] = $employee_data;
            
            // Update summary totals
            $report['summary']['total_employees']++;
            $report['summary']['total_hours'] += $employee_data['total_hours'];
            $report['summary']['total_compensation'] += $employee_data['gross_pay'];
        }
        
        // Calculate averages
        if ($report['summary']['total_employees'] > 0) {
            $report['summary']['average_hourly_rate'] = $report['summary']['total_compensation'] / $report['summary']['total_hours'];
            $report['summary']['avg_hours_per_employee'] = $report['summary']['total_hours'] / $report['summary']['total_employees'];
            $report['summary']['avg_compensation_per_employee'] = $report['summary']['total_compensation'] / $report['summary']['total_employees'];
        }
        
    } catch (Exception $e) {
        error_log("Compensation report error: " . $e->getMessage());
    }
    
    return $report;
}

// Generate Payroll Summary Report (grouped totals over time)
function generatePayrollSummaryReport($db, $start_date, $end_date, $group_by = 'monthly') {
    if (!$start_date) $start_date = date('Y-m-01');
    if (!$end_date) $end_date = date('Y-m-t');

    $dateExpr = $group_by === 'daily' ? "DATE_FORMAT(pay_period_start, '%Y-%m-%d')" : "DATE_FORMAT(pay_period_start, '%Y-%m')";
    $sql = "SELECT {$dateExpr} AS period,
                   COUNT(*) AS records,
                   COALESCE(SUM(gross_pay),0) AS gross_pay,
                   COALESCE(SUM(taxes),0) AS taxes,
                   COALESCE(SUM(deductions),0) AS deductions,
                   COALESCE(SUM(net_pay),0) AS net_pay
            FROM payrolls
            WHERE pay_period_start >= ? AND pay_period_end <= ?
            GROUP BY period
            ORDER BY period";

    $summary = [
        'totals' => ['records' => 0, 'gross_pay' => 0, 'taxes' => 0, 'deductions' => 0, 'net_pay' => 0],
        'rows' => []
    ];
    try {
        $stmt = $db->prepare($sql);
        $stmt->execute([$start_date, $end_date]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $summary['rows'][] = $r;
            $summary['totals']['records'] += (int)$r['records'];
            $summary['totals']['gross_pay'] += (float)$r['gross_pay'];
            $summary['totals']['taxes'] += (float)$r['taxes'];
            $summary['totals']['deductions'] += (float)$r['deductions'];
            $summary['totals']['net_pay'] += (float)$r['net_pay'];
        }
    } catch (Exception $e) {
        error_log('Payroll summary report error: ' . $e->getMessage());
    }
    return $summary;
}

// Generate Time & Attendance Report
function generateTimeAttendanceReport($db, $start_date, $end_date) {
    if (!$start_date) $start_date = date('Y-m-01');
    if (!$end_date) $end_date = date('Y-m-t');
    $sql = "SELECT e.id, e.name, e.department, e.position,
                   COALESCE(SUM(t.total_hours),0) AS total_hours,
                   COUNT(DISTINCT CASE WHEN t.entry_date BETWEEN ? AND ? THEN t.entry_date END) AS days_worked,
                   COALESCE(AVG(NULLIF(t.total_hours,0)),0) AS avg_daily_hours
            FROM employees e
            LEFT JOIN time_entries t ON e.id = t.employee_id AND t.entry_date BETWEEN ? AND ?
            WHERE e.status = 'Active'
            GROUP BY e.id, e.name, e.department, e.position
            ORDER BY e.name";

    $report = ['rows' => [], 'totals' => ['employees' => 0, 'hours' => 0, 'days' => 0]];
    try {
        $stmt = $db->prepare($sql);
        // parameters for COUNT DISTINCT and JOIN range
        $stmt->execute([$start_date, $end_date, $start_date, $end_date]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $r['total_hours'] = (float)$r['total_hours'];
            $r['days_worked'] = (int)$r['days_worked'];
            $r['avg_daily_hours'] = (float)$r['avg_daily_hours'];
            $report['rows'][] = $r;
            $report['totals']['employees']++;
            $report['totals']['hours'] += $r['total_hours'];
            $report['totals']['days'] += $r['days_worked'];
        }
    } catch (Exception $e) {
        error_log('Time & Attendance report error: ' . $e->getMessage());
    }
    return $report;
}

// Check if user is logged in
function isLoggedIn() {
    return isset($_SESSION['user_id']) && isset($_SESSION['username']);
}

// Get current page
$page = $_GET['page'] ?? (isLoggedIn() ? 'dashboard' : 'login');

// Authentication pages (login/signup) - accessible without login
if (in_array($page, ['login', 'signup'])) {
    // If already logged in, redirect to dashboard
    if (isLoggedIn()) {
        header('Location: index.php?page=dashboard');
        exit;
    }
} else {
    // All other pages require login
    if (!isLoggedIn()) {
        header('Location: index.php?page=login');
        exit;
    }
}

// Handle authentication actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'login') {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        
        if ($username && $password) {
            try {
                $stmt = $db->prepare("SELECT id, username, password, role FROM users WHERE username = ?");
                $stmt->execute([$username]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($user && password_verify($password, $user['password'])) {
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['role'] = $user['role'];
                    header('Location: index.php?page=dashboard');
                    exit;
                } else {
                    $_SESSION['error'] = "Invalid username or password.";
                }
            } catch (Exception $e) {
                $_SESSION['error'] = "Login error: " . $e->getMessage();
            }
        } else {
            $_SESSION['error'] = "Please fill in all fields.";
        }
        header('Location: index.php?page=login');
        exit;
        
    } elseif ($action === 'signup') {
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        
        if ($username && $email && $password && $confirm_password) {
            if ($password !== $confirm_password) {
                $_SESSION['error'] = "Passwords do not match.";
            } else {
                try {
                    // Check if username or email already exists
                    $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE username = ? OR email = ?");
                    $stmt->execute([$username, $email]);
                    $exists = $stmt->fetchColumn();
                    
                    if ($exists > 0) {
                        $_SESSION['error'] = "Username or email already exists.";
                    } else {
                        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                        $stmt = $db->prepare("INSERT INTO users (username, email, password, role) VALUES (?, ?, ?, 'admin')");
                        $stmt->execute([$username, $email, $hashedPassword]);
                        $_SESSION['message'] = "Account created successfully. Please login.";
                        header('Location: index.php?page=login');
                        exit;
                    }
                } catch (Exception $e) {
                    $_SESSION['error'] = "Signup error: " . $e->getMessage();
                }
            }
        } else {
            $_SESSION['error'] = "Please fill in all fields.";
        }
        header('Location: index.php?page=signup');
        exit;
        
    } elseif ($action === 'logout') {
        session_destroy();
        header('Location: index.php?page=login');
        exit;
    } elseif ($action === 'add_employee') {
        $name = trim($_POST['name'] ?? '');
        $position = trim($_POST['position'] ?? '');
        $department = trim($_POST['department'] ?? '');
        $start_date = trim($_POST['start_date'] ?? '');
        $phone = trim($_POST['phone'] ?? '');

        $hourly_rate = floatval($_POST['hourly_rate'] ?? 15.00);
        
        if ($name !== '') {
            // Insert using DB-aware helper to support MySQL (hire_date) and SQLite (start_date)
            try {
                insertEmployee($db, [
                    'name' => $name,
                    'position' => $position,
                    'department' => $department,
                    'date' => $start_date,
                    'hourly_rate' => $hourly_rate,
                    'phone' => $phone,
                ], $dbType);
                writeDebugLog("ADD_EMPLOYEE: name={$name}, department={$department}, start_date={$start_date}, hourly_rate={$hourly_rate}");
            } catch (Exception $e) {
                $_SESSION['error'] = 'Failed to add employee: ' . $e->getMessage();
                writeDebugLog("ERROR add_employee: " . $e->getMessage());
                header('Location: index.php?page=people');
                exit;
            }
            $_SESSION['message'] = "Employee added.";
            writeDebugLog("ADD_EMPLOYEE_SUCCESS: {$name}");
        } else {
            $_SESSION['error'] = "Name is required.";
        }
        header('Location: index.php?page=people');
        exit;
    } elseif ($action === 'edit_employee') {
        $id = (int)($_POST['employee_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $position = trim($_POST['position'] ?? '');
        $department = trim($_POST['department'] ?? '');
        $hourly_rate = floatval($_POST['hourly_rate'] ?? 15.00);
        $status = $_POST['status'] ?? 'active';
        $phone = trim($_POST['phone'] ?? '');
        // emergency_phone removed per schema change
        
        if ($name !== '' && $id > 0) {
            try {
                $stmt = $db->prepare("UPDATE employees SET name = ?, position = ?, department = ?, hourly_rate = ?, status = ?, phone = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                $stmt->execute([$name, $position, $department, $hourly_rate, $status, $phone, $id]);
                $_SESSION['message'] = "Employee updated successfully.";
                writeDebugLog("EDIT_EMPLOYEE_SUCCESS: ID={$id}, name={$name}");
            } catch (Exception $e) {
                $_SESSION['error'] = 'Failed to update employee: ' . $e->getMessage();
                writeDebugLog("ERROR edit_employee: " . $e->getMessage());
            }
        } else {
            $_SESSION['error'] = "Invalid employee data.";
        }
        header('Location: index.php?page=people');
        exit;
    } elseif ($action === 'delete_employee') {
        $id = (int)($_POST['employee_id'] ?? 0);
        if ($id > 0) {
            try {
                // Check if employee has related records
                $timeCount = $db->prepare("SELECT COUNT(*) FROM time_entries WHERE employee_id = ?");
                $timeCount->execute([$id]);
                $payrollCount = $db->prepare("SELECT COUNT(*) FROM payrolls WHERE employee_id = ?");
                $payrollCount->execute([$id]);
                
                if ($timeCount->fetchColumn() > 0 || $payrollCount->fetchColumn() > 0) {
                    // Soft delete - set status to inactive to preserve data integrity
                    $stmt = $db->prepare("UPDATE employees SET status = 'inactive', updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                    $stmt->execute([$id]);
                    $_SESSION['message'] = "Employee has been deactivated successfully. Their records are preserved for historical data integrity.";
                } else {
                    // Hard delete if no related records
                    $stmt = $db->prepare("DELETE FROM employees WHERE id = ?");
                    $stmt->execute([$id]);
                    $_SESSION['message'] = "Employee has been permanently deleted.";
                }
                writeDebugLog("DELETE_EMPLOYEE: ID={$id}");
            } catch (Exception $e) {
                $_SESSION['error'] = 'Failed to delete employee: ' . $e->getMessage();
                writeDebugLog("ERROR delete_employee: " . $e->getMessage());
            }
        }
        header('Location: index.php?page=people');
        exit;
    } elseif ($action === 'reactivate_employee') {
        $id = (int)($_POST['employee_id'] ?? 0);
        if ($id > 0) {
            try {
                $stmt = $db->prepare("UPDATE employees SET status = 'Active', updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                $stmt->execute([$id]);
                $_SESSION['message'] = "Employee has been reactivated successfully.";
                writeDebugLog("REACTIVATE_EMPLOYEE: ID={$id}");
            } catch (Exception $e) {
                $_SESSION['error'] = 'Failed to reactivate employee: ' . $e->getMessage();
                writeDebugLog("ERROR reactivate_employee: " . $e->getMessage());
            }
        }
        header('Location: index.php?page=people');
        exit;
    } elseif ($action === 'edit_time_entry') {
        $id = (int)($_POST['entry_id'] ?? 0);
        $employee_id = (int)($_POST['employee_id'] ?? 0);
        $entry_date = $_POST['entry_date'] ?? '';
        $clock_in = $_POST['clock_in'] ?? null;
        $clock_out = $_POST['clock_out'] ?? null;
        $notes = trim($_POST['notes'] ?? '');
        
        if ($id > 0 && $employee_id > 0 && $entry_date !== '') {
            try {
                // Calculate total hours
                $total_hours = 0;
                if ($clock_in && $clock_out) {
                    $start = new DateTime($clock_in);
                    $end = new DateTime($clock_out);
                    $diff = $end->diff($start);
                    $total_hours = $diff->h + ($diff->i / 60);
                }
                
                $status = ($clock_in && $clock_out) ? 'Closed' : 'Open';
                $stmt = $db->prepare("UPDATE time_entries SET employee_id = ?, entry_date = ?, clock_in = ?, clock_out = ?, total_hours = ?, notes = ?, status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                $stmt->execute([$employee_id, $entry_date, $clock_in, $clock_out, $total_hours, $notes, $status, $id]);
                $_SESSION['message'] = "Time entry updated successfully.";
                writeDebugLog("EDIT_TIME_ENTRY_SUCCESS: ID={$id}");
            } catch (Exception $e) {
                $_SESSION['error'] = 'Failed to update time entry: ' . $e->getMessage();
                writeDebugLog("ERROR edit_time_entry: " . $e->getMessage());
            }
        } else {
            $_SESSION['error'] = "Invalid time entry data.";
        }
        header('Location: index.php?page=timecard');
        exit;
    } elseif ($action === 'delete_time_entry') {
        $id = (int)($_POST['entry_id'] ?? 0);
        if ($id > 0) {
            try {
                $stmt = $db->prepare("DELETE FROM time_entries WHERE id = ?");
                $stmt->execute([$id]);
                $_SESSION['message'] = "Time entry deleted successfully.";
                writeDebugLog("DELETE_TIME_ENTRY: ID={$id}");
            } catch (Exception $e) {
                $_SESSION['error'] = 'Failed to delete time entry: ' . $e->getMessage();
                writeDebugLog("ERROR delete_time_entry: " . $e->getMessage());
            }
        }
        header('Location: index.php?page=timecard');
        exit;
    } elseif ($action === 'edit_payroll') {
        $id = (int)($_POST['payroll_id'] ?? 0);
        $employee_id = (int)($_POST['employee_id'] ?? 0);
        $regular_hours = floatval($_POST['regular_hours'] ?? 0);
        $overtime_hours = floatval($_POST['overtime_hours'] ?? 0);
        $gross_pay = floatval($_POST['gross_pay'] ?? 0);
        $taxes = floatval($_POST['taxes'] ?? 0);
        $deductions = floatval($_POST['deductions'] ?? 0);
        $net_pay = floatval($_POST['net_pay'] ?? 0);
        $status = $_POST['status'] ?? 'draft';
        $notes = trim($_POST['notes'] ?? '');
        
        if ($id > 0 && $employee_id > 0) {
            try {
                if ($dbType === 'mysql') {
                    $stmt = $db->prepare("UPDATE payrolls SET employee_id = ?, regular_hours = ?, overtime_hours = ?, gross_pay = ?, taxes = ?, deductions = ?, net_pay = ?, status = ?, notes = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                } else {
                    $stmt = $db->prepare("UPDATE payrolls SET employee_id = ?, deductions = ?, net_pay = ?, status = ? WHERE id = ?");
                }
                
                if ($dbType === 'mysql') {
                    $stmt->execute([$employee_id, $regular_hours, $overtime_hours, $gross_pay, $taxes, $deductions, $net_pay, $status, $notes, $id]);
                } else {
                    $stmt->execute([$employee_id, $deductions, $net_pay, $status, $id]);
                }
                $_SESSION['message'] = "Payroll updated successfully.";
                writeDebugLog("EDIT_PAYROLL_SUCCESS: ID={$id}");
            } catch (Exception $e) {
                $_SESSION['error'] = 'Failed to update payroll: ' . $e->getMessage();
                writeDebugLog("ERROR edit_payroll: " . $e->getMessage());
            }
        } else {
            $_SESSION['error'] = "Invalid payroll data.";
        }
        header('Location: index.php?page=payroll');
        exit;
    } elseif ($action === 'add_payroll') {
        $employee_id = (int)($_POST['employee_id'] ?? 0);
        $pay_period_start = $_POST['pay_period_start'] ?? '';
        $pay_period_end = $_POST['pay_period_end'] ?? '';
        $regular_hours = floatval($_POST['regular_hours'] ?? 0);
        $overtime_hours = floatval($_POST['overtime_hours'] ?? 0);
        $gross_pay = floatval($_POST['gross_pay'] ?? 0);
        $taxes = floatval($_POST['taxes'] ?? 0);
        $deductions = floatval($_POST['deductions'] ?? 0);
        $net_pay = floatval($_POST['net_pay'] ?? 0);
        $status = $_POST['status'] ?? 'draft';
        $notes = trim($_POST['notes'] ?? '');

        if ($employee_id > 0 && $pay_period_start && $pay_period_end) {
            try {
                $stmt = $db->prepare("INSERT INTO payrolls (employee_id, pay_period_start, pay_period_end, regular_hours, overtime_hours, gross_pay, taxes, deductions, net_pay, status, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$employee_id, $pay_period_start, $pay_period_end, $regular_hours, $overtime_hours, $gross_pay, $taxes, $deductions, $net_pay, $status, $notes]);
                $_SESSION['message'] = 'Payroll record created.';
            } catch (Exception $e) {
                $_SESSION['error'] = 'Failed to create payroll: ' . $e->getMessage();
            }
        } else {
            $_SESSION['error'] = 'Please fill in employee and pay period.';
        }
        header('Location: index.php?page=payroll');
        exit;
    } elseif ($action === 'delete_payroll') {
        $id = (int)($_POST['payroll_id'] ?? 0);
        if ($id > 0) {
            try {
                $stmt = $db->prepare("DELETE FROM payrolls WHERE id = ?");
                $stmt->execute([$id]);
                $_SESSION['message'] = "Payroll deleted successfully.";
                writeDebugLog("DELETE_PAYROLL: ID={$id}");
            } catch (Exception $e) {
                $_SESSION['error'] = 'Failed to delete payroll: ' . $e->getMessage();
                writeDebugLog("ERROR delete_payroll: " . $e->getMessage());
            }
        }
        header('Location: index.php?page=payroll');
        exit;
    } elseif ($action === 'update_payroll_status') {
        $id = (int)($_POST['payroll_id'] ?? 0);
        $status = $_POST['status'] ?? 'Pending';
        $stmt = $db->prepare("UPDATE payrolls SET status = ? WHERE id = ?");
        $stmt->execute([$status, $id]);
        $_SESSION['message'] = "Payroll status updated.";
        header('Location: index.php?page=payroll');
        exit;
    } elseif ($action === 'start_pay_run') {
        $month = $_POST['month'] ?? date('Y-m');
        $start_date = date('Y-m-01', strtotime($month . '-01'));
        $end_date = date('Y-m-t', strtotime($start_date));

        $employees = $db->query("SELECT id FROM employees")->fetchAll(PDO::FETCH_COLUMN);
        // Insert payroll records using the database-appropriate column names
        // Insert payroll records for all employees (MySQL payroll_db)
        $stmt = $db->prepare("INSERT INTO payrolls (employee_id, pay_period_start, pay_period_end, deductions, net_pay, status) VALUES (?, ?, ?, 0, 0, 'Pending')");
        foreach ($employees as $eid) {
            $stmt->execute([$eid, $start_date, $end_date]);
        }
        $_SESSION['message'] = "Started pay run for $month";
        header('Location: index.php?page=payroll');
        exit;
    } elseif ($action === 'clock_in') {
        $employee_id = (int)($_POST['employee_id'] ?? 0);
        $today = date('Y-m-d');
        $time = date('H:i:s');
        
        // Check if already clocked in today (MySQL payroll_db)
        $stmt = $db->prepare("SELECT id FROM time_entries WHERE employee_id = ? AND DATE(entry_date) = ? AND clock_out IS NULL");
        $stmt->execute([$employee_id, $today]);
        $existing = $stmt->fetch();
        
        if ($existing) {
            $_SESSION['error'] = "Already clocked in today.";
        } else {
            // Get employee name for display
            $empStmt = $db->prepare("SELECT name FROM employees WHERE id = ?");
            $empStmt->execute([$employee_id]);
            $empName = $empStmt->fetchColumn() ?: 'Unknown Employee';
            
            $stmt = $db->prepare("INSERT INTO time_entries (employee_id, entry_date, clock_in, status) VALUES (?, ?, ?, 'Open')");
            $stmt->execute([$employee_id, $today, $time]);
            $_SESSION['message'] = "✅ $empName clocked in successfully at $time";
            $_SESSION['clock_action'] = ['type' => 'clock_in', 'employee' => $empName, 'time' => $time];
        }
        header('Location: index.php?page=timecard');
        exit;
    } elseif ($action === 'clock_out') {
        $employee_id = (int)($_POST['employee_id'] ?? 0);
        $today = date('Y-m-d');
        $time = date('H:i:s');
        
        // Find open entry for today (MySQL payroll_db)
        $stmt = $db->prepare("SELECT id, clock_in FROM time_entries WHERE employee_id = ? AND DATE(entry_date) = ? AND clock_out IS NULL");
        $stmt->execute([$employee_id, $today]);
        $entry = $stmt->fetch();
        
        if ($entry) {
            // Get employee name for display
            $empStmt = $db->prepare("SELECT name FROM employees WHERE id = ?");
            $empStmt->execute([$employee_id]);
            $empName = $empStmt->fetchColumn() ?: 'Unknown Employee';
            
            $clock_in = new DateTime($entry['clock_in']);
            $clock_out = new DateTime($time);
            $diff = $clock_out->diff($clock_in);
            $hours = $diff->h + ($diff->i / 60);
            
            $stmt = $db->prepare("UPDATE time_entries SET clock_out = ?, total_hours = ?, status = 'Closed' WHERE id = ?");
            $stmt->execute([$time, $hours, $entry['id']]);
            $_SESSION['message'] = "✅ $empName clocked out successfully at $time (" . number_format($hours, 2) . " hours)";
            $_SESSION['clock_action'] = ['type' => 'clock_out', 'employee' => $empName, 'time' => $time, 'hours' => $hours];
        } else {
            $_SESSION['error'] = "No open clock-in found for today.";
        }
        header('Location: index.php?page=timecard');
        exit;
    } elseif ($action === 'add_time_entry') {
        $employee_id = (int)($_POST['employee_id'] ?? 0);
        $entry_date = $_POST['entry_date'] ?? '';
        $clock_in = $_POST['clock_in'] ?? null;
        $clock_out = $_POST['clock_out'] ?? null;
        $notes = trim($_POST['notes'] ?? '');
        
        if ($employee_id > 0 && $entry_date !== '') {
            try {
                // Calculate total hours
                $total_hours = 0;
                if ($clock_in && $clock_out) {
                    $start = new DateTime($clock_in);
                    $end = new DateTime($clock_out);
                    $diff = $end->diff($start);
                    $total_hours = $diff->h + ($diff->i / 60) + ($diff->s / 3600);
                }
                
                $status = ($clock_in && $clock_out) ? 'Closed' : 'Open';
                $stmt = $db->prepare("INSERT INTO time_entries (employee_id, entry_date, clock_in, clock_out, total_hours, notes, status) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$employee_id, $entry_date, $clock_in, $clock_out, $total_hours, $notes, $status]);
                $_SESSION['message'] = "Time entry added successfully.";
                writeDebugLog("ADD_TIME_ENTRY_SUCCESS: employee_id={$employee_id}, date={$entry_date}");
            } catch (Exception $e) {
                $_SESSION['error'] = 'Failed to add time entry: ' . $e->getMessage();
                writeDebugLog("ERROR add_time_entry: " . $e->getMessage());
            }
        } else {
            $_SESSION['error'] = "Employee and date are required.";
        }
        header('Location: index.php?page=timecard');
        exit;
    } elseif ($action === 'edit_time_entry') {
        $id = (int)($_POST['entry_id'] ?? 0);
        $employee_id = (int)($_POST['employee_id'] ?? 0);
        $entry_date = $_POST['entry_date'] ?? '';
        $clock_in = $_POST['clock_in'] ?? null;
        $clock_out = $_POST['clock_out'] ?? null;
        $notes = trim($_POST['notes'] ?? '');
        
        if ($id > 0 && $employee_id > 0 && $entry_date !== '') {
            try {
                // Calculate total hours
                $total_hours = 0;
                if ($clock_in && $clock_out) {
                    $start = new DateTime($clock_in);
                    $end = new DateTime($clock_out);
                    $diff = $end->diff($start);
                    $total_hours = $diff->h + ($diff->i / 60) + ($diff->s / 3600);
                }
                
                $status = ($clock_in && $clock_out) ? 'Closed' : 'Open';
                $stmt = $db->prepare("UPDATE time_entries SET employee_id = ?, entry_date = ?, clock_in = ?, clock_out = ?, total_hours = ?, notes = ?, status = ? WHERE id = ?");
                $stmt->execute([$employee_id, $entry_date, $clock_in, $clock_out, $total_hours, $notes, $status, $id]);
                $_SESSION['message'] = "Time entry updated successfully.";
                writeDebugLog("EDIT_TIME_ENTRY_SUCCESS: ID={$id}");
            } catch (Exception $e) {
                $_SESSION['error'] = 'Failed to update time entry: ' . $e->getMessage();
                writeDebugLog("ERROR edit_time_entry: " . $e->getMessage());
            }
        } else {
            $_SESSION['error'] = "Invalid time entry data.";
        }
        header('Location: index.php?page=timecard');
        exit;
    } elseif ($action === 'delete_time_entry') {
        $id = (int)($_POST['entry_id'] ?? 0);
        if ($id > 0) {
            try {
                $stmt = $db->prepare("DELETE FROM time_entries WHERE id = ?");
                $stmt->execute([$id]);
                $_SESSION['message'] = "Time entry deleted successfully.";
                writeDebugLog("DELETE_TIME_ENTRY: ID={$id}");
            } catch (Exception $e) {
                $_SESSION['error'] = 'Failed to delete time entry: ' . $e->getMessage();
                writeDebugLog("ERROR delete_time_entry: " . $e->getMessage());
            }
        }
        header('Location: index.php?page=timecard');
        exit;
    } elseif ($action === 'bulk_clock_out') {
        $today = date('Y-m-d');
        $time = date('H:i:s');
        
        try {
            // Get all open entries for today
            $stmt = $db->prepare("SELECT id, employee_id, clock_in FROM time_entries WHERE DATE(entry_date) = ? AND clock_out IS NULL AND status = 'Open'");
            $stmt->execute([$today]);
            $openEntries = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $updatedCount = 0;
            foreach ($openEntries as $entry) {
                if ($entry['clock_in']) {
                    $clock_in = new DateTime($entry['clock_in']);
                    $clock_out = new DateTime($time);
                    $diff = $clock_out->diff($clock_in);
                    $hours = $diff->h + ($diff->i / 60);
                    
                    $stmt = $db->prepare("UPDATE time_entries SET clock_out = ?, total_hours = ?, status = 'Closed' WHERE id = ?");
                    $stmt->execute([$time, $hours, $entry['id']]);
                    $updatedCount++;
                }
            }
            
            $_SESSION['message'] = "Clocked out {$updatedCount} employees at {$time}.";
            writeDebugLog("BULK_CLOCK_OUT: {$updatedCount} entries updated");
        } catch (Exception $e) {
            $_SESSION['error'] = 'Failed to bulk clock out: ' . $e->getMessage();
            writeDebugLog("ERROR bulk_clock_out: " . $e->getMessage());
        }
        header('Location: index.php?page=timecard');
        exit;
    } elseif ($action === 'create_sample_data') {
        $success = createSampleCompensationData($db);
        if ($success) {
            $_SESSION['message'] = 'Sample payroll and time entry data created successfully!';
        } else {
            $_SESSION['error'] = 'Failed to create sample data. Please check if employees exist.';
        }
        header('Location: index.php?page=reports');
        exit;
    } elseif ($action === 'generate_compensation_report') {
        $report_type = $_POST['report_type'] ?? 'monthly';
        $start_date = $_POST['start_date'] ?? date('Y-m-01');
        $end_date = $_POST['end_date'] ?? date('Y-m-t');
        
        $_SESSION['report_data'] = generateCompensationReport($db, $report_type, $start_date, $end_date, $dbType);
        $_SESSION['report_period'] = ['start' => $start_date, 'end' => $end_date, 'type' => $report_type];
        header('Location: index.php?page=reports&view=compensation');
        exit;
    } elseif ($action === 'generate_payroll_summary') {
        $start_date = $_POST['start_date'] ?? date('Y-m-01');
        $end_date = $_POST['end_date'] ?? date('Y-m-t');
        $group_by = $_POST['group_by'] ?? 'monthly';
        $_SESSION['payroll_summary'] = generatePayrollSummaryReport($db, $start_date, $end_date, $group_by);
        $_SESSION['report_period'] = ['start' => $start_date, 'end' => $end_date, 'group' => $group_by];
        header('Location: index.php?page=reports&view=payroll_summary');
        exit;
    } elseif ($action === 'generate_time_attendance_report') {
        $start_date = $_POST['start_date'] ?? date('Y-m-01');
        $end_date = $_POST['end_date'] ?? date('Y-m-t');
        $_SESSION['time_attendance'] = generateTimeAttendanceReport($db, $start_date, $end_date);
        $_SESSION['report_period'] = ['start' => $start_date, 'end' => $end_date];
        header('Location: index.php?page=reports&view=time_attendance');
        exit;
    }
}

// Helper functions to fetch data
function totalEmployees($db) {
    return (int)$db->query("SELECT COUNT(*) FROM employees")->fetchColumn();
}
function pendingPayrolls($db) {
    return (int)$db->query("SELECT COUNT(*) FROM payrolls WHERE status = 'Pending'")->fetchColumn();
}
function upcomingPayDate($db, $dbType = 'sqlite') {
    // Return the earliest pending payroll date using DB-specific column names
    if ($dbType === 'mysql') {
        $sql = "SELECT MIN(pay_period_start) FROM payrolls WHERE status = 'Pending'";
    } else {
        $sql = "SELECT MIN(start_date) FROM payrolls WHERE status = 'Pending'";
    }
    $row = $db->query($sql)->fetchColumn();
    return $row ?: null;
}
function payrollSummary($db, $dbType = 'sqlite') {
    // Return monthly totals for chart (group by month) with database-specific date functions
    if ($dbType === 'mysql') {
        // MySQL uses pay_period_start in payrolls schema
        $sql = "SELECT DATE_FORMAT(pay_period_start, '%Y-%m') as month, SUM(net_pay + deductions) as total
                FROM payrolls GROUP BY month ORDER BY month";
    } else {
        // SQLite uses start_date
        $sql = "SELECT strftime('%Y-%m', start_date) as month, SUM(net_pay + deductions) as total
                FROM payrolls GROUP BY month ORDER BY month";
    }
    return $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Gather dashboard statistics used in the dashboard view
 */
function getDashboardStats($db, $dbType = 'sqlite') {
    $stats = [
        'total_employees' => 0,
        'payroll_record_count' => 0,
        'total_payroll' => 0,
        'pending_payrolls' => 0,
        'upcoming_pay_date' => null,
        'next_pay_run' => date('F j, Y', strtotime('+7 days')),
        'recent_activity' => []
    ];

    try {
        $stats['total_employees'] = (int)$db->query("SELECT COUNT(*) FROM employees")->fetchColumn();
    } catch (Exception $e) {
        $stats['total_employees'] = 0;
    }

    try {
        $stats['pending_payrolls'] = (int)$db->query("SELECT COUNT(*) FROM payrolls WHERE status = 'Pending'")->fetchColumn();
    } catch (Exception $e) {
        $stats['pending_payrolls'] = 0;
    }

    // Total payroll records
    try {
        $stats['payroll_record_count'] = (int)$db->query("SELECT COUNT(*) FROM payrolls")->fetchColumn();
    } catch (Exception $e) {
        $stats['payroll_record_count'] = 0;
    }

    // Total payroll amount (sum of gross_pay)
    try {
        $stats['total_payroll'] = (float)$db->query("SELECT COALESCE(SUM(gross_pay),0) FROM payrolls")->fetchColumn();
    } catch (Exception $e) {
        $stats['total_payroll'] = 0;
    }

    try {
        $up = upcomingPayDate($db, $dbType);
        if ($up) {
            // format date for display
            $stats['upcoming_pay_date'] = date('F j, Y', strtotime($up));
        }
    } catch (Exception $e) {
        $stats['upcoming_pay_date'] = null;
    }

    // Recent activity: latest payrolls and time entries
    try {
        $recent = [];
        $rows = $db->query("SELECT p.id, p.status, p.pay_period_start AS start, p.pay_period_end AS end, e.name as employee_name FROM payrolls p LEFT JOIN employees e ON p.employee_id = e.id ORDER BY p.created_at DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $date = $r['start'] ?? $r['pay_period_start'] ?? null;
            $recent[] = ($r['employee_name'] ?? 'Unknown') . ' - payroll (' . ($r['status'] ?? 'N/A') . ')';
        }
        $stats['recent_activity'] = $recent;
    } catch (Exception $e) {
        $stats['recent_activity'] = [];
    }

    return $stats;
}

/**
 * Insert an employee record using DB-aware column names.
 * $data keys: name, position, department, date (string), hourly_rate
 */
function insertEmployee($db, $data, $dbType = 'sqlite') {
    $name = $data['name'] ?? '';
    $position = $data['position'] ?? null;
    $department = $data['department'] ?? null;
    $date = $data['date'] ?: null;
    $hourly_rate = isset($data['hourly_rate']) ? floatval($data['hourly_rate']) : null;
    $phone = $data['phone'] ?? null;
    // emergency_phone removed from employee schema

    $dateCol = 'hire_date'; // MySQL payroll_db standard

    // Try inserting with hourly_rate column first
    if ($hourly_rate !== null) {
        try {
            $sql = "INSERT INTO employees (name, position, department, $dateCol, hourly_rate, status, phone) VALUES (?, ?, ?, ?, ?, 'Active', ?)";
            $stmt = $db->prepare($sql);
            $stmt->execute([$name, $position, $department, $date, $hourly_rate, $phone]);
            return;
        } catch (Exception $e) {
            // fallback to without hourly_rate
        }
    }

    // Fallback insert without hourly_rate
    $sql = "INSERT INTO employees (name, position, department, $dateCol, status, phone) VALUES (?, ?, ?, ?, 'Active', ?)";
    $stmt = $db->prepare($sql);
    $stmt->execute([$name, $position, $department, $date, $phone]);
}

// Page data for logged-in users
$employees = [];
$payrolls = [];

$timeEntries = [];
$todayEntries = []; // Initialize to prevent undefined variable errors
$chartData = [];

if (isLoggedIn()) {
    if ($page === 'people') {
        $includeInactive = isset($_GET['include_inactive']) && $_GET['include_inactive'] === '1';
        if ($includeInactive) {
            $employees = $db->query("SELECT * FROM employees ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
        } else {
            try {
                $stmt = $db->prepare("SELECT * FROM employees WHERE LOWER(status) = 'active' ORDER BY id DESC");
                $stmt->execute();
                $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) {
                // Fallback if status column has inconsistent casing or missing values
                $employees = $db->query("SELECT * FROM employees ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
            }
        }

    } elseif ($page === 'payroll') {
        // Support day-level filtering via start/end; fallback to month
        $month = $_GET['month'] ?? date('Y-m');
        $startParam = $_GET['start'] ?? '';
        $endParam = $_GET['end'] ?? '';

        if ($startParam !== '' && $endParam !== '') {
            $start = date('Y-m-d', strtotime($startParam));
            $end = date('Y-m-d', strtotime($endParam));
        } else {
            $start = date('Y-m-01', strtotime($month . '-01'));
            $end = date('Y-m-t', strtotime($start));
        }

        // MySQL payroll_db query with standard column names
        $stmt = $db->prepare("SELECT p.*, e.name as employee_name FROM payrolls p JOIN employees e ON p.employee_id = e.id WHERE p.pay_period_start >= ? AND p.pay_period_end <= ? ORDER BY p.created_at DESC, p.id DESC");
        $stmt->execute([$start, $end]);
        $payrolls = $stmt->fetchAll(PDO::FETCH_ASSOC);
        // Load employees for payroll editing (include more fields for info panel)
        $employees = $db->query("SELECT id, name, position, department, hire_date, COALESCE(hourly_rate, 15.00) AS hourly_rate, status, phone FROM employees WHERE status = 'Active' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    } elseif ($page === 'timecard') {
        // Load time entries with employee names for the table
        $timeEntries = $db->query("SELECT te.*, e.name as employee_name FROM time_entries te LEFT JOIN employees e ON te.employee_id = e.id ORDER BY te.id DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
        
        // Check if hourly_rate column exists, if not use default value
        try {
            $employees = $db->query("SELECT id, name, COALESCE(position, '') AS position, COALESCE(hourly_rate, 15.00) as hourly_rate FROM employees WHERE status = 'Active' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            // Fallback for old database schema or empty table
            try {
                $employees = $db->query("SELECT id, name, '' AS position, 15.00 as hourly_rate FROM employees WHERE status = 'Active' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e2) {
                $employees = []; // Empty array if no employees exist
                $_SESSION['info'] = "No employees found. Please add employees first.";
            }
        }
        
        // Get today's entries for dashboard cards and clock status (MySQL payroll_db)
        $today = date('Y-m-d');
        $stmt = $db->prepare("
            SELECT t.*, e.name as employee_name, COALESCE(e.hourly_rate, 15.00) as hourly_rate
            FROM time_entries t
            JOIN employees e ON t.employee_id = e.id
            WHERE DATE(t.entry_date) = ?
            AND t.id IN (
                SELECT MAX(id) 
                FROM time_entries 
                WHERE DATE(entry_date) = ? 
                GROUP BY employee_id
            )
            ORDER BY t.id DESC
        ");
        $stmt->execute([$today, $today]);
        $todayEntries = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []; // Ensure it's always an array
    } elseif ($page === 'reports') {
        // Load report data if available
        $compensationReport = $_SESSION['report_data'] ?? null;
        $payrollSummary = $_SESSION['payroll_summary'] ?? null;
        $timeAttendance = $_SESSION['time_attendance'] ?? null;
        $reportPeriod = $_SESSION['report_period'] ?? null;
        $reportView = $_GET['view'] ?? null;
    } elseif ($page === 'dashboard') {
        try {
            $chartData = payrollSummary($db, 'mysql');
        } catch (Exception $e) {
            $chartData = []; // Empty chart data if query fails
            error_log("Chart data error: " . $e->getMessage());
        }

        // Populate dashboard stats for use in the template
        try {
            $dashboardStats = getDashboardStats($db, 'mysql');
        } catch (Exception $e) {
            $dashboardStats = [];
        }
    } elseif ($page === 'search') {
        // Basic search across employees, payrolls, and time entries
        $q = trim($_GET['q'] ?? '');
        $search = ['q' => $q, 'employees' => [], 'payrolls' => [], 'times' => []];
        if ($q !== '') {
            try {
                // Employees
                $stmt = $db->prepare("SELECT id, name, position, department, status FROM employees 
                    WHERE name LIKE ? OR COALESCE(position,'') LIKE ? OR COALESCE(department,'') LIKE ?
                    ORDER BY name LIMIT 50");
                $like = "%$q%";
                $stmt->execute([$like, $like, $like]);
                $search['employees'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

                // Payrolls joined with employees
                $stmt = $db->prepare("SELECT p.id, p.pay_period_start, p.pay_period_end, p.net_pay, p.status, p.notes,
                                                p.regular_hours, p.overtime_hours, p.gross_pay, p.taxes, p.deductions,
                                                e.name AS employee_name
                                       FROM payrolls p
                                       JOIN employees e ON p.employee_id = e.id
                                       WHERE e.name LIKE ? OR COALESCE(p.notes,'') LIKE ? OR p.status LIKE ?
                                       ORDER BY p.created_at DESC, p.id DESC LIMIT 50");
                $stmt->execute([$like, $like, $like]);
                $search['payrolls'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

                // Time entries joined with employees
                $stmt = $db->prepare("SELECT t.id, t.entry_date, t.total_hours, t.status, COALESCE(t.notes,'') AS notes, e.name AS employee_name
                                       FROM time_entries t
                                       JOIN employees e ON t.employee_id = e.id
                                       WHERE e.name LIKE ? OR COALESCE(t.notes,'') LIKE ?
                                       ORDER BY t.entry_date DESC, t.id DESC LIMIT 50");
                $stmt->execute([$like, $like]);
                $search['times'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) {
                error_log('Search error: ' . $e->getMessage());
            }
        }
    }
}

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <title>Payroll System</title>
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <link rel="stylesheet" type="text/css" href="/Payroll/styles.css?v=1.1">
    <link rel="stylesheet" type="text/css" href="/Payroll/dashboard.css?v=1.0">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="/Payroll/payroll_functions.js"></script>
</head>
<body>
    <?php if ($page === 'login'): ?>
        <div class="auth-container">
            <div class="auth-right">
                <div class="auth-card">
                    <div class="auth-header">
                        <div class="auth-icon">
                            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M12 2C13.1 2 14 2.9 14 4C14 5.1 13.1 6 12 6C10.9 6 10 5.1 10 4C10 2.9 10.9 2 12 2ZM21 9V7L15 3.5C14.1 3.1 12.9 3.1 12 3.5L6 7V9H3V22H21V9H21ZM11 19H9V17H11V19ZM11 15H9V13H11V15ZM15 19H13V17H15V19ZM15 15H13V13H15V15Z" fill="currentColor"/>
                            </svg>
                        </div>
                        <h2>Admin Login</h2>
                        <p class="auth-subtitle">Welcome back! Please sign in to your account</p>
                    </div>
                    
                    <?php if (!empty($_SESSION['message'])): ?>
                        <div class="alert success">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M9 12L11 14L15 10M21 12C21 16.9706 16.9706 21 12 21C7.02944 21 3 16.9706 3 12C3 7.02944 7.02944 3 12 3C16.9706 3 21 7.02944 21 12Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                            <?=htmlspecialchars($_SESSION['message'])?>
                        </div>
                        <?php unset($_SESSION['message']); endif; ?>
                    <?php if (!empty($_SESSION['error'])): ?>
                        <div class="alert error">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M12 9V13M12 17H12.01M21 12C21 16.9706 16.9706 21 12 21C7.02944 21 3 16.9706 3 12C3 7.02944 7.02944 3 12 3C16.9706 3 21 7.02944 21 12Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                            <?=htmlspecialchars($_SESSION['error'])?>
                        </div>
                        <?php unset($_SESSION['error']); endif; ?>
                    
                    <form method="post" action="index.php?page=login" class="auth-form">
                        <input type="hidden" name="action" value="login" />
                        <div class="form-group">
                            <label>
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                    <path d="M20 21V19C20 17.9391 19.5786 16.9217 18.8284 16.1716C18.0783 15.4214 17.0609 15 16 15H8C6.93913 15 5.92172 15.4214 4.17157 16.1716C3.42143 16.9217 3 17.9391 3 19V21M16 7C16 9.20914 14.2091 11 12 11C9.79086 11 8 9.20914 8 7C8 4.79086 9.79086 3 12 3C14.2091 3 16 4.79086 16 7Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                </svg>
                                Username
                            </label>
                            <input type="text" name="username" placeholder="Enter your username" required />
                        </div>
                        <div class="form-group">
                            <label>
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                    <rect x="3" y="11" width="18" height="11" rx="2" ry="2" stroke="currentColor" stroke-width="2"/>
                                    <circle cx="12" cy="16" r="1" fill="currentColor"/>
                                    <path d="M7 11V7C7 5.67392 7.52678 4.40215 8.46447 3.46447C9.40215 2.52678 10.6739 2 12 2C13.3261 2 14.5979 2.52678 15.5355 3.46447C16.4732 4.40215 17 5.67392 17 7V11" stroke="currentColor" stroke-width="2"/>
                                </svg>
                                Password
                            </label>
                            <input type="password" name="password" placeholder="Enter your password" required />
                        </div>
                        <div class="form-options">
                            <a href="#" class="forgot-link">Forgot password?</a>
                        </div>
                        <button type="submit" class="btn-primary">
                            <span>Login</span>
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M9 12L11 14L15 10" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </button>
                    </form>
                    <div class="auth-divider">
                        <span>or</span>
                    </div>
                    <p class="auth-link">Don't have an account? <a href="index.php?page=signup">Sign up here</a></p>
                </div>
            </div>
        </div>

    <?php elseif ($page === 'signup'): ?>
        <div class="auth-container">
            <div class="auth-card">
                <div class="auth-header">
                    <div class="auth-icon">
                        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M16 21V19C16 17.9391 15.5786 16.9217 14.8284 16.1716C14.0783 15.4214 13.0609 15 12 15H5C3.93913 15 2.92172 15.4214 2.17157 16.1716C1.42143 16.9217 1 17.9391 1 19V21M12 11C14.2091 11 16 9.20914 16 7C16 4.79086 14.2091 3 12 3C9.79086 3 8 4.79086 8 7C8 9.20914 9.79086 11 12 11ZM20 8V14M23 11H17" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </div>
                    <h2>Create Admin Account</h2>
                    <p class="auth-subtitle">Join our payroll management system</p>
                </div>
                
                <?php if (!empty($_SESSION['error'])): ?>
                    <div class="alert error">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M12 9V13M12 17H12.01M21 12C21 16.9706 16.9706 21 12 21C7.02944 21 3 16.9706 3 12C3 7.02944 7.02944 3 12 3C16.9706 3 21 7.02944 21 12Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                        <?=htmlspecialchars($_SESSION['error'])?>
                    </div>
                    <?php unset($_SESSION['error']); endif; ?>
                
                <form method="post" action="index.php?page=signup" class="auth-form">
                    <input type="hidden" name="action" value="signup" />
                    <div class="form-group">
                        <label>
                            <svg width="16" height="16" viewBox="0 0 24  24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M20 21V19C20 17.9391 19.5786 16.9217 18.8284 16.1716C18.0783 15.4214 17.0609 15 16 15H8C6.93913 15 5.92172 15.4214 4.17157 16.1716C3.42143 16.9217 3 17.9391 3 19V21M16 7C16 9.20914 14.2091 11 12 11C9.79086 11 8 9.20914 8 7C8 4.79086 9.79086 3 12 3C14.2091 3 16 4.79086 16 7Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                            Username
                        </label>
                        <input type="text" name="username" placeholder="Choose a username" required />
                    </div>
                    <div class="form-group">
                        <label>
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M4 4H20C21.1 4 22 4.9 22 6V18C22 19.1 21.1 20 20 20H4C2.9 20 2 19.1 2 18V6C2 4.9 2.9 4 4 4Z" stroke="currentColor" stroke-width="2"/>
                                <polyline points="22,6 12,13 2,6" stroke="currentColor" stroke-width="2"/>
                            </svg>
                            Email
                        </label>
                        <input type="email" name="email" placeholder="Enter your email address" required />
                    </div>
                    <div class="form-group">
                        <label>
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <rect x="3" y="11" width="18" height="11" rx="2" ry="2" stroke="currentColor" stroke-width="2"/>
                                <circle cx="12" cy="16" r="1" fill="currentColor"/>
                                <path d="M7 11V7C7 5.67392 7.52678 4.40215 8.46447 3.46447C9.40215 2.52678 10.6739 2 12 2C13.3261 2 14.5979 2.52678 15.5355 3.46447C16.4732 4.40215 17 5.67392 17 7V11" stroke="currentColor" stroke-width="2"/>
                            </svg>
                            Password
                        </label>
                        <input type="password" name="password" placeholder="Create a password" required />
                    </div>
                    <div class="form-group">
                        <label>
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M9 12L11 14L15 10M21 12C21 16.9706 16.9706 21 12 21C7.02944 21 3 16.9706 3 12C3 7.02944 7.02944 3 12 3C16.9706 3 21 7.02944 21 12Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                            Confirm Password
                        </label>
                        <input type="password" name="confirm_password" placeholder="Confirm your password" required />
                    </div>
                    <button type="submit" class="btn-primary">
                        <span>Create Account</span>
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M5 12H19M19 12L12 5M19 12L12 19" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </button>
                </form>
                <div class="auth-divider">
                    <span>or</span>
                </div>
                <p class="auth-link">Already have an account? <a href="index.php?page=login">Login here</a></p>
            </div>
        </div>

    <?php else: ?>
        <!-- Main application layout for logged-in users -->
        <button id="mobile-nav-toggle" class="mobile-nav-toggle">☰</button>
        <div id="mobile-overlay" class="mobile-overlay"></div>
        <aside class="sidebar">
            <div class="brand">Payroll System</div>
            <div class="user">
                <div class="avatar">
                    <img src="image/Avatar.jpg" alt="Admin avatar" class="avatar-img" onerror="this.style.display='none'; this.parentElement.querySelector('.initials').style.display='flex';">
                    <div class="initials" style="display:none;">
                        <?= strtoupper(substr($_SESSION['username'],0,2)) ?>
                    </div>
                    <span class="status-dot" title="Online"></span>
                </div>
                <div class="username"><?= htmlspecialchars($_SESSION['username']) ?></div>
            </div>
            <nav>
                <a class="nav-link <?= $page==='dashboard' ? 'active' : '' ?>" href="index.php?page=dashboard">
                    <span class="nav-icon">📊</span>
                    <span class="nav-text">Dashboard</span>
                </a>
                <a class="nav-link <?= $page==='people' ? 'active' : '' ?>" href="index.php?page=people">
                    <span class="nav-icon">👥</span>
                    <span class="nav-text">People</span>
                </a>
                <a class="nav-link <?= $page==='payroll' ? 'active' : '' ?>" href="index.php?page=payroll">
                    <span class="nav-icon">💰</span>
                    <span class="nav-text">Payroll</span>
                </a>
                <a class="nav-link <?= $page==='reports' ? 'active' : '' ?>" href="index.php?page=reports">
                    <span class="nav-icon">📈</span>
                    <span class="nav-text">Reports</span>
                </a>
                <a class="nav-link <?= $page==='timecard' ? 'active' : '' ?>" href="index.php?page=timecard">
                    <span class="nav-icon">⏰</span>
                    <span class="nav-text">Time & Attendance</span>
                </a>
                <form method="post" action="index.php" style="margin-top:8px !important;">
                    <input type="hidden" name="action" value="logout" />
                    <button type="submit" class="logout-btn-side no-hover">
                        <span class="nav-icon">🚪</span>
                        <span class="nav-text">Log Out</span>
                    </button>
                </form>
            </nav>
        </aside>

        <header class="topbar">
            <div class="left">
                <button id="burger-menu" class="burger-btn">
                    <span class="burger-line"></span>
                    <span class="burger-line"></span>
                    <span class="burger-line"></span>
                </button>
            </div>
            <div class="center">Payroll System</div>
            <div class="right">
                <form method="get" action="index.php" style="display:inline;">
                    <input type="hidden" name="page" value="search" />
                    <input name="q" placeholder="Search employees, payrolls, time..." value="<?= htmlspecialchars($_GET['q'] ?? '') ?>" />
                </form>
            </div>
        </header>

        <main class="main">
            <?php if (!empty($_SESSION['message'])): ?>
                <div class="alert success"><?=htmlspecialchars($_SESSION['message'])?></div>
                <?php unset($_SESSION['message']); endif; ?>
            <?php if (!empty($_SESSION['error'])): ?>
                <div class="alert error"><?=htmlspecialchars($_SESSION['error'])?></div>
                <?php unset($_SESSION['error']); endif; ?>

            <?php if ($page === 'dashboard'): ?>
                <div class="dashboard-container">
                    <div class="dashboard-header">
                        <h1>Payroll Management System</h1>
                    </div>

                    <!-- Aesthetic Dashboard -->
                    <div class="aesthetic-dashboard">
                        <div class="dashboard-header">
                            <div class="welcome-section">
                                <h2>Welcome to Your Payroll Dashboard</h2>
                                <p>Manage your workforce efficiently</p>
                            </div>
                        </div>
                        
                        <div class="stats-cards">
                            <div class="stat-card employees">
                                <div class="card-icon">
                                    <svg width="32" height="32" fill="currentColor" viewBox="0 0 24 24">
                                        <path d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                                    </svg>
                                </div>
                                <div class="card-content">
                                    <div class="stat-number"><?= (int)($dashboardStats['total_employees'] ?? 0) ?></div>
                                    <div class="stat-label">Active Employees</div>
                                </div>
                            </div>
                            
                            <div class="stat-card payrolls">
                                <div class="card-icon">
                                    <svg width="32" height="32" fill="currentColor" viewBox="0 0 24 24">
                                        <path d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                                    </svg>
                                </div>
                                <div class="card-content">
                                    <div class="stat-number"><?= (int)($dashboardStats['payroll_record_count'] ?? 0) ?></div>
                                    <div class="stat-label">Payroll Records</div>
                                </div>
                            </div>
                            
                            <div class="stat-card earnings">
                                <div class="card-icon">
                                    <svg width="32" height="32" fill="currentColor" viewBox="0 0 24 24">
                                        <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1.41 16.09V20h-2.67v-1.93c-1.71-.36-3.16-1.46-3.27-3.4h1.96c.1 1.05.82 1.87 2.65 1.87 1.96 0 2.4-.98 2.4-1.59 0-.83-.44-1.61-2.67-2.14-2.48-.6-4.18-1.62-4.18-3.67 0-1.72 1.39-2.84 3.11-3.21V4h2.67v1.95c1.86.45 2.79 1.86 2.85 3.39H14.3c-.05-1.11-.64-1.87-2.22-1.87-1.5 0-2.4.68-2.4 1.64 0 .84.65 1.39 2.67 1.91s4.18 1.39 4.18 3.91c-.01 1.83-1.38 2.83-3.12 3.16z"/>
                                    </svg>
                                </div>
                                <div class="card-content">
                                    <div class="stat-number">₱<?php
                                        $totalPayroll = isset($dashboardStats['total_payroll']) ? (float)$dashboardStats['total_payroll'] : 0;
                                        echo number_format($totalPayroll, 0);
                                    ?></div>
                                    <div class="stat-label">Total Payroll</div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="action-section">
                            <h3>Quick Actions</h3>
                            <div class="action-cards">
                                <a href="index.php?page=people" class="action-card people">
                                    <div class="action-icon">
                                        <svg width="24" height="24" fill="currentColor" viewBox="0 0 24 24">
                                            <path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/>
                                        </svg>
                                    </div>
                                    <div class="action-text">
                                        <h4>Employees</h4>
                                        <p>Manage staff records</p>
                                    </div>
                                </a>
                                
                                <a href="index.php?page=payroll" class="action-card payroll">
                                    <div class="action-icon">
                                        <svg width="24" height="24" fill="currentColor" viewBox="0 0 24 24">
                                            <path d="M11.8 10.9c-2.27-.59-3-1.2-3-2.15 0-1.09 1.01-1.85 2.7-1.85 1.78 0 2.44.85 2.5 2.1h2.21c-.07-1.72-1.12-3.3-3.21-3.81V3h-3v2.16c-1.94.42-3.5 1.68-3.5 3.61 0 2.31 1.91 3.46 4.7 4.13 2.5.6 3 1.48 3 2.41 0 .69-.49 1.79-2.7 1.79-2.06 0-2.87-.92-2.98-2.1h-2.2c.12 2.19 1.76 3.42 3.68 3.83V21h3v-2.15c1.95-.37 3.5-1.5 3.5-3.55 0-2.84-2.43-3.81-4.7-4.4z"/>
                                        </svg>
                                    </div>
                                    <div class="action-text">
                                        <h4>Payroll</h4>
                                        <p>Process payments</p>
                                    </div>
                                </a>
                                
                                <a href="index.php?page=timecard" class="action-card timecard">
                                    <div class="action-icon">
                                        <svg width="24" height="24" fill="currentColor" viewBox="0 0 24 24">
                                            <path d="M11.99 2C6.47 2 2 6.48 2 12s4.47 10 9.99 10C17.52 22 22 17.52 22 12S17.52 2 11.99 2zM12 20c-4.42 0-8-3.58-8-8s3.58-8 8-8 8 3.58 8 8-3.58 8-8 8zm.5-13H11v6l5.25 3.15.75-1.23-4.5-2.67z"/>
                                        </svg>
                                    </div>
                                    <div class="action-text">
                                        <h4>Time Tracking</h4>
                                        <p>Clock in & out</p>
                                    </div>
                                </a>
                                
                                <a href="index.php?page=reports" class="action-card reports">
                                    <div class="action-icon">
                                        <svg width="24" height="24" fill="currentColor" viewBox="0 0 24 24">
                                            <path d="M3.5 18.49l6-6.01 4 4L22 6.92l-1.41-1.41-7.09 7.97-4-4L2 16.99z"/>
                                        </svg>
                                    </div>
                                    <div class="action-text">
                                        <h4>Reports</h4>
                                        <p>View analytics</p>
                                    </div>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

            <?php elseif ($page === 'people'): ?>
                <h2>People</h2>
                <div class="toolbar">
                    <form method="get" action="index.php" style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                        <input type="hidden" name="page" value="people" />
                        <input placeholder="Search" name="q" value="<?= htmlspecialchars($_GET['q'] ?? '') ?>" />
                        <label style="display:flex; align-items:center; gap:6px; font-size:13px; color:#555;">
                            <input type="checkbox" name="include_inactive" value="1" <?= (isset($_GET['include_inactive']) && $_GET['include_inactive']==='1') ? 'checked' : '' ?> />
                            Show Inactive
                        </label>
                        <button type="submit">Apply</button>
                    </form>
                    <button onclick="document.getElementById('addModal').style.display='block'">+ Add Employee</button>
                </div>

                <table class="table">
                    <thead>
                        <tr><th>Name</th><th>Position</th><th>Department</th><th>Start Date</th><th>Status</th><th>Actions</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($employees as $e): ?>
                        <tr <?= (strtolower($e['status'] ?? '') === 'inactive') ? 'style="opacity: 0.6; background-color: #f8f9fa;"' : '' ?>>
                            <td><?=htmlspecialchars($e['name'])?></td>
                            <td><?=htmlspecialchars($e['position'])?></td>
                            <td><?=htmlspecialchars($e['department'])?></td>
                            <td><?=htmlspecialchars($e['start_date'] ?? $e['hire_date'] ?? '')?></td>
                            <td>
                                <?php if (strtolower($e['status'] ?? '') === 'inactive'): ?>
                                    <span style="color: #dc3545; font-weight: 500;">
                                        <svg width="12" height="12" style="margin-right: 4px; vertical-align: -1px;" fill="currentColor" viewBox="0 0 24 24">
                                            <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/>
                                        </svg>
                                        Inactive
                                    </span>
                                <?php else: ?>
                                    <span style="color: #28a745; font-weight: 500;">
                                        <svg width="12" height="12" style="margin-right: 4px; vertical-align: -1px;" fill="currentColor" viewBox="0 0 24 24">
                                            <path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/>
                                        </svg>
                                        Active
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="actions">
                                <button class="btn-edit" title="Edit" onclick='editEmployee(<?= json_encode($e, JSON_HEX_APOS|JSON_HEX_QUOT) ?>, "edit")'>
                                    <svg width="16" height="16" fill="currentColor" viewBox="0 0 24 24"><path d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04c.39-.39.39-1.02 0-1.41l-2.34-2.34c-.39-.39-1.02-.39-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z"/></svg>
                                </button>
                                <?php if (strtolower($e['status'] ?? '') === 'inactive'): ?>
                                    <button class="btn-activate" title="Reactivate Employee" onclick='reactivateEmployee(<?= (int)$e['id'] ?>, "<?= htmlspecialchars($e['name'], ENT_QUOTES) ?>")' style="background: #28a745; color: white;">
                                        <svg width="16" height="16" fill="currentColor" viewBox="0 0 24 24"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>
                                    </button>
                                <?php else: ?>
                                    <button class="btn-delete" title="Remove Employee" onclick='deleteEmployee(<?= (int)$e['id'] ?>, "<?= htmlspecialchars($e['name'], ENT_QUOTES) ?>")'>
                                        <svg width="16" height="16" fill="currentColor" viewBox="0 0 24 24"><path d="M6 19c0 1.1.9 2 2 2h8c1.1 0 2-.9 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z"/></svg>
                                    </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>

                <div id="addModal" class="modal">
                    <div class="modal-content">
                        <h3>Add Employee</h3>
                        <form method="post" action="index.php?page=people">
                            <input type="hidden" name="action" value="add_employee" />
                            <label>Name</label><input name="name" required />
                            <label>Position</label><input name="position" />
                            <label>Department</label><input name="department" />
                            <label>Start Date</label><input type="date" name="start_date" />
                            <label>Phone</label><input name="phone" />
                            <label>Hourly Rate (₱)</label><input type="number" name="hourly_rate" step="0.01" min="0" value="15.00" />
                            <div class="modal-actions">
                                <button type="submit">Add</button>
                                <button type="button" onclick="document.getElementById('addModal').style.display='none'">Cancel</button>
                            </div>
                        </form>
                    </div>
                </div>
                
                <!-- Edit Employee Modal -->
                <div id="editModal" class="modal">
                    <div class="modal-content">
                        <h3>Edit Employee</h3>
                        <form method="post" action="index.php?page=people">
                            <input type="hidden" name="action" value="edit_employee" />
                            <input type="hidden" name="employee_id" id="editEmployeeId" />
                            <label>Name *</label><input name="name" id="editEmployeeName" required />
                            <label>Position</label><input name="position" id="editEmployeePosition" />
                            <label>Department</label><input name="department" id="editEmployeeDepartment" />
                            <label>Phone</label><input name="phone" id="editEmployeePhone" />
                            <label>Hourly Rate (₱)</label><input type="number" name="hourly_rate" id="editEmployeeRate" step="0.01" min="0" />
                            <label>Status</label>
                            <select name="status" id="editEmployeeStatus">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                            <div class="modal-actions">
                                <button type="submit">Update</button>
                                <button type="button" onclick="document.getElementById('editModal').style.display='none'">Cancel</button>
                            </div>
                        </form>
                    </div>
                </div>

            <?php elseif ($page === 'payroll'): ?>
                <h2>Current Payroll</h2>


                <!-- Quick Payroll Entry Card -->
                <div class="quick-payroll-card">
                    <div class="quick-card-header">
                        <div class="title">Quick Payroll Entry</div>
                        <div class="subtitle">Fast input for a single employee</div>
                    </div>
                    <form method="post" action="index.php?page=payroll" class="quick-payroll-form">
                        <input type="hidden" name="action" value="add_payroll" />
                        <div class="quick-grid">
                            <div class="q-group">
                                <label>Employee</label>
                                <select name="employee_id" required>
                                    <option value="">Select employee</option>
                                    <?php foreach ($employees as $emp): ?>
                                        <option value="<?= (int)$emp['id'] ?>" data-rate="<?= isset($emp['hourly_rate']) ? number_format((float)$emp['hourly_rate'], 2, '.', '') : '0.00' ?>"><?= htmlspecialchars($emp['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="q-section q-full">Pay Period</div>
                            <div class="q-group">
                                <label>Pay Period Start</label>
                                <input type="date" name="pay_period_start" value="<?= date('Y-m-01') ?>" required />
                            </div>
                            <div class="q-group">
                                <label>Pay Period End</label>
                                <input type="date" name="pay_period_end" value="<?= date('Y-m-t') ?>" required />
                            </div>
                            <div class="q-section q-full">Hours</div>
                            <div class="q-group">
                                <label>Regular Hours</label>
                                <input type="number" name="regular_hours" step="0.1" min="0" value="0" />
                            </div>
                            <div class="q-group">
                                <label>Overtime Hours</label>
                                <input type="number" name="overtime_hours" step="0.1" min="0" value="0" />
                            </div>
                            <div class="q-section q-full">Amounts</div>
                            <div class="q-group">
                                <label>Gross Pay (₱)</label>
                                <div class="currency-input peso"><span class="prefix">₱</span><input id="qp-gross" type="number" name="gross_pay" step="0.01" min="0" value="0" /></div>
                            </div>
                            <div class="q-group">
                                <label>Taxes (₱)</label>
                                <div class="currency-input peso"><span class="prefix">₱</span><input id="qp-taxes" type="number" name="taxes" step="0.01" min="0" value="0" /></div>
                            </div>
                            <div class="q-group">
                                <label>Deductions (₱)</label>
                                <div class="currency-input peso"><span class="prefix">₱</span><input id="qp-deductions" type="number" name="deductions" step="0.01" min="0" value="0" /></div>
                                <div class="q-hint" id="qp-ded-breakdown">SSS ₱0.00 • PhilHealth ₱0.00 • Pag-IBIG ₱0.00</div>
                            </div>
                            <div class="q-group">
                                <label>Net Pay (₱)</label>
                                <div class="currency-input peso"><span class="prefix">₱</span><input id="qp-net" type="number" name="net_pay" step="0.01" min="0" value="0" /></div>
                            </div>
                            <div class="q-help q-full">Taxes are withheld and remitted to BIR. Deductions are government contributions (SSS, PhilHealth, Pag-IBIG). Net Pay is the employee's take-home after taxes and deductions.</div>
                            <div class="q-section q-full">Status & Notes</div>
                            <div class="q-group">
                                <label>Status</label>
                                <select name="status">
                                    <option value="draft">Draft</option>
                                    <option value="pending" selected>Pending</option>
                                    <option value="approved">Approved</option>
                                    <option value="paid">Paid</option>
                                </select>
                            </div>
                            <div class="q-group q-full">
                                <label>Notes</label>
                                <textarea name="notes" rows="2" placeholder="Optional notes"></textarea>
                            </div>
                        </div>
                        <div class="quick-actions">
                            <button type="submit" class="btn-primary">Save Payroll</button>
                        </div>
                    </form>
                </div>

                <div class="payroll-section">
                    <div class="payroll-card">
                        <div class="payroll-table-wrapper">
                            <table class="payroll-table">
                                <thead>
                                    <tr>
                                        <th>Employee Name</th>
                                        <th>Period</th>
                                        <th>Regular Hours</th>
                                        <th>Overtime Hours</th>
                                        <th>Gross Pay</th>
                                        <th>Taxes</th>
                                        <th>Deductions</th>
                                        <th>Net Pay</th>
                                        <th>Status</th>
                                        <th>Notes</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($payrolls as $p): ?>
                                        <?php
                                            // Get payroll data with defaults
                                            $regular_hours = floatval($p['regular_hours'] ?? 0);
                                            $overtime_hours = floatval($p['overtime_hours'] ?? 0);
                                            $gross_pay = floatval($p['gross_pay'] ?? 0);
                                            $taxes = floatval($p['taxes'] ?? 0);
                                            $deductions = floatval($p['deductions'] ?? 0);
                                            $net_pay = floatval($p['net_pay'] ?? 0);
                                            $status = $p['status'] ?? 'draft';
                                            $notes = $p['notes'] ?? '';
                                            
                                            // If gross_pay is 0, calculate from hours and get hourly rate
                                            if ($gross_pay == 0 && ($regular_hours > 0 || $overtime_hours > 0)) {
                                                $emp_stmt = $db->prepare("SELECT hourly_rate FROM employees WHERE id = (SELECT employee_id FROM payrolls WHERE id = ?)");
                                                $emp_stmt->execute([$p['id']]);
                                                $hourly_rate = floatval($emp_stmt->fetchColumn() ?? 0);
                                                
                                                $gross_pay = ($regular_hours * $hourly_rate) + ($overtime_hours * $hourly_rate * 1.5);
                                                $taxes = $gross_pay * 0.10;
                                                $calculated_deductions = ($gross_pay * 0.045) + ($gross_pay * 0.025) + ($gross_pay * 0.02);
                                                $deductions = $deductions > 0 ? $deductions : $calculated_deductions;
                                                $net_pay = $gross_pay - $taxes - $deductions;
                                            }
                                        ?>
                                        <tr class="payroll-item">
                                            <td><?= htmlspecialchars($p['employee_name']) ?></td>
                                            <td><?= htmlspecialchars($period_display ?? (date('M j', strtotime($p['pay_period_start'] ?? $p['start_date'] ?? '')) . ' - ' . date('M j, Y', strtotime($p['pay_period_end'] ?? $p['end_date'] ?? '')))) ?></td>
                                            <td><?= number_format($regular_hours, 1) ?>h</td>
                                            <td><?= number_format($overtime_hours, 1) ?>h</td>
                                            <td>₱<?= number_format($gross_pay, 2) ?></td>
                                            <td>₱<?= number_format($taxes, 2) ?></td>
                                            <td>₱<?= number_format($deductions, 2) ?></td>
                                            <td>₱<?= number_format($net_pay, 2) ?></td>
                                            <td class="status-cell"><?= htmlspecialchars(ucfirst($status)) ?></td>
                                            <td><?= htmlspecialchars($notes ?: '-') ?></td>
                                            <td>
                                                <button type="button" class="btn-secondary" onclick="viewPayroll(<?= (int)$p['id'] ?>)" title="View">View</button>
                                                <button type="button" class="btn-edit" onclick="editPayroll({id: <?= (int)$p['id'] ?>})" title="Edit">Edit</button>
                                                <button type="button" class="btn-delete" onclick="deletePayroll(<?= (int)$p['id'] ?>)" title="Delete">Delete</button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($payrolls)): ?>
                                        <tr><td colspan="11" class="empty-payroll">No payroll records for selected month. <a href="#" onclick="generatePayrollForMonth()">Generate Payroll</a></td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Edit Payroll Modal -->
                <!-- Add Payroll Modal -->
                <div id="addPayrollModal" class="modal" style="display:none">
                    <div class="modal-content payroll-modal-content">
                        <h3>Add Payroll Record</h3>
                        <form method="post" action="index.php?page=payroll">
                            <input type="hidden" name="action" value="add_payroll" />

                            <div class="payroll-form-grid">
                                <div class="form-group">
                                    <label>Employee</label>
                                    <select name="employee_id" id="addPayrollEmployee" required>
                                        <?php foreach ($employees as $emp): ?>
                                            <option value="<?= $emp['id'] ?>"><?= htmlspecialchars($emp['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>Pay Period Start</label>
                                    <input type="date" name="pay_period_start" id="addPayrollStartDate" required />
                                </div>
                                <div class="form-group">
                                    <label>Pay Period End</label>
                                    <input type="date" name="pay_period_end" id="addPayrollEndDate" required />
                                </div>
                                <div class="form-group">
                                    <label>Regular Hours</label>
                                    <input type="number" name="regular_hours" id="addPayrollRegularHours" step="0.1" min="0" value="0" />
                                </div>
                                <div class="form-group">
                                    <label>Overtime Hours</label>
                                    <input type="number" name="overtime_hours" id="addPayrollOvertimeHours" step="0.1" min="0" value="0" />
                                </div>
                                <div class="form-group">
                                    <label>Gross Pay (₱)</label>
                                    <input type="number" name="gross_pay" id="addPayrollGrossPay" step="0.01" min="0" value="0" />
                                </div>
                                <div class="form-group">
                                    <label>Taxes (₱)</label>
                                    <input type="number" name="taxes" id="addPayrollTaxes" step="0.01" min="0" value="0" />
                                </div>
                                <div class="form-group">
                                    <label>Deductions (₱)</label>
                                    <input type="number" name="deductions" id="addPayrollDeductions" step="0.01" min="0" value="0" />
                                </div>
                                <div class="form-group">
                                    <label>Net Pay (₱)</label>
                                    <input type="number" name="net_pay" id="addPayrollNetPay" step="0.01" min="0" value="0" />
                                </div>
                                <div class="form-group">
                                    <label>Status</label>
                                    <select name="status" id="addPayrollStatus">
                                        <option value="draft">Draft</option>
                                        <option value="pending">Pending</option>
                                        <option value="approved">Approved</option>
                                        <option value="paid">Paid</option>
                                    </select>
                                </div>
                                <div class="form-group full-width">
                                    <label>Notes</label>
                                    <textarea name="notes" id="addPayrollNotes" rows="4" placeholder="Additional notes or comments"></textarea>
                                </div>
                            </div>
                            <div class="modal-actions">
                                <button type="submit">Create Payroll</button>
                                <button type="button" onclick="document.getElementById('addPayrollModal').style.display='none'">Cancel</button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Edit Payroll Modal -->
                <div id="editPayrollModal" class="modal">
                    <div class="modal-content payroll-modal-content">
                        <h3>Edit Payroll Record</h3>
                        <form method="post" action="index.php?page=payroll">
                            <input type="hidden" name="action" value="edit_payroll" />
                            <input type="hidden" name="payroll_id" id="editPayrollId" />
                            
                            <div class="payroll-form-grid">
                                <div class="form-group">
                                    <label>Employee</label>
                                    <select name="employee_id" id="editPayrollEmployee" required>
                                        <?php foreach ($employees as $emp): ?>
                                            <option value="<?= $emp['id'] ?>"><?= htmlspecialchars($emp['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="form-group">
                                    <label>Pay Period Start</label>
                                    <input type="date" name="pay_period_start" id="editPayrollStartDate" required />
                                </div>
                                
                                <div class="form-group">
                                    <label>Pay Period End</label>
                                    <input type="date" name="pay_period_end" id="editPayrollEndDate" required />
                                </div>
                                
                                <div class="form-group">
                                    <label>Regular Hours</label>
                                    <input type="number" name="regular_hours" id="editPayrollRegularHours" step="0.1" min="0" />
                                </div>
                                
                                <div class="form-group">
                                    <label>Overtime Hours</label>
                                    <input type="number" name="overtime_hours" id="editPayrollOvertimeHours" step="0.1" min="0" />
                                </div>
                                
                                <div class="form-group">
                                    <label>Gross Pay (₱)</label>
                                    <input type="number" name="gross_pay" id="editPayrollGrossPay" step="0.01" min="0" />
                                </div>
                                
                                <div class="form-group">
                                    <label>Taxes (₱)</label>
                                    <input type="number" name="taxes" id="editPayrollTaxes" step="0.01" min="0" />
                                </div>
                                
                                <div class="form-group">
                                    <label>Deductions (₱)</label>
                                    <input type="number" name="deductions" id="editPayrollDeductions" step="0.01" min="0" />
                                </div>
                                
                                <div class="form-group">
                                    <label>Net Pay (₱)</label>
                                    <input type="number" name="net_pay" id="editPayrollNetPay" step="0.01" min="0" />
                                </div>
                                
                                <div class="form-group">
                                    <label>Status</label>
                                    <select name="status" id="editPayrollStatus">
                                        <option value="draft">Draft</option>
                                        <option value="pending">Pending</option>
                                        <option value="approved">Approved</option>
                                        <option value="paid">Paid</option>
                                    </select>
                                </div>
                                
                                <div class="form-group full-width">
                                    <label>Notes</label>
                                    <textarea name="notes" id="editPayrollNotes" rows="4" placeholder="Additional notes or comments"></textarea>
                                </div>
                            </div>
                            
                            <div class="modal-actions">
                                <button type="submit">Update Payroll</button>
                                <button type="button" onclick="document.getElementById('editPayrollModal').style.display='none'">Cancel</button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- View Payroll Fullscreen Card -->
                <div id="viewPayrollModal" class="fullscreen-modal" style="display:none">
                    <div class="fullscreen-card">
                        <button class="fullscreen-close" onclick="closeViewPayroll()" title="Close">✕</button>
                        <div class="fullscreen-card-inner">
                            <h2 id="viewPayrollEmployee" class="fullscreen-employee">Employee Name</h2>
                            <div class="fullscreen-grid">
                                <div class="grid-item"><strong>Pay Period</strong><div id="viewPayrollPeriod"></div></div>
                                <div class="grid-item"><strong>Regular Hours</strong><div id="viewPayrollRegularHours"></div></div>
                                <div class="grid-item"><strong>Overtime Hours</strong><div id="viewPayrollOvertimeHours"></div></div>
                                <div class="grid-item"><strong>Gross Pay (₱)</strong><div id="viewPayrollGrossPay"></div></div>
                                <div class="grid-item"><strong>Taxes (₱)</strong><div id="viewPayrollTaxes"></div></div>
                                <div class="grid-item"><strong>Deductions (₱)</strong><div id="viewPayrollDeductions"></div></div>
                                <div class="grid-item"><strong>Net Pay (₱)</strong><div id="viewPayrollNetPay"></div></div>
                                <div class="grid-item"><strong>Status</strong><div id="viewPayrollStatus"></div></div>
                                <div class="grid-item full-width"><strong>Notes</strong><div id="viewPayrollNotes"></div></div>
                            </div>
                            <div class="fullscreen-actions">
                                <button onclick="document.getElementById('editPayrollModal').style.display='block'; closeViewPayroll(); editPayroll(currentViewPayroll || {})">Edit Payroll</button>
                                <button onclick="closeViewPayroll()">Close</button>
                            </div>
                        </div>
                    </div>
                </div>

            <?php elseif ($page === 'timecard'): ?>
                <h2>Time & Attendance</h2>
                <div class="toolbar">
                    <div class="toolbar-left">
                        <h3>Today (Manila): <?php 
                            $manila_today = new DateTime('now', new DateTimeZone('Asia/Manila'));
                            echo $manila_today->format('l, F j, Y');
                        ?></h3>
                        <?php if (isset($_SESSION['clock_action'])): 
                            $action = $_SESSION['clock_action'];
                            $actionClass = $action['type'] === 'clock_in' ? 'clock-in-status' : 'clock-out-status';
                        ?>
                            <div class="<?= $actionClass ?>">
                                <span class="employee-name"><?= htmlspecialchars($action['employee']) ?></span>
                                <?php if ($action['type'] === 'clock_in'): ?>
                                    <span class="action-text">clocked in at <?= $action['time'] ?></span>
                                <?php else: ?>
                                    <span class="action-text">clocked out at <?= $action['time'] ?> (<?= number_format($action['hours'], 1) ?>h)</span>
                                <?php endif; ?>
                            </div>
                            <?php unset($_SESSION['clock_action']); ?>
                        <?php endif; ?>
                    </div>
                    <div class="toolbar-right">
                        <div>Current Time (Manila): <span id="current-time"><?php 
                            $manila_now = new DateTime('now', new DateTimeZone('Asia/Manila'));
                            echo $manila_now->format('h:i:s A');
                        ?></span></div>
                    </div>
                </div>

                <div class="cards">
                    <div class="card">
                        <div class="card-title">Active Employees</div>
                        <div class="card-value"><?= count($employees) ?></div>
                    </div>
                    <div class="card">
                        <div class="card-title">Clocked In Today</div>
                        <div class="card-value"><?= count(array_filter($todayEntries, function($e) { return !empty($e['clock_in']) && empty($e['clock_out']); })) ?></div>
                    </div>
                    <div class="card">
                        <div class="card-title">Total Hours Today</div>
                        <div class="card-value"><?= number_format(array_sum(array_column($todayEntries, 'total_hours')), 1) ?>h</div>
                    </div>
                    <div class="card">
                        <div class="card-title">Avg Hours/Employee</div>
                        <div class="card-value"><?= count($todayEntries) > 0 ? number_format(array_sum(array_column($todayEntries, 'total_hours')) / count($employees), 1) : '0' ?>h</div>
                    </div>
                </div>

                <div class="panel">
                    <div class="panel-title">Quick Clock In/Out</div>
                    <div class="clock-actions">
                        <?php foreach ($employees as $emp): 
                            $isClocked = false;
                            $clockedEntry = null;
                            foreach ($todayEntries as $entry) {
                                if ($entry['employee_id'] == $emp['id'] && !empty($entry['clock_in']) && empty($entry['clock_out'])) {
                                    $isClocked = true;
                                    $clockedEntry = $entry;
                                    break;
                                }
                            }
                        ?>
                            <div class="employee-clock">
                                <div class="employee-info">
                                    <strong><?= htmlspecialchars($emp['name']) ?></strong>
                                    <span class="hourly-rate">₱<?= number_format($emp['hourly_rate'], 2) ?>/hr</span>
                                    <?php if ($isClocked): ?>
                                        <span class="status clocked-in">Clocked In: <?php 
                                            $ciRaw = $clockedEntry['clock_in'];
                                            $ciObj = false;
                                            if ($ciRaw) {
                                                // Try parse as full datetime first, else as time-only
                                                try { $ciObj = new DateTime($ciRaw, new DateTimeZone('Asia/Manila')); } catch (Exception $e) { $ciObj = false; }
                                                if (!$ciObj) {
                                                    $ciObj = DateTime::createFromFormat('H:i:s', $ciRaw, new DateTimeZone('Asia/Manila'));
                                                }
                                            }
                                            echo $ciObj ? $ciObj->format('h:i:s A') : htmlspecialchars($ciRaw);
                                        ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="clock-buttons">
                                    <?php if (!$isClocked): ?>
                                        <form method="post" style="display:inline;">
                                            <input type="hidden" name="action" value="clock_in" />
                                            <input type="hidden" name="employee_id" value="<?= $emp['id'] ?>" />
                                            <button type="submit" class="btn-clock-in">Clock In</button>
                                        </form>
                                    <?php else: ?>
                                        <form method="post" style="display:inline;">
                                            <input type="hidden" name="action" value="clock_out" />
                                            <input type="hidden" name="employee_id" value="<?= $emp['id'] ?>" />
                                            <button type="submit" class="btn-clock-out">Clock Out</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="panel">
                    <div class="panel-header">
                        <div class="panel-title">Time Entries Management</div>
                        <div class="panel-actions">
                            <button onclick="document.getElementById('addTimeModal').style.display='block'" class="btn-primary">
                                <svg width="16" height="16" fill="currentColor" viewBox="0 0 24 24"><path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/></svg>
                                Add Time Entry
                            </button>
                            <form method="post" style="display:inline;" onsubmit="return confirm('Clock out all open entries?')">
                                <input type="hidden" name="action" value="bulk_clock_out" />
                                <button type="submit" class="btn-secondary">
                                    <svg width="16" height="16" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10 10-4.5 10-10S17.5 2 12 2zm4.2 14.2L11 13V7h1.5v5.2l4.5 2.7-.8 1.3z"/></svg>
                                    Bulk Clock Out
                                </button>
                            </form>
                        </div>
                    </div>
                    
                    <!-- Today's Entries -->
                    <div class="timesheet-section">
                        <div class="timesheet-card">
                            <div class="timesheet-header">
                                <h4><i class="clock-icon">🕐</i> Today's Timesheet</h4>
                                <div class="timesheet-date"><?php 
                                    $manila_date = new DateTime('now', new DateTimeZone('Asia/Manila'));
                                    echo $manila_date->format('F j, Y');
                                ?></div>
                            </div>
                        <div class="timesheet-table-wrapper">
                            <table class="timesheet-table">
                            <thead>
                                <tr>
                                    <th>Employee</th>
                                    <th>Clock In</th>
                                    <th>Clock Out</th>
                                    <th>Hours</th>
                                    <th>Earnings</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($todayEntries)): ?>
                                    <?php foreach ($todayEntries as $entry): 
                                        $employee = array_filter($employees, function($e) use ($entry) { return $e['id'] == $entry['employee_id']; });
                                        $emp = reset($employee);
                                        $earnings = $entry['total_hours'] * ($emp['hourly_rate'] ?? 0);
                                        $hourly_rate = $emp ? $emp['hourly_rate'] : 0;
                                        
                                        // Format clock times
                                        $clock_in_display = '-';
                                        if (!empty($entry['clock_in'])) {
                                            $ciObj = DateTime::createFromFormat('H:i:s', $entry['clock_in'], new DateTimeZone('Asia/Manila'));
                                            $clock_in_display = $ciObj ? $ciObj->format('g:i A') : htmlspecialchars($entry['clock_in']);
                                        }
                                        
                                        $clock_out_display = '-';
                                        if (!empty($entry['clock_out'])) {
                                            $coObj = DateTime::createFromFormat('H:i:s', $entry['clock_out'], new DateTimeZone('Asia/Manila'));
                                            $clock_out_display = $coObj ? $coObj->format('g:i A') : htmlspecialchars($entry['clock_out']);
                                        }
                                    ?>
                                        <tr>
                                            <td>
                                                <?= htmlspecialchars($entry['employee_name']) ?>
                                                <small style="color: #666; display: block;">₱<?= number_format($hourly_rate, 2) ?>/hr</small>
                                            </td>
                                            <td><?= $clock_in_display ?></td>
                                            <td><?= $clock_out_display ?></td>
                                            <td><?= $entry['total_hours'] > 0 ? number_format($entry['total_hours'], 1) : '0.0' ?>h</td>
                                            <td>₱<?= number_format($earnings, 2) ?></td>
                                            <td><?= htmlspecialchars($entry['status']) ?></td>
                                            <td>
                                                <button onclick="editTimeEntry(<?= htmlspecialchars(json_encode($entry)) ?>)" class="btn-secondary" title="Edit">Edit</button>
                                                <button onclick="deleteTimeEntry(<?= $entry['id'] ?>)" class="btn-secondary" title="Delete" style="margin-left: 5px;">Delete</button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="7" style="text-align:center; padding:20px; color:#666;">No time entries for today. Use Quick Clock In above or Add Time Entry to get started.</td></tr>
                                <?php endif; ?>
                            </tbody>
                            </table>
                        </div>
                        </div>
                    </div>
                </div>
                
                <!-- Add Time Entry Modal -->
                <div id="addTimeModal" class="modal">
                    <div class="modal-content">
                        <h3>Add Time Entry</h3>
                        <form method="post" action="index.php?page=timecard">
                            <input type="hidden" name="action" value="add_time_entry" />
                            <label>Employee *</label>
                            <select name="employee_id" required>
                                <option value="">Select Employee</option>
                                <?php foreach ($employees as $emp): ?>
                                    <option value="<?= $emp['id'] ?>"><?= htmlspecialchars($emp['name']) ?> - <?= htmlspecialchars($emp['position'] ?? '') ?></option>
                                <?php endforeach; ?>
                            </select>
                            <label>Date *</label>
                            <input type="date" name="entry_date" value="<?= date('Y-m-d') ?>" required />
                            <label>Clock In Time</label>
                            <input type="time" name="clock_in" />
                            <label>Clock Out Time</label>
                            <input type="time" name="clock_out" />
                            <label>Notes</label>
                            <textarea name="notes" rows="3" placeholder="Optional notes about this time entry"></textarea>
                            <div class="modal-actions">
                                <button type="submit">Add Entry</button>
                                <button type="button" onclick="document.getElementById('addTimeModal').style.display='none'">Cancel</button>
                            </div>
                        </form>
                    </div>
                </div>
                
                <!-- Edit Time Entry Modal -->
                <div id="editTimeModal" class="modal">
                    <div class="modal-content">
                        <h3>Edit Time Entry</h3>
                        <form method="post" action="index.php?page=timecard">
                            <input type="hidden" name="action" value="edit_time_entry" />
                            <input type="hidden" name="entry_id" id="editTimeId" />
                            <label>Employee *</label>
                            <select name="employee_id" id="editTimeEmployee" required>
                                <?php foreach ($employees as $emp): ?>
                                    <option value="<?= $emp['id'] ?>"><?= htmlspecialchars($emp['name']) ?> - <?= htmlspecialchars($emp['position'] ?? '') ?></option>
                                <?php endforeach; ?>
                            </select>
                            <label>Date *</label>
                            <input type="date" name="entry_date" id="editTimeDate" required />
                            <label>Clock In Time</label>
                            <input type="time" name="clock_in" id="editTimeClockIn" />
                            <label>Clock Out Time</label>
                            <input type="time" name="clock_out" id="editTimeClockOut" />
                            <label>Notes</label>
                            <textarea name="notes" id="editTimeNotes" rows="3" placeholder="Optional notes about this time entry"></textarea>
                            <div class="modal-actions">
                                <button type="submit">Update Entry</button>
                                <button type="button" onclick="document.getElementById('editTimeModal').style.display='none'">Cancel</button>
                            </div>
                        </form>
                    </div>
                </div>

            
            <?php elseif ($page === 'reports'): ?>
                <h2>Reports</h2>
                
                <!-- Display messages -->
                <?php if (isset($_SESSION['message'])): ?>
                    <div class="alert success" style="margin: 15px 0; padding: 12px 15px; background: linear-gradient(135deg, #48bb78, #38a169); color: white; border-radius: 8px; box-shadow: 0 4px 12px rgba(56, 161, 105, 0.3);">
                        <strong>✓ Success:</strong> <?= htmlspecialchars($_SESSION['message']) ?>
                    </div>
                    <?php unset($_SESSION['message']); ?>
                <?php endif; ?>
                
                <?php if (isset($_SESSION['error'])): ?>
                    <div class="alert error" style="margin: 15px 0; padding: 12px 15px; background: linear-gradient(135deg, #f56565, #e53e3e); color: white; border-radius: 8px; box-shadow: 0 4px 12px rgba(245, 101, 101, 0.3);">
                        <strong>✗ Error:</strong> <?= htmlspecialchars($_SESSION['error']) ?>
                    </div>
                    <?php unset($_SESSION['error']); ?>
                <?php endif; ?>
                
                <?php if ($reportView === 'compensation' && $compensationReport): ?>
                    <!-- Compensation Report Display -->
                    <div class="toolbar">
                        <h3>Employee Compensation Report</h3>
                        <div>
                            Period: <?= date('M j, Y', strtotime($reportPeriod['start'])) ?> - <?= date('M j, Y', strtotime($reportPeriod['end'])) ?>
                            <a href="index.php?page=reports" class="btn-secondary">← Back to Reports</a>
                        </div>
                    </div>
                    
                    <!-- Report Summary Cards -->
                    <div class="cards">
                        <div class="card">
                            <div class="card-title">Total Employees</div>
                            <div class="card-value"><?= $compensationReport['summary']['total_employees'] ?></div>
                        </div>
                        <div class="card">
                            <div class="card-title">Total Hours</div>
                            <div class="card-value"><?= number_format($compensationReport['summary']['total_hours'], 1) ?>h</div>
                        </div>
                        <div class="card">
                            <div class="card-title">Total Compensation</div>
                            <div class="card-value">₱<?= number_format($compensationReport['summary']['total_compensation'], 2) ?></div>
                        </div>
                        <div class="card">
                            <div class="card-title">Avg Hourly Rate</div>
                            <div class="card-value">₱<?= number_format($compensationReport['summary']['average_hourly_rate'] ?? 0, 2) ?></div>
                        </div>
                    </div>
                    
                    <!-- Detailed Employee Report -->
                    <div class="panel">
                        <div class="panel-title">Employee Compensation Details</div>
                        <div class="table-responsive">
                            <table class="table compensation-table">
                                <thead>
                                    <tr>
                                        <th>Employee</th>
                                        <th>Position</th>
                                        <th>Hourly Rate</th>
                                        <th>Days Worked</th>
                                        <th>Total Hours</th>
                                        <th>Regular Pay</th>
                                        <th>Overtime Pay</th>
                                        <th>Gross Pay</th>
                                        <th>Deductions</th>
                                        <th>Net Pay</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($compensationReport['employees'] as $emp): ?>
                                        <tr>
                                            <td>
                                                <strong><?= htmlspecialchars($emp['name']) ?></strong><br>
                                                <small><?= htmlspecialchars($emp['department']) ?></small>
                                            </td>
                                            <td><?= htmlspecialchars($emp['position']) ?></td>
                                            <td>₱<?= number_format($emp['hourly_rate'], 2) ?></td>
                                            <td><?= $emp['days_worked'] ?></td>
                                            <td><?= number_format($emp['total_hours'], 1) ?>h</td>
                                            <td>₱<?= number_format($emp['regular_pay'], 2) ?></td>
                                            <td>₱<?= number_format($emp['overtime_pay'], 2) ?></td>
                                            <td><strong>₱<?= number_format($emp['gross_pay'], 2) ?></strong></td>
                                            <td>₱<?= number_format($emp['deductions'], 2) ?></td>
                                            <td><strong>₱<?= number_format($emp['net_pay'], 2) ?></strong></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($compensationReport['employees'])): ?>
                                        <tr>
                                            <td colspan="10" style="text-align: center; padding: 40px; background: rgba(102, 126, 234, 0.05); border-radius: 8px;">
                                                <div style="color: #4a5568; font-size: 16px; margin-bottom: 15px;">📋 No employee data found for the selected period</div>
                                                <div style="color: #718096; font-size: 14px; line-height: 1.5; margin-bottom: 20px;">
                                                    This could be because:<br>
                                                    • No employees are marked as 'Active'<br>
                                                    • No time entries exist for the date range<br>
                                                    • No payroll records have been created
                                                </div>
                                                <form method="post" action="index.php?page=reports" style="display: inline-block;">
                                                    <input type="hidden" name="action" value="create_sample_data" />
                                                    <button type="submit" style="background: linear-gradient(135deg, #667eea, #764ba2); color: white; border: none; padding: 10px 20px; border-radius: 8px; font-size: 14px; cursor: pointer; box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);">
                                                        🔧 Generate Sample Data
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                
                <?php elseif ($reportView === 'payroll_summary' && $payrollSummary): ?>
                    <!-- Payroll Summary Report Display -->
                    <div class="toolbar">
                        <h3>Payroll Summary Report</h3>
                        <div>
                            Period: <?= date('M j, Y', strtotime($reportPeriod['start'])) ?> - <?= date('M j, Y', strtotime($reportPeriod['end'])) ?> (<?= htmlspecialchars($reportPeriod['group'] ?? 'monthly') ?>)
                            <a href="index.php?page=reports" class="btn-secondary">← Back to Reports</a>
                        </div>
                    </div>
                    <div class="cards">
                        <div class="card"><div class="card-title">Records</div><div class="card-value"><?= (int)$payrollSummary['totals']['records'] ?></div></div>
                        <div class="card"><div class="card-title">Gross Pay</div><div class="card-value">₱<?= number_format($payrollSummary['totals']['gross_pay'], 2) ?></div></div>
                        <div class="card"><div class="card-title">Taxes</div><div class="card-value">₱<?= number_format($payrollSummary['totals']['taxes'], 2) ?></div></div>
                        <div class="card"><div class="card-title">Deductions</div><div class="card-value">₱<?= number_format($payrollSummary['totals']['deductions'], 2) ?></div></div>
                        <div class="card"><div class="card-title">Net Pay</div><div class="card-value">₱<?= number_format($payrollSummary['totals']['net_pay'], 2) ?></div></div>
                    </div>
                    <div class="panel">
                        <div class="panel-title">Breakdown</div>
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Period</th>
                                        <th>Records</th>
                                        <th>Gross Pay</th>
                                        <th>Taxes</th>
                                        <th>Deductions</th>
                                        <th>Net Pay</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach (($payrollSummary['rows'] ?? []) as $r): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($r['period']) ?></td>
                                            <td><?= (int)$r['records'] ?></td>
                                            <td>₱<?= number_format($r['gross_pay'], 2) ?></td>
                                            <td>₱<?= number_format($r['taxes'], 2) ?></td>
                                            <td>₱<?= number_format($r['deductions'], 2) ?></td>
                                            <td><strong>₱<?= number_format($r['net_pay'], 2) ?></strong></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($payrollSummary['rows'])): ?>
                                        <tr><td colspan="6">No data for selected period.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                <?php elseif ($reportView === 'time_attendance' && $timeAttendance): ?>
                    <!-- Time & Attendance Report Display -->
                    <div class="toolbar">
                        <h3>Time & Attendance Report</h3>
                        <div>
                            Period: <?= date('M j, Y', strtotime($reportPeriod['start'])) ?> - <?= date('M j, Y', strtotime($reportPeriod['end'])) ?>
                            <a href="index.php?page=reports" class="btn-secondary">← Back to Reports</a>
                        </div>
                    </div>
                    <div class="cards">
                        <div class="card"><div class="card-title">Employees</div><div class="card-value"><?= (int)$timeAttendance['totals']['employees'] ?></div></div>
                        <div class="card"><div class="card-title">Total Hours</div><div class="card-value"><?= number_format($timeAttendance['totals']['hours'], 1) ?>h</div></div>
                        <div class="card"><div class="card-title">Total Days</div><div class="card-value"><?= (int)$timeAttendance['totals']['days'] ?></div></div>
                    </div>
                    <div class="panel">
                        <div class="panel-title">Employee Hours</div>
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Employee</th>
                                        <th>Department</th>
                                        <th>Position</th>
                                        <th>Days Worked</th>
                                        <th>Total Hours</th>
                                        <th>Avg Daily Hours</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach (($timeAttendance['rows'] ?? []) as $r): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($r['name']) ?></td>
                                            <td><?= htmlspecialchars($r['department']) ?></td>
                                            <td><?= htmlspecialchars($r['position']) ?></td>
                                            <td><?= (int)$r['days_worked'] ?></td>
                                            <td><?= number_format($r['total_hours'], 1) ?>h</td>
                                            <td><?= number_format($r['avg_daily_hours'], 2) ?>h</td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($timeAttendance['rows'])): ?>
                                        <tr><td colspan="6">No time entries for selected period.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                <?php else: ?>
                    <!-- Report Selection -->
                    <div class="panel">
                        <div class="panel-title">Payroll Summary Report</div>
                        <p>Detailed breakdown of payroll expenses over time.</p>
                        <form method="post" action="index.php?page=reports" class="report-form">
                            <input type="hidden" name="action" value="generate_payroll_summary" />
                            <div class="form-row">
                                <div class="form-group">
                                    <label>Group By:</label>
                                    <select name="group_by">
                                        <option value="monthly">Monthly</option>
                                        <option value="daily">Daily</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>Start Date:</label>
                                    <input type="date" name="start_date" value="<?= date('Y-m-01') ?>" />
                                </div>
                                <div class="form-group">
                                    <label>End Date:</label>
                                    <input type="date" name="end_date" value="<?= date('Y-m-t') ?>" />
                                </div>
                                <div class="form-group">
                                    <button type="submit" class="primary">Generate Report</button>
                                </div>
                            </div>
                        </form>
                    </div>



                    <div class="panel">
                        <div class="panel-title">Time & Attendance Report</div>
                        <p>Employee hours, attendance patterns, and time tracking data.</p>
                        <form method="post" action="index.php?page=reports" class="report-form">
                            <input type="hidden" name="action" value="generate_time_attendance_report" />
                            <div class="form-row">
                                <div class="form-group">
                                    <label>Start Date:</label>
                                    <input type="date" name="start_date" value="<?= date('Y-m-01') ?>" />
                                </div>
                                <div class="form-group">
                                    <label>End Date:</label>
                                    <input type="date" name="end_date" value="<?= date('Y-m-t') ?>" />
                                </div>
                                <div class="form-group">
                                    <button type="submit" class="primary">Generate Report</button>
                                </div>
                            </div>
                        </form>
                    </div>
                <?php endif; ?>

            <?php elseif ($page === 'search'): ?>
                <h2>Search</h2>
                <div class="toolbar">
                    <form method="get" action="index.php">
                        <input type="hidden" name="page" value="search" />
                        <input name="q" value="<?= htmlspecialchars($search['q'] ?? ($_GET['q'] ?? '')) ?>" placeholder="Search employees, payrolls, time..." />
                        <button type="submit" class="primary">Search</button>
                        <a class="btn-secondary" href="index.php?page=dashboard">Back</a>
                    </form>
                </div>

                <?php $hasQuery = isset($search) && isset($search['q']) && trim($search['q']) !== ''; ?>
                <?php if (!$hasQuery): ?>
                    <div class="panel"><p>Type a keyword to search employees, payrolls, or time entries.</p></div>
                <?php else: ?>
                    <div class="cards">
                        <div class="card"><div class="card-title">Employees</div><div class="card-value"><?= isset($search['employees']) ? count($search['employees']) : 0 ?></div></div>
                        <div class="card"><div class="card-title">Payrolls</div><div class="card-value"><?= isset($search['payrolls']) ? count($search['payrolls']) : 0 ?></div></div>
                        <div class="card"><div class="card-title">Time Entries</div><div class="card-value"><?= isset($search['times']) ? count($search['times']) : 0 ?></div></div>
                    </div>

                    <div class="panel">
                        <div class="panel-title">Employees</div>
                        <div class="table-responsive">
                            <table class="data-table">
                                <thead><tr><th>Name</th><th>Position</th><th>Department</th><th>Status</th></tr></thead>
                                <tbody>
                                    <?php foreach (($search['employees'] ?? []) as $e): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($e['name']) ?></td>
                                            <td><?= htmlspecialchars($e['position'] ?? '') ?></td>
                                            <td><?= htmlspecialchars($e['department'] ?? '') ?></td>
                                            <td><?= htmlspecialchars($e['status'] ?? '') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($search['employees'])): ?>
                                        <tr><td colspan="4">No matching employees.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="panel">
                        <div class="panel-title">Payrolls</div>
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Employee</th>
                                        <th>Period</th>
                                        <th>Regular</th>
                                        <th>OT</th>
                                        <th>Gross</th>
                                        <th>Taxes</th>
                                        <th>Deductions</th>
                                        <th>Net</th>
                                        <th>Status</th>
                                        <th>Notes</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach (($search['payrolls'] ?? []) as $p): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($p['employee_name']) ?></td>
                                            <td><?= date('M j', strtotime($p['pay_period_start'])) ?> - <?= date('M j, Y', strtotime($p['pay_period_end'])) ?></td>
                                            <td><?= number_format((float)($p['regular_hours'] ?? 0), 1) ?>h</td>
                                            <td><?= number_format((float)($p['overtime_hours'] ?? 0), 1) ?>h</td>
                                            <td>₱<?= number_format((float)($p['gross_pay'] ?? 0), 2) ?></td>
                                            <td>₱<?= number_format((float)($p['taxes'] ?? 0), 2) ?></td>
                                            <td>₱<?= number_format((float)($p['deductions'] ?? 0), 2) ?></td>
                                            <td><strong>₱<?= number_format((float)($p['net_pay'] ?? 0), 2) ?></strong></td>
                                            <td><?= htmlspecialchars(ucfirst($p['status'] ?? '')) ?></td>
                                            <td><?= htmlspecialchars($p['notes'] ?? '') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($search['payrolls'])): ?>
                                        <tr><td colspan="10">No matching payrolls.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="panel">
                        <div class="panel-title">Time Entries</div>
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Employee</th>
                                        <th>Date</th>
                                        <th>Hours</th>
                                        <th>Status</th>
                                        <th>Notes</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach (($search['times'] ?? []) as $t): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($t['employee_name']) ?></td>
                                            <td><?= date('M j, Y', strtotime($t['entry_date'])) ?></td>
                                            <td><?= number_format((float)($t['total_hours'] ?? 0), 1) ?>h</td>
                                            <td><?= htmlspecialchars($t['status'] ?? '') ?></td>
                                            <td><?= htmlspecialchars($t['notes'] ?? '') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($search['times'])): ?>
                                        <tr><td colspan="5">No matching time entries.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>

            <?php else: ?>
                <p>Page not found.</p>
            <?php endif; ?>
        </main>

        <footer class="footer">Demo payroll system prototype | Database: <?= strtoupper($dbType) ?></footer>
    <?php endif; ?>
    
    <?php 
    // Clear report data after displaying to prevent stale data
    if (isset($reportView)) {
        if ($reportView === 'compensation' && !empty($compensationReport)) {
            unset($_SESSION['report_data']);
        } elseif ($reportView === 'payroll_summary' && !empty($payrollSummary)) {
            unset($_SESSION['payroll_summary']);
        } elseif ($reportView === 'time_attendance' && !empty($timeAttendance)) {
            unset($_SESSION['time_attendance']);
        }
        unset($_SESSION['report_period']);
    }
    ?>

    <script>
        // Simple modal hide on click outside
        window.addEventListener('click', function(e){
            const mod = document.getElementById('addModal');
            if (mod && e.target === mod) mod.style.display = 'none';
        });

        // Update current time every second for Time & Attendance page (Manila timezone)
        function updateClock() {
            const now = new Date();
            const manilaTime = new Date(now.toLocaleString("en-US", {timeZone: "Asia/Manila"}));
            const timeString = manilaTime.toLocaleTimeString('en-PH', { hour12: true });
            const timeElement = document.getElementById('current-time');
            if (timeElement) {
                timeElement.textContent = timeString;
            }
        }
        
        if (document.getElementById('current-time')) {
            updateClock();
            setInterval(updateClock, 1000);
        }
    </script>
    <script>
    // Quick Payroll Entry auto-calculation (Manila locale)
    document.addEventListener('DOMContentLoaded', function() {
        const form = document.querySelector('.quick-payroll-form');
        if (!form) return;

        const selEmp = form.querySelector('select[name="employee_id"]');
        const reg = form.querySelector('input[name="regular_hours"]');
        const ot = form.querySelector('input[name="overtime_hours"]');
        const gross = form.querySelector('#qp-gross');
        const taxes = form.querySelector('#qp-taxes');
        const deds = form.querySelector('#qp-deductions');
        const net = form.querySelector('#qp-net');
        const dedBreakdown = form.querySelector('#qp-ded-breakdown');

        function parseNum(el) {
            const v = parseFloat((el && el.value) ? el.value : '0');
            return isNaN(v) ? 0 : v;
        }

        function getRate() {
            const opt = selEmp ? selEmp.options[selEmp.selectedIndex] : null;
            const rate = opt ? parseFloat(opt.getAttribute('data-rate') || '0') : 0;
            return isNaN(rate) ? 0 : rate;
        }

        function updateDedBreakdown(g) {
            if (!dedBreakdown) return;
            const sss = g * 0.045;
            const ph = g * 0.025;
            const pi = g * 0.02;
            dedBreakdown.textContent = `SSS ₱${sss.toFixed(2)} • PhilHealth ₱${ph.toFixed(2)} • Pag-IBIG ₱${pi.toFixed(2)}`;
        }

        function recomputeFromGross() {
            const g = parseNum(gross);
            const t = +(g * 0.10).toFixed(2);
            taxes.value = t.toFixed(2);
            // SSS 4.5%, PhilHealth 2.5%, Pag-IBIG 2%
            const calcDeds = +(g * (0.045 + 0.025 + 0.02)).toFixed(2);
            if (!deds.dataset.manual) {
                deds.value = calcDeds.toFixed(2);
            }
            const n = g - parseNum(taxes) - parseNum(deds);
            net.value = n.toFixed(2);
            updateDedBreakdown(g);
        }

        function recomputeGrossFromHours() {
            const rate = getRate();
            const r = parseNum(reg);
            const o = parseNum(ot);
            if (rate > 0) {
                const g = (r * rate) + (o * rate * 1.5);
                gross.value = g.toFixed(2);
                deds.dataset.manual = '';
                recomputeFromGross();
            }
        }

        if (selEmp) selEmp.addEventListener('change', recomputeGrossFromHours);
        if (reg) reg.addEventListener('input', recomputeGrossFromHours);
        if (ot) ot.addEventListener('input', recomputeGrossFromHours);
        if (gross) gross.addEventListener('input', function(){ deds.dataset.manual=''; recomputeFromGross(); });
        if (taxes) taxes.addEventListener('input', function(){
            const g = parseNum(gross);
            const n = g - parseNum(taxes) - parseNum(deds);
            net.value = n.toFixed(2);
            updateDedBreakdown(g);
        });
        if (deds) deds.addEventListener('input', function(){ deds.dataset.manual='1'; const g = parseNum(gross); const n = g - parseNum(taxes) - parseNum(deds); net.value = n.toFixed(2); updateDedBreakdown(g); });

        // Initial compute if fields have defaults
        recomputeGrossFromHours();
        if (parseNum(gross) > 0) recomputeFromGross(); else updateDedBreakdown(0);
    });
    </script>
    
    <script>
    // Employee CRUD functions
    function editEmployee(emp, mode = 'edit') {
        if (mode === 'edit') {
            document.getElementById('editModal').style.display = 'block';
            document.getElementById('editEmployeeId').value = emp.id;
            document.getElementById('editEmployeeName').value = emp.name || '';
            document.getElementById('editEmployeePosition').value = emp.position || '';
            document.getElementById('editEmployeeDepartment').value = emp.department || '';
            document.getElementById('editEmployeePhone').value = emp.phone || '';
            document.getElementById('editEmployeeRate').value = emp.hourly_rate || 15.00;
            document.getElementById('editEmployeeStatus').value = emp.status || 'active';
        }
    }
    
    function reactivateEmployee(id, name) {
        if (confirm(`Are you sure you want to reactivate employee "${name}"?\n\nThis will set their status back to Active and they will appear in regular employee lists.`)) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'index.php?page=people';
            form.innerHTML = `
                <input type="hidden" name="action" value="reactivate_employee">
                <input type="hidden" name="employee_id" value="${id}">
            `;
            document.body.appendChild(form);
            form.submit();
        }
    }
    
    function deleteEmployee(id, name) {
        const message = `Are you sure you want to remove employee "${name}"?\n\nNote: If they have time entries or payroll records, they will be deactivated (not permanently deleted) to preserve data integrity.`;
        if (confirm(message)) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'index.php?page=people';
            form.innerHTML = `
                <input type="hidden" name="action" value="delete_employee">
                <input type="hidden" name="employee_id" value="${id}">
            `;
            document.body.appendChild(form);
            form.submit();
        }
    }
    
    // Time Entry CRUD functions
    function editTimeEntry(entry) {
        document.getElementById('editTimeModal').style.display = 'block';
        document.getElementById('editTimeId').value = entry.id;
        document.getElementById('editTimeEmployee').value = entry.employee_id;
        document.getElementById('editTimeDate').value = entry.entry_date;
        document.getElementById('editTimeClockIn').value = entry.clock_in || '';
        document.getElementById('editTimeClockOut').value = entry.clock_out || '';
        document.getElementById('editTimeNotes').value = entry.notes || '';
    }
    
    function deleteTimeEntry(id) {
        if (confirm('Are you sure you want to delete this time entry?')) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'index.php?page=timecard';
            form.innerHTML = `
                <input type="hidden" name="action" value="delete_time_entry">
                <input type="hidden" name="entry_id" value="${id}">
            `;
            document.body.appendChild(form);
            form.submit();
        }
    }
    
    // Payroll CRUD functions
    function editPayroll(payroll) {
        document.getElementById('editPayrollModal').style.display = 'block';
        document.getElementById('editPayrollId').value = payroll.id;
        document.getElementById('editPayrollEmployee').value = payroll.employee_id;
        document.getElementById('editPayrollRegularHours').value = payroll.regular_hours || 0;
        document.getElementById('editPayrollOvertimeHours').value = payroll.overtime_hours || 0;
        document.getElementById('editPayrollGrossPay').value = payroll.gross_pay || 0;
        document.getElementById('editPayrollTaxes').value = payroll.taxes || 0;
        document.getElementById('editPayrollDeductions').value = payroll.deductions || 0;
        document.getElementById('editPayrollNetPay').value = payroll.net_pay || 0;
        document.getElementById('editPayrollStatus').value = payroll.status || 'draft';
        document.getElementById('editPayrollNotes').value = payroll.notes || '';
    }
    
    function deletePayroll(id) {
        if (confirm('Are you sure you want to delete this payroll record?')) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'index.php?page=payroll';
            form.innerHTML = `
                <input type="hidden" name="action" value="delete_payroll">
                <input type="hidden" name="payroll_id" value="${id}">
            `;
            document.body.appendChild(form);
            form.submit();
        }
    }
    
    // Modal close functions
    document.addEventListener('DOMContentLoaded', function() {
        // Close modals when clicking outside
        window.onclick = function(event) {
            const modals = document.querySelectorAll('.modal');
            modals.forEach(modal => {
                if (event.target === modal) {
                    modal.style.display = 'none';
                }
            });
        }
        
        // Mobile navigation toggle
        const mobileToggle = document.getElementById('mobile-nav-toggle');
        const burgerMenu = document.getElementById('burger-menu');
        const sidebar = document.querySelector('.sidebar');
        const mobileOverlay = document.getElementById('mobile-overlay');
        
        function toggleSidebar() {
            sidebar.classList.toggle('open');
            burgerMenu.classList.toggle('active');
            if (mobileOverlay) {
                mobileOverlay.classList.toggle('active');
            }
        }
        
        if (mobileToggle && sidebar) {
            mobileToggle.addEventListener('click', toggleSidebar);
        }
        
        if (burgerMenu && sidebar) {
            burgerMenu.addEventListener('click', toggleSidebar);
        }
        
        if (mobileOverlay) {
            mobileOverlay.addEventListener('click', function() {
                sidebar.classList.remove('open');
                burgerMenu.classList.remove('active');
                mobileOverlay.classList.remove('active');
            });
        }
        
        // Close mobile nav when window is resized to desktop
        window.addEventListener('resize', function() {
            if (window.innerWidth > 992) {
                sidebar.classList.remove('open');
                burgerMenu.classList.remove('active');
                if (mobileOverlay) {
                    mobileOverlay.classList.remove('active');
                }
            }
        });
    });

    // (Removed) Bulk selection and deletion scripts for payrolls
    </script>
    
    <style>
    .actions {
        text-align: center;
    }
    
    .btn-edit, .btn-delete {
        background: none;
        border: 1px solid #ddd;
        padding: 6px 8px;
        margin: 0 2px;
        border-radius: 4px;
        cursor: pointer;
        font-size: 14px;
    }
    
    .btn-edit:hover {
        background: #e3f2fd;
        border-color: #2196f3;
        color: #2196f3;
    }
    
    .btn-delete:hover {
        background: #ffebee;
        border-color: #f44336;
        color: #f44336;
    }
    
    .role {
        padding: 2px 8px;
        border-radius: 12px;
        font-size: 12px;
        font-weight: 600;
        text-transform: uppercase;
    }
    
    .role.admin {
        background: #e3f2fd;
        color: #1976d2;
    }
    
    .role.manager {
        background: #f3e5f5;
        color: #7b1fa2;
    }
    
    .role.user {
        background: #e8f5e8;
        color: #388e3c;
    }
    
    .status.inactive {
        background: #ffebee;
        color: #d32f2f;
    }

    /* Toolbar Clock Status Styles */
    .toolbar {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        margin-bottom: 1rem;
        padding: 1rem;
        background: #f8f9fa;
        border-radius: 8px;
        border: 1px solid #e9ecef;
    }

    .toolbar-left h3 {
        margin: 0 0 0.5rem 0;
    }

    .toolbar-right {
        text-align: right;
    }

    .clock-in-status, .clock-out-status {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 8px 12px;
        border-radius: 6px;
        font-size: 14px;
        margin-top: 0.5rem;
        animation: fadeIn 0.5s ease-in;
    }

    .clock-in-status {
        background: #d4edda;
        border: 1px solid #c3e6cb;
        color: #155724;
    }

    .clock-out-status {
        background: #f8d7da;
        border: 1px solid #f5c6cb;
        color: #721c24;
    }

    .clock-in-status .employee-name {
        font-weight: 600;
        color: #0c5460;
    }

    .clock-out-status .employee-name {
        font-weight: 600;
        color: #721c24;
    }

    .action-text {
        font-size: 13px;
    }

    @keyframes fadeIn {
        from { opacity: 0; transform: translateY(-10px); }
        to { opacity: 1; transform: translateY(0); }
    }

    @media (max-width: 768px) {
        .toolbar {
            flex-direction: column;
            gap: 1rem;
        }
        
        .toolbar-right {
            text-align: left;
        }
    }
    </style>
</body>
</html>