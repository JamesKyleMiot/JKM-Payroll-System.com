<?php
// db_queries_example.php
// Examples for common operations adapted for MySQL.
// Include: $db = require 'db_connect.php';

$db = require __DIR__ . '/db_connect.php';

// 1) Count employees
$totalEmployees = (int)$db->query("SELECT COUNT(*) FROM employees")->fetchColumn();
echo "Total employees: $totalEmployees\n";

// 2) Pending payrolls
$pending = (int)$db->query("SELECT COUNT(*) FROM payrolls WHERE status = 'Pending'")->fetchColumn();
echo "Pending payrolls: $pending\n";

// 3) Upcoming pay date (earliest start_date for pending payrolls)
$next = $db->query("SELECT MIN(start_date) AS next_start FROM payrolls WHERE status='Pending'")->fetchColumn();
echo "Upcoming pay start date: " . ($next ?: 'none') . "\n";

// 4) Payroll summary grouped by month (MySQL version of strftime('%Y-%m', start_date))
$stmt = $db->query("
    SELECT DATE_FORMAT(start_date, '%Y-%m') AS month, SUM(IFNULL(net_pay,0) + IFNULL(deductions,0)) AS total
    FROM payrolls
    GROUP BY month
    ORDER BY month ASC
");
$rows = $stmt->fetchAll();
foreach ($rows as $r) {
    echo "{$r['month']} => {$r['total']}\n";
}

// 5) Get payrolls for a month filter (example: 2024-07)
$month = '2024-07';
$stmt = $db->prepare("
    SELECT p.*, e.name AS employee_name
    FROM payrolls p
    JOIN employees e ON p.employee_id = e.id
    WHERE DATE_FORMAT(p.start_date, '%Y-%m') = ?
    ORDER BY e.name
");
$stmt->execute([$month]);
$payrolls = $stmt->fetchAll();
print_r($payrolls);