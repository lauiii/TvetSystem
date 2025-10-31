<?php
require_once 'config.php';

try {
    $stmt = $pdo->query('SELECT COUNT(*) FROM assessment_items');
    echo 'assessment_items count: ' . $stmt->fetchColumn() . PHP_EOL;

    $stmt = $pdo->query('SELECT COUNT(*) FROM assessments');
    echo 'assessments count: ' . $stmt->fetchColumn() . PHP_EOL;

    // Check if there are any assessment_items for the course
    $stmt = $pdo->query('SELECT ai.id, ai.name, ac.name as criteria_name FROM assessment_items ai INNER JOIN assessment_criteria ac ON ai.criteria_id = ac.id LIMIT 5');
    echo 'Sample assessment_items:' . PHP_EOL;
    while ($row = $stmt->fetch()) {
        echo "- ID: {$row['id']}, Name: {$row['name']}, Criteria: {$row['criteria_name']}" . PHP_EOL;
    }

} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage() . PHP_EOL;
}
?>
