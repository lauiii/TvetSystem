<?php
require_once __DIR__ . '/../config.php';
header_remove();
echo "Inspecting all DB tables columns:\n";
$tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
foreach ($tables as $t) {
    echo "Table: $t\n";
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM $t")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($cols as $c) {
            echo " - $c\n";
        }
    } catch (Exception $e) {
        echo " Error: " . $e->getMessage() . "\n";
    }
    echo "\n";
}
?>
