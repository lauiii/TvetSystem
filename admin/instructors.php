<?php
/**
 * Admin - Manage Instructors
 * Add, edit, delete, and list instructors
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../include/functions.php';
requireRole('admin');

$error = '';
$msg = '';

// Handle add instructor
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add') {
    $first_name = sanitize($_POST['first_name'] ?? '');
    $last_name = sanitize($_POST['last_name'] ?? '');
    $email = sanitize($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($first_name) || empty($last_name) || empty($email) || empty($password)) {
        $error = 'All fields are required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Invalid email format.';
    } else {
        try {
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare('INSERT INTO users (first_name, last_name, email, password, role) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([$first_name, $last_name, $email, $hashedPassword, 'instructor']);
            $msg = 'Instructor added successfully.';
        } catch (Exception $e) {
            $error = 'Failed to add instructor: ' . $e->getMessage();
        }
    }
}

// Handle edit instructor
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit') {
    $id = (int)($_POST['id'] ?? 0);
    $first_name = sanitize($_POST['first_name'] ?? '');
    $last_name = sanitize($_POST['last_name'] ?? '');
    $email = sanitize($_POST['email'] ?? '');
    $status = sanitize($_POST['status'] ?? 'active');

    if ($id <= 0 || empty($first_name) || empty($last_name) || empty($email)) {
        $error = 'All fields are required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Invalid email format.';
    } else {
        try {
            $stmt = $pdo->prepare('UPDATE users SET first_name = ?, last_name = ?, email = ?, status = ? WHERE id = ? AND role = ?');
            $stmt->execute([$first_name, $last_name, $email, $status, $id, 'instructor']);
            $msg = 'Instructor updated successfully.';
        } catch (Exception $e) {
            $error = 'Failed to update instructor: ' . $e->getMessage();
        }
    }
}

// Handle delete instructor
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        try {
            $stmt = $pdo->prepare('DELETE FROM users WHERE id = ? AND role = ?');
            $stmt->execute([$id, 'instructor']);
            $msg = 'Instructor deleted.';
        } catch (Exception $e) {
            $error = 'Failed to delete instructor: ' . $e->getMessage();
        }
    }
}

// Fetch instructors
$instructors = [];
try {
    $stmt = $pdo->query("SELECT id, first_name, last_name, email, status, created_at FROM users WHERE role = 'instructor' ORDER BY last_name, first_name");
    $instructors = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $error = $error ?: 'Failed to load instructors: ' . $e->getMessage();
}

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Instructors - Admin</title>
    <link rel="stylesheet" href="../assets/css/admin-style.css">
</head>
<body>
    <div class="admin-layout">
        <?php $active = 'instructors'; require __DIR__ . '/inc/sidebar.php'; ?>

        <main class="main-content">
            <div class="container">
                <?php $pageTitle = 'Instructors'; require __DIR__ . '/inc/header.php'; ?>

                <div class="card add-instructor-card">
                    <?php if ($error): ?><div class="err"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
                    <?php if ($msg): ?><div class="ok"><?php echo htmlspecialchars($msg); ?></div><?php endif; ?>

                    <h3>Add Instructor</h3>
                    <form method="POST">
                        <input type="hidden" name="action" value="add">
                        <div class="form-row">
                            <label>First Name</label><br>
                            <input type="text" name="first_name" required>
                        </div>
                        <div class="form-row">
                            <label>Last Name</label><br>
                            <input type="text" name="last_name" required>
                        </div>
                        <div class="form-row">
                            <label>Email</label><br>
                            <input type="email" name="email" required>
                        </div>
                        <div class="form-row">
                            <label>Password</label><br>
                            <input type="password" name="password" required>
                        </div>
                        <button class="btn primary">Add Instructor</button>
                    </form>
                </div>

                <div style="height:20px"></div>
                <div class="card">
                    <h3>Existing Instructors</h3>
                    <?php if (count($instructors) === 0): ?>
                        <p>No instructors found.</p>
                    <?php else: ?>
                        <?php foreach ($instructors as $instructor): ?>
                            <div class="list-row">
                                <div>
                                    <strong><?php echo htmlspecialchars($instructor['first_name'] . ' ' . $instructor['last_name']); ?></strong>
                                    <div style="color:#666;font-size:13px"><?php echo htmlspecialchars($instructor['email']); ?> • Status: <?php echo htmlspecialchars($instructor['status']); ?></div>
                                </div>
                                <div>
                                    <button class="btn" onclick="editInstructor(<?php echo $instructor['id']; ?>, '<?php echo htmlspecialchars($instructor['first_name']); ?>', '<?php echo htmlspecialchars($instructor['last_name']); ?>', '<?php echo htmlspecialchars($instructor['email']); ?>', '<?php echo htmlspecialchars($instructor['status']); ?>')">Edit</button>
                                    <form method="POST" style="display:inline-block;margin-left:8px" onsubmit="return confirm('Delete instructor?')">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?php echo $instructor['id']; ?>">
                                        <button class="btn" style="background:#e74c3c">Delete</button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <!-- Edit Modal -->
    <div id="editModal" class="modal" style="display:none;">
        <div class="modal-content">
            <span class="close" onclick="closeModal()">&times;</span>
            <h3>Edit Instructor</h3>
            <form method="POST">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="id" id="editId">
                <div class="form-row">
                    <label>First Name</label><br>
                    <input type="text" name="first_name" id="editFirstName" required>
                </div>
                <div class="form-row">
                    <label>Last Name</label><br>
                    <input type="text" name="last_name" id="editLastName" required>
                </div>
                <div class="form-row">
                    <label>Email</label><br>
                    <input type="email" name="email" id="editEmail" required>
                </div>
                <div class="form-row">
                    <label>Status</label><br>
                    <select name="status" id="editStatus">
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>
                <button class="btn primary">Update Instructor</button>
            </form>
        </div>
    </div>

    <script>
        function editInstructor(id, firstName, lastName, email, status) {
            document.getElementById('editId').value = id;
            document.getElementById('editFirstName').value = firstName;
            document.getElementById('editLastName').value = lastName;
            document.getElementById('editEmail').value = email;
            document.getElementById('editStatus').value = status;
            document.getElementById('editModal').style.display = 'block';
        }

        function closeModal() {
            document.getElementById('editModal').style.display = 'none';
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            if (event.target == document.getElementById('editModal')) {
                closeModal();
            }
        }
    </script>

    <style>
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.4);
        }

        .modal-content {
            background-color: #fefefe;
            margin: 15% auto;
            padding: 20px;
            border: 1px solid #888;
            width: 80%;
            max-width: 500px;
            border-radius: 8px;
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

        .form-row {
            margin-bottom: 15px;
        }

        .form-row label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
        }

        .form-row input, .form-row select {
            width: 100%;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
    </style>
</body>
</html>
