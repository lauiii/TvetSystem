<?php
/**
 * Instructor - Manage Assessment Criteria and Items
 * Add, edit, and delete assessment criteria and their items for courses they teach
 */

require_once '../config.php';
require_once __DIR__ . '/../include/functions.php';
requireRole('instructor');

$instructorId = $_SESSION['user_id'];
$error = '';
$msg = '';

// Ensure assessment_criteria schema allows same criteria across sections of the same course
try {
    // 1) Add slug column if missing
    try { $pdo->exec("ALTER TABLE assessment_criteria ADD COLUMN slug VARCHAR(190) NULL AFTER name"); } catch (Exception $e) { /* ignore */ }
    // 2) Backfill slug for any nulls (MySQL8, then PHP fallback)
    try {
        $pdo->exec("UPDATE assessment_criteria SET slug = LOWER(TRIM(REGEXP_REPLACE(name, '[^a-z0-9]+', '-'))) WHERE slug IS NULL OR slug = ''");
    } catch (Exception $e) {
        try {
            $st=$pdo->query("SELECT id, name FROM assessment_criteria WHERE slug IS NULL OR slug = ''");
            $rows=$st?$st->fetchAll(PDO::FETCH_ASSOC):[];
            foreach($rows as $r){ $nm=strtolower(trim((string)$r['name'])); $slug=preg_replace('/[^a-z0-9]+/i','-',$nm); $slug=trim($slug,'-'); $up=$pdo->prepare("UPDATE assessment_criteria SET slug=? WHERE id=?"); $up->execute([$slug,(int)$r['id']]); }
        } catch (Exception $ie) { /* ignore */ }
    }
    // 3) Make slug NOT NULL (safe)
    try { $pdo->exec("ALTER TABLE assessment_criteria MODIFY slug VARCHAR(190) NOT NULL"); } catch (Exception $e) { /* ignore */ }

    // 4) Drop any UNIQUE index that is course-scoped (first column course_id)
    try {
        $idxStmt = $pdo->query("SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'assessment_criteria' AND NON_UNIQUE = 0 GROUP BY INDEX_NAME HAVING MIN(SEQ_IN_INDEX)=1 AND SUM(CASE WHEN COLUMN_NAME='course_id' AND SEQ_IN_INDEX=1 THEN 1 ELSE 0 END)>0");
        $toDrop = $idxStmt ? $idxStmt->fetchAll(PDO::FETCH_COLUMN) : [];
        foreach ($toDrop as $ix) { if ($ix && strtolower($ix) !== 'primary') { $pdo->exec("ALTER TABLE assessment_criteria DROP INDEX `".str_replace("`","``",$ix)."`"); } }
    } catch (Exception $e) { /* ignore */ }
    // Also try legacy name
    try { $pdo->exec("ALTER TABLE assessment_criteria DROP INDEX unique_criteria"); } catch (Exception $e) { /* ignore */ }

    // 5) Ensure section-scoped unique exists (use a distinct name to avoid conflict)
    try { $pdo->exec("ALTER TABLE assessment_criteria ADD UNIQUE KEY uniq_ac_section_period_slug (section_id, period, slug)"); } catch (Exception $e) { /* ignore */ }
} catch (Exception $e) { /* ignore */ }

// Get sections taught by this instructor
$sectionsStmt = $pdo->prepare("
    SELECT s.id AS section_id, s.section_code, s.section_name,
           c.id AS course_id, c.course_code, c.course_name, c.year_level, c.semester, p.name as program_name
    FROM instructor_sections ins
    INNER JOIN sections s ON ins.section_id = s.id
    INNER JOIN courses c ON s.course_id = c.id
    LEFT JOIN programs p ON c.program_id = p.id
    WHERE ins.instructor_id = ? AND s.status = 'active'
    ORDER BY c.course_code, s.section_code
");
$sectionsStmt->execute([$instructorId]);
$sectionsList = $sectionsStmt->fetchAll(PDO::FETCH_ASSOC);

// Get selected section
$sectionId = intval($_GET['section_id'] ?? ($sectionsList[0]['section_id'] ?? 0));
// Support legacy/link using course_id by mapping to first matching section for this instructor
if ($sectionId === 0) {
    $qCourseId = intval($_GET['course_id'] ?? 0);
    if ($qCourseId > 0) {
        $map = $pdo->prepare("SELECT s.id FROM instructor_sections ins INNER JOIN sections s ON s.id=ins.section_id WHERE ins.instructor_id=? AND s.course_id=? AND s.status='active' ORDER BY s.id LIMIT 1");
        $map->execute([$instructorId, $qCourseId]);
        $sid = (int)$map->fetchColumn();
        if ($sid > 0) { header('Location: assessments.php?section_id='.$sid); exit; }
    }
}
// Derive course id and selected section info
$courseId = 0; $selectedSection = null;
foreach ($sectionsList as $row) {
    if ((int)$row['section_id'] === $sectionId) {
        $courseId = (int)$row['course_id'];
        $selectedSection = $row;
        break;
    }
}

// Handle add criteria
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_criteria') {
    $course_id = intval($_POST['course_id'] ?? 0);
    $section_id = intval($_POST['section_id'] ?? $sectionId);
    $name = sanitize($_POST['name'] ?? '');
    $period = strtolower(sanitize($_POST['period'] ?? ''));
    $percentage = floatval($_POST['percentage'] ?? 0);

    // Verify instructor teaches this section
    $courseCheck = $pdo->prepare("SELECT s.id FROM instructor_sections ins INNER JOIN sections s ON ins.section_id = s.id WHERE s.course_id = ? AND s.id = ? AND ins.instructor_id = ?");
    $courseCheck->execute([$course_id, $section_id, $instructorId]);
    if (!$courseCheck->fetch()) {
        $error = 'You do not have permission to manage assessments for this course.';
    } elseif (empty($name) || empty($period) || $percentage <= 0 || $percentage > 100) {
        $error = 'All fields are required and percentage must be between 1-100.';
    } else {
        // Define period limits
        $periodLimits = [
            'prelim' => 100,
            'midterm' => 100,
            'finals' => 100
        ];
        // Base weights for computing effective contribution
        $periodWeights = [
            'prelim' => 30,
            'midterm' => 30,
            'finals' => 40,
            'final' => 40
        ];
        // Base weights for computing effective contribution
        $periodWeights = [
            'prelim' => 30,
            'midterm' => 30,
            'finals' => 40,
            'final' => 40
        ];

        // Check total percentage for the specific period
        $periodCheck = $pdo->prepare("SELECT SUM(percentage) as total FROM assessment_criteria WHERE section_id = ? AND period = ?");
        $periodCheck->execute([$section_id, $period]);
        $currentPeriodTotal = floatval($periodCheck->fetch()['total'] ?? 0);

        if (!isset($periodLimits[$period])) {
            $error = 'Invalid period selected.';
        } elseif ($currentPeriodTotal + $percentage > $periodLimits[$period]) {
            $error = 'Total criteria percentage for ' . ucfirst($period) . ' cannot exceed ' . $periodLimits[$period] . '%. Current total: ' . $currentPeriodTotal . '%.';
        } else {
            try {
                // Build slug from name (lowercase, alnum + dashes) and normalize period lowercase
                $slug = strtolower(trim($name));
                $slug = preg_replace('/[^a-z0-9]+/i', '-', $slug);
                $slug = trim($slug, '-');

                // Transaction for duplicate checks and insert
                $pdo->beginTransaction();
                try {
                    $dup = null;
                    // Prefer slug-based duplicate using section_id (most common schema)
                    try {
                        $dupCrit = $pdo->prepare('SELECT id FROM assessment_criteria WHERE section_id = ? AND period = ? AND slug = ? LIMIT 1');
                        $dupCrit->execute([$section_id, $period, $slug]);
                        $dup = $dupCrit->fetch(PDO::FETCH_ASSOC);
                    } catch (Exception $e) {
                        $dup = null;
                    }
                    // If slug column not present on table, fallback to case-insensitive name under section
                    if (!$dup) {
                        try {
                            $dupCrit3 = $pdo->prepare('SELECT id FROM assessment_criteria WHERE section_id = ? AND period = ? AND TRIM(name) COLLATE utf8mb4_general_ci = TRIM(?) COLLATE utf8mb4_general_ci LIMIT 1');
                            $dupCrit3->execute([$section_id, $period, $name]);
                            $dup = $dupCrit3->fetch(PDO::FETCH_ASSOC);
                        } catch (Exception $e3) { /* ignore */ }
                    }

                    if ($dup) {
                        // If duplicate exists but has NO items, treat as stale: delete then allow re-insert
                        $itemsCountStmt = $pdo->prepare('SELECT COUNT(*) FROM assessment_items WHERE criteria_id = ?');
                        $itemsCountStmt->execute([(int)$dup['id']]);
                        $cntItems = (int)$itemsCountStmt->fetchColumn();
                        if ($cntItems === 0) {
                            $delStale = $pdo->prepare('DELETE FROM assessment_criteria WHERE id = ?');
                            $delStale->execute([(int)$dup['id']]);
                            // proceed to insert fresh criteria
                        } else {
                            // Criteria already exists and has items — reuse instead of erroring
                            $pdo->rollBack();
                            $msg = 'Criteria already exists for this section and period.';
                            goto after_add_criteria_txn;
                        }
                    }

                    // compute effective percentage (within-period × period weight)
                    $pkey = strtolower($period) === 'finals' ? 'final' : strtolower($period);
                    $pweight = $periodWeights[$pkey] ?? 0;
                    $effective = ($pweight > 0) ? (($percentage / 100.0) * $pweight) : 0;

                    // Try insert including slug; fallback if column missing
                    try {
                        $stmt = $pdo->prepare('INSERT INTO assessment_criteria (course_id, section_id, name, slug, period, percentage, effective_percentage) VALUES (?, ?, ?, ?, ?, ?, ?)');
                        $stmt->execute([$course_id, $section_id, $name, $slug, $period, $percentage, $effective]);
                    } catch (Exception $e) {
                        try {
                            $stmt = $pdo->prepare('INSERT INTO assessment_criteria (course_id, section_id, name, period, percentage, effective_percentage) VALUES (?, ?, ?, ?, ?, ?)');
                            $stmt->execute([$course_id, $section_id, $name, $period, $percentage, $effective]);
                        } catch (Exception $e2) {
                            // ultimate fallback without effective_percentage
                            $stmt = $pdo->prepare('INSERT INTO assessment_criteria (course_id, section_id, name, period, percentage) VALUES (?, ?, ?, ?, ?)');
                            $stmt->execute([$course_id, $section_id, $name, $period, $percentage]);
                        }
                    }

                    $pdo->commit();
                    $msg = 'Assessment criteria added successfully.';
                } catch (Exception $e) {
                    $pdo->rollBack();
                    throw $e;
                }
after_add_criteria_txn:
            } catch (Exception $e) {
                $error = 'Failed to add criteria: ' . $e->getMessage();
            }
        }
    }
}

// Handle copy assessments from another section of the same course
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'copy_from_section') {
    $source_section_id = (int)($_POST['source_section_id'] ?? 0);
    $target_section_id = (int)$sectionId;
    try {
        if ($target_section_id <= 0 || $source_section_id <= 0) {
            throw new Exception('Please select a section to copy from.');
        }
        // Verify instructor teaches both sections and they are for the same course
        $secInfoStmt = $pdo->prepare("SELECT s.id, s.course_id FROM instructor_sections ins INNER JOIN sections s ON s.id=ins.section_id WHERE ins.instructor_id=? AND s.id IN (?,?) ORDER BY s.id");
        $secInfoStmt->execute([$instructorId, $source_section_id, $target_section_id]);
        $rows = $secInfoStmt->fetchAll(PDO::FETCH_ASSOC);
        $byId = []; foreach ($rows as $r) { $byId[(int)$r['id']] = (int)$r['course_id']; }
        if (!isset($byId[$source_section_id]) || !isset($byId[$target_section_id])) {
            throw new Exception('You are not assigned to one or both sections.');
        }
        if ((int)$byId[$source_section_id] !== (int)$byId[$target_section_id]) {
            throw new Exception('Sections are not for the same course.');
        }

        // Fetch criteria from source
        $critStmt = $pdo->prepare("SELECT id, name, period, percentage, COALESCE(slug, LOWER(TRIM(name))) AS slug, COALESCE(effective_percentage, NULL) AS eff FROM assessment_criteria WHERE section_id = ? ORDER BY id");
        $critStmt->execute([$source_section_id]);
        $srcCriteria = $critStmt->fetchAll(PDO::FETCH_ASSOC);

        if (!$srcCriteria) { throw new Exception('Source section has no assessments to copy.'); }

        $pdo->beginTransaction();
        try {
            $insertCritWithEff = $pdo->prepare('INSERT INTO assessment_criteria (course_id, section_id, name, slug, period, percentage, effective_percentage) VALUES (?, ?, ?, ?, ?, ?, ?)');
            $insertCrit = $pdo->prepare('INSERT INTO assessment_criteria (course_id, section_id, name, slug, period, percentage) VALUES (?, ?, ?, ?, ?, ?)');
            $findDup = $pdo->prepare('SELECT id FROM assessment_criteria WHERE section_id=? AND period=? AND slug=? LIMIT 1');
            $itemsByCrit = $pdo->prepare('SELECT name, total_score FROM assessment_items WHERE criteria_id = ? ORDER BY id');
            $insertItem = $pdo->prepare('INSERT INTO assessment_items (criteria_id, name, total_score) VALUES (?, ?, ?)');

            $newIdByOld = [];
            foreach ($srcCriteria as $c) {
                $slug = strtolower(trim((string)$c['slug']));
                $slug = preg_replace('/[^a-z0-9]+/i','-',$slug);
                $slug = trim($slug,'-');
                // Skip if already exists in target (by slug + period)
                $findDup->execute([$target_section_id, $c['period'], $slug]);
                $dupId = (int)($findDup->fetchColumn() ?: 0);
                if ($dupId > 0) { $newIdByOld[(int)$c['id']] = $dupId; continue; }

                // Insert criteria
                $eff = $c['eff'];
                $ok = false; $newId = 0;
                try {
                    $insertCritWithEff->execute([$byId[$target_section_id], $target_section_id, $c['name'], $slug, $c['period'], $c['percentage'], $eff]);
                    $newId = (int)$pdo->lastInsertId(); $ok = true;
                } catch (Exception $e) {
                    $insertCrit->execute([$byId[$target_section_id], $target_section_id, $c['name'], $slug, $c['period'], $c['percentage']]);
                    $newId = (int)$pdo->lastInsertId(); $ok = true;
                }
                if ($ok && $newId > 0) {
                    $newIdByOld[(int)$c['id']] = $newId;
                    // Copy items
                    $itemsByCrit->execute([(int)$c['id']]);
                    foreach ($itemsByCrit->fetchAll(PDO::FETCH_ASSOC) as $it) {
                        $insertItem->execute([$newId, $it['name'], (float)$it['total_score']]);
                    }
                }
            }
            $pdo->commit();
            $msg = 'Assessments copied successfully.';
        } catch (Exception $ie) {
            $pdo->rollBack();
            throw $ie;
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Handle add item
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_item') {
    $criteria_id = intval($_POST['criteria_id'] ?? 0);
    $name = sanitize($_POST['item_name'] ?? '');
    $total_score = floatval($_POST['total_score'] ?? 0);

    // Verify criteria belongs to a section taught by this instructor
    $criteriaCheck = $pdo->prepare("        
        SELECT ac.id FROM assessment_criteria ac
        INNER JOIN instructor_sections ins ON ac.section_id = ins.section_id
        WHERE ac.id = ? AND ins.instructor_id = ?
    ");
    $criteriaCheck->execute([$criteria_id, $instructorId]);
    if (!$criteriaCheck->fetch()) {
        $error = 'You do not have permission to manage this criteria.';
    } elseif (empty($name)) {
        $error = 'Item name is required.';
    } elseif ($total_score <= 0) {
        $error = 'Total score must be greater than 0.';
    } else {
        try {
            // Use transaction + robust duplicate check to avoid race conditions
            $pdo->beginTransaction();
            $dupCheck = $pdo->prepare('SELECT id FROM assessment_items WHERE criteria_id = ? AND TRIM(name) COLLATE utf8mb4_general_ci = TRIM(?) COLLATE utf8mb4_general_ci LIMIT 1');
            $dupCheck->execute([$criteria_id, $name]);
            if ($dupCheck->fetch()) {
                $pdo->rollBack();
                $error = 'An assessment item with that name already exists for this criteria.';
            } else {
                $stmt = $pdo->prepare('INSERT INTO assessment_items (criteria_id, name, total_score) VALUES (?, ?, ?)');
                $stmt->execute([$criteria_id, $name, $total_score]);
                $pdo->commit();
                $msg = 'Assessment item added successfully.';
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $error = 'Failed to add item: ' . $e->getMessage();
        }
    }
}

// Handle edit criteria
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_criteria') {
    $id = intval($_POST['id'] ?? 0);
    $name = sanitize($_POST['name'] ?? '');
    $period = sanitize($_POST['period'] ?? '');
    $percentage = floatval($_POST['percentage'] ?? 0);

    // Verify criteria belongs to instructor's section
    $criteriaCheck = $pdo->prepare("
        SELECT ac.id, ac.course_id, ac.period, ac.section_id FROM assessment_criteria ac
        INNER JOIN instructor_sections ins ON ac.section_id = ins.section_id
        WHERE ac.id = ? AND ins.instructor_id = ?
    ");
    $criteriaCheck->execute([$id, $instructorId]);
    $criteriaData = $criteriaCheck->fetch();
    if (!$criteriaData) {
        $error = 'You do not have permission to edit this criteria.';
    } elseif (empty($name) || empty($period) || $percentage <= 0 || $percentage > 100) {
        $error = 'All fields are required and percentage must be between 1-100.';
    } else {
        // Define period limits
        $periodLimits = [
            'prelim' => 100,
            'midterm' => 100,
            'finals' => 100
        ];

        // Check total percentage for the specific period excluding current criteria
        $periodCheck = $pdo->prepare("SELECT SUM(percentage) as total FROM assessment_criteria WHERE section_id = ? AND period = ? AND id != ?");
        $periodCheck->execute([$criteriaData['section_id'], $period, $id]);
        $currentPeriodTotal = floatval($periodCheck->fetch()['total'] ?? 0);

        if (!isset($periodLimits[$period])) {
            $error = 'Invalid period selected.';
        } elseif ($currentPeriodTotal + $percentage > $periodLimits[$period]) {
            $error = 'Total criteria percentage for ' . ucfirst($period) . ' cannot exceed ' . $periodLimits[$period] . '%. Current total (excluding this criteria): ' . $currentPeriodTotal . '%.';
        } else {
            try {
                $pkey = strtolower($period) === 'finals' ? 'final' : strtolower($period);
                $pweight = $periodWeights[$pkey] ?? 0;
                $effective = ($pweight > 0) ? (($percentage / 100.0) * $pweight) : 0;
                try {
                    $stmt = $pdo->prepare('UPDATE assessment_criteria SET name = ?, period = ?, percentage = ?, effective_percentage = ? WHERE id = ?');
                    $stmt->execute([$name, $period, $percentage, $effective, $id]);
                } catch (Exception $e) {
                    // fallback if column not yet added
                    $stmt = $pdo->prepare('UPDATE assessment_criteria SET name = ?, period = ?, percentage = ? WHERE id = ?');
                    $stmt->execute([$name, $period, $percentage, $id]);
                }
                $msg = 'Assessment criteria updated successfully.';
            } catch (Exception $e) {
                $error = 'Failed to update criteria: ' . $e->getMessage();
            }
        }
    }
}

// Handle edit item
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_item') {
    $id = intval($_POST['id'] ?? 0);
    $name = sanitize($_POST['item_name'] ?? '');
    $total_score = floatval($_POST['total_score'] ?? 0);

    // Verify item belongs to instructor's section
    $itemCheck = $pdo->prepare("
        SELECT ai.id FROM assessment_items ai
        INNER JOIN assessment_criteria ac ON ai.criteria_id = ac.id
        INNER JOIN instructor_sections ins ON ac.section_id = ins.section_id
        WHERE ai.id = ? AND ins.instructor_id = ?
    ");
    $itemCheck->execute([$id, $instructorId]);
    if (!$itemCheck->fetch()) {
        $error = 'You do not have permission to edit this item.';
    } elseif (empty($name)) {
        $error = 'Item name is required.';
    } elseif ($total_score <= 0) {
        $error = 'Total score must be greater than 0.';
    } else {
        try {
            $stmt = $pdo->prepare('UPDATE assessment_items SET name = ?, total_score = ? WHERE id = ?');
            $stmt->execute([$name, $total_score, $id]);
            $msg = 'Assessment item updated successfully.';
        } catch (Exception $e) {
            $error = 'Failed to update item: ' . $e->getMessage();
        }
    }
}

// Handle delete criteria
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_criteria') {
    $id = intval($_POST['id'] ?? 0);

    // Verify criteria belongs to instructor's section
    $criteriaCheck = $pdo->prepare("
        SELECT ac.id FROM assessment_criteria ac
        INNER JOIN instructor_sections ins ON ac.section_id = ins.section_id
        WHERE ac.id = ? AND ins.instructor_id = ?
    ");
    $criteriaCheck->execute([$id, $instructorId]);
    if (!$criteriaCheck->fetch()) {
        $error = 'You do not have permission to delete this criteria.';
    } else {
        try {
            $stmt = $pdo->prepare('DELETE FROM assessment_criteria WHERE id = ?');
            $stmt->execute([$id]);
            $msg = 'Assessment criteria deleted successfully.';
        } catch (Exception $e) {
            $error = 'Failed to delete criteria: ' . $e->getMessage();
        }
    }
}

// Handle delete item
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_item') {
    $id = intval($_POST['id'] ?? 0);

    // Verify item belongs to instructor's section
    $itemCheck = $pdo->prepare("
        SELECT ai.id FROM assessment_items ai
        INNER JOIN assessment_criteria ac ON ai.criteria_id = ac.id
        INNER JOIN instructor_sections ins ON ac.section_id = ins.section_id
        WHERE ai.id = ? AND ins.instructor_id = ?
    ");
    $itemCheck->execute([$id, $instructorId]);
    if (!$itemCheck->fetch()) {
        $error = 'You do not have permission to delete this item.';
    } else {
        try {
            // Fetch parent criteria_id BEFORE deletion
            $critId = 0;
            try {
                $pre = $pdo->prepare('SELECT criteria_id FROM assessment_items WHERE id = ?');
                $pre->execute([$id]);
                $critId = (int)$pre->fetchColumn();
            } catch (Exception $e) { $critId = 0; }

            // Delete the item
            $stmt = $pdo->prepare('DELETE FROM assessment_items WHERE id = ?');
            $stmt->execute([$id]);

            // If the parent criteria now has zero items, auto-delete the criteria to avoid empty rows
            if ($critId > 0) {
                try {
                    $remainStmt = $pdo->prepare('SELECT COUNT(*) FROM assessment_items WHERE criteria_id = ?');
                    $remainStmt->execute([$critId]);
                    if ((int)$remainStmt->fetchColumn() === 0) {
                        $delCrit = $pdo->prepare('DELETE FROM assessment_criteria WHERE id = ?');
                        $delCrit->execute([$critId]);
                    }
                } catch (Exception $e) { /* ignore */ }
            }
            $msg = 'Assessment item deleted successfully.';
        } catch (Exception $e) {
            $error = 'Failed to delete item: ' . $e->getMessage();
        }
    }
}

// ✅ Fixed version — prevents duplicated criteria
$criteria = [];
if ($sectionId > 0) {
    // Step 1: Fetch all criteria for this section (one row per criteria)
    $stmt = $pdo->prepare("
        SELECT id, name, period, percentage
        FROM assessment_criteria
        WHERE section_id = ?
        ORDER BY period, id ASC
    ");
    $stmt->execute([$sectionId]);
    $criteria = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Optional debug
    try {
        $debugLog = date('c') . " | [criteria] course_id={$courseId}, count=" . count($criteria) . "\n";
        @file_put_contents(__DIR__ . '/../logs/grades_errors.log', $debugLog, FILE_APPEND);
    } catch (Exception $e) { /* ignore */ }

    // Step 2: Attach all assessment items per criteria
    foreach ($criteria as &$c) {
        $stmt2 = $pdo->prepare("
            SELECT id, name, total_score
            FROM assessment_items
            WHERE criteria_id = ?
            ORDER BY id ASC
        ");
        $stmt2->execute([$c['id']]);
        $c['items'] = $stmt2->fetchAll(PDO::FETCH_ASSOC);
    }
    unset($c);
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Assessments - TVET System</title>
    <link rel="icon" type="image/svg+xml" href="../public/assets/icon/logo.svg">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f6fa;
            color: #333;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 30px;
        }

        .page-header {
            background: white;
            padding: 25px;
            border-radius: 10px;
            margin-bottom: 30px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }

        .page-header h1 {
            color: #6a0dad;
            margin-bottom: 10px;
        }

        .back-link {
            color: #6a0dad;
            text-decoration: none;
            display: inline-block;
            margin-bottom: 15px;
        }

        .alert {
            padding: 15px 20px;
            border-radius: 6px;
            margin-bottom: 20px;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border-left: 4px solid #28a745;
        }

        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border-left: 4px solid #dc3545;
        }

        .card {
            background: white;
            padding: 25px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            margin-bottom: 30px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
            color: #555;
        }

        .form-control {
            width: 100%;
            padding: 10px;
            border: 2px solid #e0e0e0;
            border-radius: 6px;
            font-size: 14px;
        }

        .form-control:focus {
            outline: none;
            border-color: #6a0dad;
        }

        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-block;
        }

        .btn-primary {
            background: #6a0dad;
            color: white;
        }

        .btn-primary:hover {
            background: #5a0c9d;
        }

        .btn-secondary {
            background: #6c757d;
            color: white;
        }

        .btn-secondary:hover {
            background: #5a6268;
        }

        .btn-danger {
            background: #dc3545;
            color: white;
        }

        .btn-danger:hover {
            background: #c82333;
        }

        .table-wrapper {
            overflow-x: auto;
        }

        .assessments-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 600px;
        }

        .assessments-table th {
            background: #6a0dad;
            color: white;
            padding: 12px;
            text-align: left;
            font-weight: 600;
        }

        .assessments-table td {
            padding: 12px;
            border-bottom: 1px solid #e0e0e0;
        }

        .assessments-table tr:hover {
            background: #f8f9fa;
        }

        /* Period indicator row */
        .period-banner {
            text-align: center;
            background: #f3e8ff;
            color: #6a0dad;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            border-top: 2px solid #6a0dad;
            border-bottom: 2px solid #6a0dad;
        }

        /* Remaining percentage styling under period line */
        .remaining-text {
            display: block;
            color: #8B5CF6; /* violet to fit theme */
            font-weight: 600;
            font-size: 0.9rem;
            margin-top: 4px;
            text-align: left;
        }

        .actions {
            display: flex;
            gap: 5px;
        }

        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
        }

        .modal-content {
            background-color: white;
            margin: 15% auto;
            padding: 20px;
            border-radius: 10px;
            width: 90%;
            max-width: 500px;
        }

        .close {
            color: #aaa;
            float: right;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
        }

        .close:hover {
            color: black;
        }

        @media (max-width: 768px) {
            .container {
                padding: 15px;
            }

            .actions {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <a href="dashboard.php" class="back-link">← Back to Dashboard</a>

        <div class="page-header">
            <h1>Manage Assessments</h1>
            <p>Create and manage assessments for your courses</p>
            <div style="display:flex; gap:8px; flex-wrap:wrap; justify-content:flex-end; margin-top:10px;">
                <a href="manage-grades.php?section_id=<?php echo (int)$sectionId; ?>" class="btn btn-primary">🧮 Manage Grades</a>
            </div>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if ($msg): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($msg); ?></div>
        <?php endif; ?>

        <?php if (!$sectionId): ?>
            <div class="card">
                <h2 style="color: #6a0dad; margin-bottom: 20px;">Select Course</h2>
                <form method="GET">
                    <div class="form-group">
                        <label for="section_id">Course:</label>
                        <select name="section_id" id="section_id" class="form-control" onchange="this.form.submit()">
                            <option value="">Select a section...</option>
                            <?php foreach ($sectionsList as $sec): ?>
                                <option value="<?php echo (int)$sec['section_id']; ?>" <?php echo $sectionId == (int)$sec['section_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($sec['course_code'] . ' - ' . $sec['section_code'] . ' — ' . $sec['course_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </form>
            </div>
        <?php else: ?>
            <div class="card" style="display:flex;justify-content:space-between;align-items:center;gap:12px;">
                <div>
                    <h2 style="color:#6a0dad;margin:0 0 6px;">Assessments</h2>
                    <?php if ($selectedSection): ?>
                        <?php $yl=(int)($selectedSection['year_level']??0); $ylLbl=$yl===1?'1st Year':($yl===2?'2nd Year':($yl===3?'3rd Year':($yl>0?$yl.'th Year':'Year'))); $semI=(int)($selectedSection['semester']??0); $semLbl=$semI===1?'1st Semester':($semI===2?'2nd Semester':($semI===3?'Summer':'')); ?>
                        <div style="color:#555;">
                            <strong><?php echo htmlspecialchars($selectedSection['course_code'].' — '.$selectedSection['course_name']); ?></strong>
                            • Section <?php echo htmlspecialchars($selectedSection['section_code']); ?>
                            • <?php echo htmlspecialchars($ylLbl); ?>
                            <?php if ($semLbl!==''): ?>• <?php echo htmlspecialchars($semLbl); ?><?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
                <a href="?" class="btn btn-secondary">Change</a>
            </div>

            <?php // One-click duplicate assessments UI ?>
            <div class="card" style="margin-top:10px;">
                <h3 style="color:#6a0dad; margin:0 0 10px;">Copy Assessments from Another Section</h3>
                <?php
                // Build source sections list (same course, taught by instructor), excluding current
                $srcStmt = $pdo->prepare("SELECT s.id, s.section_code FROM instructor_sections ins INNER JOIN sections s ON s.id=ins.section_id WHERE ins.instructor_id=? AND s.course_id=? AND s.id<>? ORDER BY s.section_code");
                $srcStmt->execute([$instructorId, (int)$courseId, (int)$sectionId]);
                $srcSections = $srcStmt->fetchAll(PDO::FETCH_ASSOC);
                ?>
                <form method="POST" onsubmit="return confirm('Copy all assessment criteria and items from the selected section into this section? Existing criteria with the same slug and period will be kept.');" style="display:flex; gap:10px; align-items:end; flex-wrap:wrap;">
                    <input type="hidden" name="action" value="copy_from_section">
                    <div>
                        <label>From Section</label>
                        <select name="source_section_id" required>
                            <option value="">Select source section...</option>
                            <?php foreach ($srcSections as $ss): ?>
                                <option value="<?php echo (int)$ss['id']; ?>">Section <?php echo htmlspecialchars($ss['section_code']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <button class="btn btn-primary">Copy from Section</button>
                    </div>
                </form>
            </div>
        <?php endif; ?>

        <?php if ($sectionId > 0): ?>
            <div class="card">
                <h2 style="color: #6a0dad; margin-bottom: 20px;">Add New Assessment Criteria</h2>
                <form method="POST">
                    <input type="hidden" name="action" value="add_criteria">
                    <input type="hidden" name="course_id" value="<?php echo $courseId; ?>">
                    <input type="hidden" name="section_id" value="<?php echo (int)$sectionId; ?>">

                    <div class="form-group">
                        <label for="name">Criteria Name:</label>
                        <input type="text" id="name" name="name" class="form-control" placeholder="e.g., Written Exam, Practical Exam" required>
                    </div>

                    <div class="form-group">
                        <label for="period">Period:</label>
                        <select id="period" name="period" class="form-control" required>
                            <option value="">Select period...</option>
                            <option value="prelim">Preliminary</option>
                            <option value="midterm">Midterm</option>
                            <option value="finals">Finals</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="percentage">Percentage (1-100):</label>
                        <input type="number" id="percentage" name="percentage" class="form-control" min="1" max="100" required>
                    </div>

                    <button type="submit" class="btn btn-primary">Add Criteria</button>
                </form>
            </div>

            <div class="card">
                <h2 style="color: #6a0dad; margin-bottom: 20px;">Assessment Criteria and Items</h2>

                <?php
                // Calculate totals by period
                $periodTotals = ['prelim' => 0, 'midterm' => 0, 'finals' => 0];
                $totalPercentage = 0;
                foreach ($criteria as $criterion) {
                    $totalPercentage += $criterion['percentage'];
                    $periodTotals[$criterion['period']] += $criterion['percentage'];
                }
                $periodLimits = ['prelim' => 30, 'midterm' => 30, 'finals' => 40];
                // Base weights for each period used to compute overall contribution
                $periodWeights = [
                    'prelim' => 30,
                    'midterm' => 30,
                    // normalize 'finals' -> use key 'final' while supporting existing 'finals' value from DB
                    'final' => 40,
                    'finals' => 40
                ];
                ?>

                <div style="margin-bottom: 20px; padding: 15px; background: #f8f9fa; border-radius: 6px;">
                    <div style="margin-bottom: 10px;">
                        <strong>Period Breakdown:</strong>
                    </div>
                    <div style="display: flex; gap: 20px; flex-wrap: wrap;">
                        <?php $periodWeightLabels = ['prelim'=>30,'midterm'=>30,'finals'=>40]; ?>
                        <?php foreach ($periodTotals as $period => $total): ?>
                            <?php
                                $labelWeight = $periodWeightLabels[$period] ?? 0;
                                $within = max(0, min(100, $total));
                            ?>
                            <div style="flex: 1; min-width: 150px;">
                                <strong><?php echo ucfirst($period); ?>: <?php echo number_format($within, 0); ?>/100% <span style="color:#6c757d;">(<?php echo number_format($labelWeight, 0); ?>% of total grade)</span></strong>
                                <?php if ($within > 100): ?>
                                    <span class="remaining-text" style="color:#dc3545;">Exceeds limit</span>
                                <?php elseif ($within < 100): ?>
                                    <?php $remaining = number_format(100 - $within, 0); ?>
                                    <span class="remaining-text"><?php echo $remaining; ?>% remaining</span>
                                <?php else: ?>
                                    <span class="remaining-text" style="color:#28a745;">Perfect</span>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <hr style="margin: 10px 0;">
                    <div>
                        <strong>Total Assessment Percentage: <?php echo number_format($totalPercentage, 0); ?>% <span style="color:#6c757d;">(sum across periods)</span></strong>
                    </div>
                </div>

                <?php if (count($criteria) > 0): ?>
                    <div class="table-wrapper">
                        <table class="assessments-table">
                            <thead>
                                <tr>
                                    <th>Type</th>
                                    <th>Name</th>
                                    <th>Period</th>
                                    <th>Percentage/Score</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $lastPeriod = null; ?>
                                <?php foreach ($criteria as $criterion): ?>
                                    <?php
                                        $p = strtolower($criterion['period'] ?? '');
                                        if ($p !== $lastPeriod) {
                                            echo '<tr><td colspan="5" class="period-banner">' . htmlspecialchars(strtoupper($criterion['period'])) . '</td></tr>';
                                            $lastPeriod = $p;
                                        }
                                    ?>
                                    <!-- Criteria Row -->
                                    <tr style="background: #f8f9ff; border-top: 2px solid #6a0dad;">
                                        <td style="font-weight: 600; color: #6a0dad;">Criteria</td>
                                        <td style="font-weight: 600; color: #6a0dad;"><?php echo htmlspecialchars($criterion['name']); ?></td>
                                        <td><?php echo htmlspecialchars(ucfirst($criterion['period'])); ?></td>
                                        <td>
                                            <?php echo htmlspecialchars($criterion['percentage']); ?>%
                                            <small class="text-muted">(of this period)</small>
                                        </td>
                                        <td>
                                            <div class="actions">
                                                <button class="btn btn-secondary btn-sm" onclick="openEditCriteriaModal(<?php echo $criterion['id']; ?>, '<?php echo htmlspecialchars($criterion['name']); ?>', '<?php echo htmlspecialchars($criterion['period']); ?>', <?php echo $criterion['percentage']; ?>)">Edit</button>
                                                <form method="POST" style="display: inline;">
                                                    <input type="hidden" name="action" value="delete_criteria">
                                                    <input type="hidden" name="id" value="<?php echo $criterion['id']; ?>">
                                                    <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('Are you sure you want to delete this criteria and all its items?')">Delete</button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>

                                    <!-- Add Item Row -->
                                    <tr style="background: #fafafa;">
                                        <td colspan="5" style="padding: 10px;">
                                            <form method="POST" style="display: flex; gap: 10px; align-items: center;">
                                                <input type="hidden" name="action" value="add_item">
                                                <input type="hidden" name="criteria_id" value="<?php echo $criterion['id']; ?>">
                                                <span style="font-weight: 500; color: #666;">Add Item:</span>
                                                <input type="text" name="item_name" placeholder="Item name..." class="form-control" style="flex: 1; margin: 0;" required>
                                                <input type="number" name="total_score" placeholder="Total Score" class="form-control" style="width: 120px; margin: 0;" min="0.01" step="0.01" required>
                                                <button type="submit" class="btn btn-primary btn-sm">Add Item</button>
                                            </form>
                                        </td>
                                    </tr>

                                    <!-- Items Rows -->
                                    <?php if (count($criterion['items']) > 0): ?>
                                        <?php foreach ($criterion['items'] as $item): ?>
                                            <tr style="background: #fff;">
                                                <td style="padding-left: 30px;">└─ Item</td>
                                                <td style="padding-left: 30px;"><?php echo htmlspecialchars($item['name']); ?></td>
                                                <td>-</td>
                                                <td><?php echo htmlspecialchars($item['total_score']); ?> pts</td>
                                                <td>
                                                    <div class="actions">
                                                        <button class="btn btn-secondary btn-sm" onclick="openEditItemModal(<?php echo $item['id']; ?>, '<?php echo htmlspecialchars($item['name']); ?>', <?php echo $item['total_score']; ?>)">Edit</button>
                                                        <form method="POST" style="display: inline;">
                                                            <input type="hidden" name="action" value="delete_item">
                                                            <input type="hidden" name="id" value="<?php echo $item['id']; ?>">
                                                            <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('Are you sure you want to delete this item?')">Delete</button>
                                                        </form>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr style="background: #fff;">
                                            <td colspan="5" style="padding-left: 30px; color: #666; font-style: italic;">No items added yet for this criteria.</td>
                                        </tr>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p>No assessment criteria created yet for this course.</p>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Edit Criteria Modal -->
    <div id="editCriteriaModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeEditCriteriaModal()">&times;</span>
            <h2>Edit Assessment Criteria</h2>
            <form method="POST">
                <input type="hidden" name="action" value="edit_criteria">
                <input type="hidden" name="id" id="edit_criteria_id">

                <div class="form-group">
                    <label for="edit_criteria_name">Criteria Name:</label>
                    <input type="text" id="edit_criteria_name" name="name" class="form-control" required>
                </div>

                <div class="form-group">
                    <label for="edit_criteria_period">Period:</label>
                    <select id="edit_criteria_period" name="period" class="form-control" required>
                        <option value="prelim">Preliminary</option>
                        <option value="midterm">Midterm</option>
                        <option value="finals">Finals</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="edit_criteria_percentage">Percentage (1-100):</label>
                    <input type="number" id="edit_criteria_percentage" name="percentage" class="form-control" min="1" max="100" required>
                </div>

                <button type="submit" class="btn btn-primary">Update Criteria</button>
            </form>
        </div>
    </div>

    <!-- Edit Item Modal -->
    <div id="editItemModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeEditItemModal()">&times;</span>
            <h2>Edit Assessment Item</h2>
            <form method="POST">
                <input type="hidden" name="action" value="edit_item">
                <input type="hidden" name="id" id="edit_item_id">

                <div class="form-group">
                    <label for="edit_item_name">Item Name:</label>
                    <input type="text" id="edit_item_name" name="item_name" class="form-control" required>
                </div>

                <div class="form-group">
                    <label for="edit_item_total_score">Total Score:</label>
                    <input type="number" id="edit_item_total_score" name="total_score" class="form-control" min="0.01" step="0.01" required>
                </div>

                <button type="submit" class="btn btn-primary">Update Item</button>
            </form>
        </div>
    </div>

    <script>
        // Prevent double-submit for add forms: disable submit buttons on click
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('form').forEach(function(form) {
                form.addEventListener('submit', function(e) {
                    const btn = form.querySelector('button[type="submit"]');
                    if (btn) {
                        btn.disabled = true;
                        btn.dataset.origText = btn.textContent;
                        btn.textContent = 'Processing...';
                    }
                });
            });
        });
        function openEditCriteriaModal(id, name, period, percentage) {
            document.getElementById('edit_criteria_id').value = id;
            document.getElementById('edit_criteria_name').value = name;
            document.getElementById('edit_criteria_period').value = period;
            document.getElementById('edit_criteria_percentage').value = percentage;
            document.getElementById('editCriteriaModal').style.display = 'block';
        }

        function closeEditCriteriaModal() {
            document.getElementById('editCriteriaModal').style.display = 'none';
        }

        function openEditItemModal(id, name, totalScore) {
            document.getElementById('edit_item_id').value = id;
            document.getElementById('edit_item_name').value = name;
            document.getElementById('edit_item_total_score').value = totalScore;
            document.getElementById('editItemModal').style.display = 'block';
        }

        function closeEditItemModal() {
            document.getElementById('editItemModal').style.display = 'none';
        }

        // Close modals when clicking outside
        window.onclick = function(event) {
            var criteriaModal = document.getElementById('editCriteriaModal');
            var itemModal = document.getElementById('editItemModal');
            if (event.target == criteriaModal) {
                criteriaModal.style.display = 'none';
            }
            if (event.target == itemModal) {
                itemModal.style.display = 'none';
            }
        }
    </script>
</body>
</html>
