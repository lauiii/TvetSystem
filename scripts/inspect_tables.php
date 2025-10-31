<?php
require_once __DIR__ . '/../config.php';
header_remove();
echo "Inspecting DB tables columns:\n";
$tables = ['users','flags','school_years','enrollments'];
foreach ($tables as $t) {
    echo "Table: $t\n";
    try {
        $q = $pdo->prepare('SHOW COLUMNS FROM ' . $t);
        $q->execute();
        $cols = $q->fetchAll(PDO::FETCH_COLUMN);
        foreach ($cols as $c) {
            echo " - $c\n";
        }
    } catch (Exception $e) {
        echo " Error: " . $e->getMessage() . "\n";
    }
    echo "\n";
}

?>
