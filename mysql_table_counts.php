<?php
require_once __DIR__ . '/mysql_config.php';
try {
    $db = getMySQLConnection();
    $tables = ['users','employees','time_entries','payrolls'];
    foreach ($tables as $t) {
        $count = $db->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
        echo "Table $t: $count records\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
