<?php
/**
 * Instructor - Manage Assessment Criteria and Items
 * Add, edit, and delete assessment criteria and their items for courses they teach
 */

require_once '../config.php';
requireRole('instructor');

$instructorId = $_SESSION['user_id'];
$error = '';
$msg = '';

// Get courses taught by this instructor
$courses = $pdo->prepare("
    SELECT c.id, c.course_code, c.course_name, c.year_level, c.semester, p.name as program_name
    FROM courses c
    LEFT JOIN programs p ON c.program_id = p.id
    WHERE c.instructor_id = ?
    ORDER BY c.course_code
");
$courses->execute([$instructorId]);
$courses = $courses->fetchAll(PDO::FETCH_ASSOC);

// Get selected course
$courseId = intval($_GET['course_id'] ?? ($courses[0]['id'] ?? 0));

// Handle add criteria
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_criteria') {
    $course_id = intval($_POST['course_id'] ?? 0);
    $name = sanitize($_POST['name'] ?? '');
    $period = sanitize($_POST['period'] ?? '');
    $percentage = floatval($_POST['percentage'] ?? 0);

    // Verify instructor teaches this course
    $courseCheck = $pdo->prepare("SELECT id FROM courses WHERE id = ? AND instructor_id = ?");
    $courseCheck->execute([$course_id, $instructorId]);
    if (!$courseCheck->fetch()) {
        $error = 'You do not have permission to manage assessments for this course.';
    } elseif (empty($name) || empty($period) || $percentage <= 0 || $percentage > 100) {
        $error = 'All fields are required and percentage must be between 1-100.';
    } else {
        // Define period limits
        $periodLimits = [
            'prelim' => 30,
            'midterm' => 30,
            'finals' => 40
        ];

        // Check total percentage for the specific period
        $periodCheck = $pdo->prepare("SELECT SUM(percentage) as total FROM assessment_criteria WHERE course_id = ? AND period = ?");
        $periodCheck->execute([$course_id, $period]);
        $currentPeriodTotal = floatval($periodCheck->fetch()['total'] ?? 0);

        if (!isset($periodLimits[$period])) {
            $error = 'Invalid period selected.';
        } elseif ($currentPeriodTotal + $percentage > $periodLimits[$period]) {
            $error = 'Total criteria percentage for ' . ucfirst($period) . ' cannot exceed ' . $periodLimits[$period] . '%. Current total: ' . $currentPeriodTotal . '%.';
        } else {
            try {
                $stmt = $pdo->prepare('INSERT INTO assessment_criteria (course_id, name, period, percentage) VALUES (?, ?, ?, ?)');
                $stmt->execute([$course_id, $name, $period, $percentage]);
                $msg = 'Assessment criteria added successfully.';
            } catch (Exception $e) {
                $error = 'Failed to add criteria: ' . $e->getMessage();
            }
        }
    }
}

// Handle add item
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_item') {
    $criteria_id = intval($_POST['criteria_id'] ?? 0);
    $name = sanitize($_POST['item_name'] ?? '');
    $total_score = floatval($_POST['total_score'] ?? 0);

    // Verify criteria belongs to instructor's course
    $criteriaCheck = $pdo->prepare("
        SELECT ac.id FROM assessment_criteria ac
        INNER JOIN courses c ON ac.course_id = c.id
        WHERE ac.id = ? AND c.instructor_id = ?
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
            $stmt = $pdo->prepare('INSERT INTO assessment_items (criteria_id, name, total_score) VALUES (?, ?, ?)');
            $stmt->execute([$criteria_id, $name, $total_score]);
            $msg = 'Assessment item added successfully.';
        } catch (Exception $e) {
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

    // Verify criteria belongs to instructor's course
    $criteriaCheck = $pdo->prepare("
        SELECT ac.id, ac.course_id, ac.period FROM assessment_criteria ac
        INNER JOIN courses c ON ac.course_id = c.id
        WHERE ac.id = ? AND c.instructor_id = ?
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
            'prelim' => 30,
            'midterm' => 30,
            'finals' => 40
        ];

        // Check total percentage for the specific period excluding current criteria
        $periodCheck = $pdo->prepare("SELECT SUM(percentage) as total FROM assessment_criteria WHERE course_id = ? AND period = ? AND id != ?");
        $periodCheck->execute([$criteriaData['course_id'], $period, $id]);
        $currentPeriodTotal = floatval($periodCheck->fetch()['total'] ?? 0);

        if (!isset($periodLimits[$period])) {
            $error = 'Invalid period selected.';
        } elseif ($currentPeriodTotal + $percentage > $periodLimits[$period]) {
            $error = 'Total criteria percentage for ' . ucfirst($period) . ' cannot exceed ' . $periodLimits[$period] . '%. Current total (excluding this criteria): ' . $currentPeriodTotal . '%.';
        } else {
            try {
                $stmt = $pdo->prepare('UPDATE assessment_criteria SET name = ?, period = ?, percentage = ? WHERE id = ?');
                $stmt->execute([$name, $period, $percentage, $id]);
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

    // Verify item belongs to instructor's course
    $itemCheck = $pdo->prepare("
        SELECT ai.id FROM assessment_items ai
        INNER JOIN assessment_criteria ac ON ai.criteria_id = ac.id
        INNER JOIN courses c ON ac.course_id = c.id
        WHERE ai.id = ? AND c.instructor_id = ?
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

    // Verify criteria belongs to instructor's course
    $criteriaCheck = $pdo->prepare("
        SELECT ac.id FROM assessment_criteria ac
        INNER JOIN courses c ON ac.course_id = c.id
        WHERE ac.id = ? AND c.instructor_id = ?
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

    // Verify item belongs to instructor's course
    $itemCheck = $pdo->prepare("
        SELECT ai.id FROM assessment_items ai
        INNER JOIN assessment_criteria ac ON ai.criteria_id = ac.id
        INNER JOIN courses c ON ac.course_id = c.id
        WHERE ai.id = ? AND c.instructor_id = ?
    ");
    $itemCheck->execute([$id, $instructorId]);
    if (!$itemCheck->fetch()) {
        $error = 'You do not have permission to delete this item.';
    } else {
        try {
            $stmt = $pdo->prepare('DELETE FROM assessment_items WHERE id = ?');
            $stmt->execute([$id]);
            $msg = 'Assessment item deleted successfully.';
        } catch (Exception $e) {
            $error = 'Failed to delete item: ' . $e->getMessage();
        }
    }
}

// Get criteria and items for selected course
$criteria = [];
if ($courseId > 0) {
    $stmt = $pdo->prepare("
        SELECT ac.*, COUNT(ai.id) as item_count
        FROM assessment_criteria ac
        LEFT JOIN assessment_items ai ON ac.id = ai.criteria_id
        WHERE ac.course_id = ?
        GROUP BY ac.id
        ORDER BY ac.period, ac.name
    ");
    $stmt->execute([$courseId]);
    $criteria = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get items for each criteria
    foreach ($criteria as &$criterion) {
        $itemStmt = $pdo->prepare("SELECT * FROM assessment_items WHERE criteria_id = ? ORDER BY name");
        $itemStmt->execute([$criterion['id']]);
        $criterion['items'] = $itemStmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Assessments - TVET System</title>
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
        </div>

        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if ($msg): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($msg); ?></div>
        <?php endif; ?>

        <div class="card">
            <h2 style="color: #6a0dad; margin-bottom: 20px;">Select Course</h2>
            <form method="GET">
                <div class="form-group">
                    <label for="course_id">Course:</label>
                    <select name="course_id" id="course_id" class="form-control" onchange="this.form.submit()">
                        <option value="">Select a course...</option>
                        <?php foreach ($courses as $course): ?>
                            <option value="<?php echo $course['id']; ?>" <?php echo $courseId == $course['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($course['course_code'] . ' - ' . $course['course_name'] . ' (' . $course['program_name'] . ' - Year ' . $course['year_level'] . ', Sem ' . $course['semester'] . ')'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </form>
        </div>

        <?php if ($courseId > 0): ?>
            <div class="card">
                <h2 style="color: #6a0dad; margin-bottom: 20px;">Add New Assessment Criteria</h2>
                <form method="POST">
                    <input type="hidden" name="action" value="add_criteria">
                    <input type="hidden" name="course_id" value="<?php echo $courseId; ?>">

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
                ?>

                <div style="margin-bottom: 20px; padding: 15px; background: #f8f9fa; border-radius: 6px;">
                    <div style="margin-bottom: 10px;">
                        <strong>Period Breakdown:</strong>
                    </div>
                    <div style="display: flex; gap: 20px; flex-wrap: wrap;">
                        <?php foreach ($periodTotals as $period => $total): ?>
                            <div style="flex: 1; min-width: 150px;">
                                <strong><?php echo ucfirst($period); ?>: <?php echo $total; ?>/<?php echo $periodLimits[$period]; ?>%</strong>
                                <?php if ($total > $periodLimits[$period]): ?>
                                    <span style="color: #dc3545; margin-left: 5px;">(Exceeds limit)</span>
                                <?php elseif ($total < $periodLimits[$period]): ?>
                                    <span style="color: #ffc107; margin-left: 5px;">(<?php echo $periodLimits[$period] - $total; ?>% remaining)</span>
                                <?php else: ?>
                                    <span style="color: #28a745; margin-left: 5px;">(Perfect)</span>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <hr style="margin: 10px 0;">
                    <div>
                        <strong>Total Assessment Percentage: <?php echo $totalPercentage; ?>%</strong>
                        <?php if ($totalPercentage > 100): ?>
                            <span style="color: #dc3545; margin-left: 10px;">(Exceeds 100% - please adjust percentages)</span>
                        <?php elseif ($totalPercentage < 100): ?>
                            <span style="color: #ffc107; margin-left: 10px;">(Below 100% - <?php echo 100 - $totalPercentage; ?>% remaining)</span>
                        <?php else: ?>
                            <span style="color: #28a745; margin-left: 10px;">(Perfect - exactly 100%)</span>
                        <?php endif; ?>
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
                                <?php foreach ($criteria as $criterion): ?>
                                    <!-- Criteria Row -->
                                    <tr style="background: #f8f9ff; border-top: 2px solid #6a0dad;">
                                        <td style="font-weight: 600; color: #6a0dad;">Criteria</td>
                                        <td style="font-weight: 600; color: #6a0dad;"><?php echo htmlspecialchars($criterion['name']); ?></td>
                                        <td><?php echo htmlspecialchars(ucfirst($criterion['period'])); ?></td>
                                        <td><?php echo htmlspecialchars($criterion['percentage']); ?>%</td>
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
