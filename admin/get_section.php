<?php
/**
 * API endpoint to get section data for editing
 */
require_once __DIR__ . '/../config.php';
requireRole('admin');

header('Content-Type: application/json');

$section_id = (int)($_GET['id'] ?? 0);
if ($section_id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid section ID']);
    exit;
}

try {
    // Get section details
    $stmt = $pdo->prepare('SELECT * FROM sections WHERE id = ?');
    $stmt->execute([$section_id]);
    $section = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$section) {
        http_response_code(404);
        echo json_encode(['error' => 'Section not found']);
        exit;
    }

    // Get assigned instructors
    $stmt = $pdo->prepare('SELECT instructor_id FROM instructor_sections WHERE section_id = ?');
    $stmt->execute([$section_id]);
    $instructors = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $section['instructors'] = $instructors;

    echo json_encode($section);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
?>
