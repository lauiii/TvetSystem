<?php
// CLI script to create or reset an admin user
// Usage: php scripts/set_admin.php email password

if (PHP_SAPI !== 'cli') {
    echo "This script must be run from the command line.\n";
    exit(1);
}

if ($argc < 3) {
    echo "Usage: php set_admin.php email password\n";
    exit(1);
}

$email = $argv[1];
$pass = $argv[2];

require_once __DIR__ . '/../config.php';

try {
    $hash = password_hash($pass, PASSWORD_BCRYPT);

    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if ($user) {
        $u = $pdo->prepare("UPDATE users SET password = ?, role = 'admin', status = 'active' WHERE id = ?");
        $u->execute([$hash, $user['id']]);
        echo "Updated existing user $email -> set as admin and password changed.\n";
    } else {
        // Determine users table columns so we insert only available columns
        $dbName = $pdo->query('SELECT DATABASE()')->fetchColumn();
        $colStmt = $pdo->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'users'");
        $colStmt->execute([$dbName]);
        $cols = $colStmt->fetchAll(PDO::FETCH_COLUMN);

        $insertCols = [];
        $placeholders = [];
        $values = [];

        // student_id if available
        if (in_array('student_id', $cols)) {
            $insertCols[] = 'student_id';
            $placeholders[] = '?';
            $values[] = 'ADMIN' . random_int(1000,9999);
        }

        // first_name / last_name or name
        if (in_array('first_name', $cols) && in_array('last_name', $cols)) {
            $insertCols[] = 'first_name'; $placeholders[] = '?'; $values[] = 'System';
            $insertCols[] = 'last_name'; $placeholders[] = '?'; $values[] = 'Administrator';
        } elseif (in_array('name', $cols)) {
            $insertCols[] = 'name'; $placeholders[] = '?'; $values[] = 'System Administrator';
        }

        // email, password
        if (in_array('email', $cols)) {
            $insertCols[] = 'email'; $placeholders[] = '?'; $values[] = $email;
        }
        if (in_array('password', $cols)) {
            $insertCols[] = 'password'; $placeholders[] = '?'; $values[] = $hash;
        }
        // role, status
        if (in_array('role', $cols)) {
            $insertCols[] = 'role'; $placeholders[] = '?'; $values[] = 'admin';
        }
        if (in_array('status', $cols)) {
            $insertCols[] = 'status'; $placeholders[] = '?'; $values[] = 'active';
        }

        if (count($insertCols) === 0) {
            throw new Exception('No suitable columns found in users table to create admin.');
        }

        $sql = 'INSERT INTO users (' . implode(',', $insertCols) . ') VALUES (' . implode(',', $placeholders) . ')';
        $ins = $pdo->prepare($sql);
        $ins->execute($values);
        echo "Created admin $email\n";
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}

?>
