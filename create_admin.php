<?php
// create_admin.php
// Usage (CLI): php create_admin.php username email password
// Usage (web): create_admin.php?username=...&email=...&password=...

$dbFile = __DIR__ . '/data/payroll_db';
if (!file_exists($dbFile)) {
    echo "Database not found at $dbFile. Run init_db.php first.";
    exit(1);
}

try {
    $db = new PDO('sqlite:' . $dbFile);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Read input from CLI args or GET params
    $username = null;
    $email = null;
    $password = null;

    if (PHP_SAPI === 'cli') {
        $argv = $_SERVER['argv'];
        if (isset($argv[1])) $username = $argv[1];
        if (isset($argv[2])) $email = $argv[2];
        if (isset($argv[3])) $password = $argv[3];
    } else {
        $username = $_GET['username'] ?? null;
        $email = $_GET['email'] ?? null;
        $password = $_GET['password'] ?? null;
    }

    // Defaults if not provided
    if (empty($username)) $username = 'admin2';
    if (empty($email)) $email = 'admin2@payroll.local';
    if (empty($password)) $password = 'Admin@123';

    // Ensure users table exists
    $tbl = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='users'")->fetchColumn();
    if (!$tbl) {
        echo "Users table not found. Run init_db.php to initialize the database.";
        exit(1);
    }

    // Check if username or email exists
    $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE username = ? OR email = ?");
    $stmt->execute([$username, $email]);
    $exists = (int)$stmt->fetchColumn();

    if ($exists > 0) {
        echo "User with that username or email already exists.\n";
        // Show existing users with same name/email
        $stmt = $db->prepare("SELECT id, username, email, role, created_at FROM users WHERE username = ? OR email = ?");
        $stmt->execute([$username, $email]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            echo "- ID: {$r['id']}, Username: {$r['username']}, Email: {$r['email']}, Role: {$r['role']}, Created: {$r['created_at']}\n";
        }
        exit(0);
    }

    $hashed = password_hash($password, PASSWORD_DEFAULT);
    $ins = $db->prepare("INSERT INTO users (username, email, password, role) VALUES (?, ?, ?, 'admin')");
    $ins->execute([$username, $email, $hashed]);

    echo "Created admin user successfully:\n";
    echo "Username: $username\n";
    echo "Email: $email\n";
    echo "Password: $password\n";
    echo "You can now login at /Payroll/index.php?page=login\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
    exit(1);
}

?>