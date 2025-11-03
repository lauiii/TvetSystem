<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../include/functions.php';
requireRole('admin');

// Default config
$YEARS = [1,2,3];
$PER_STUDENTS = 20; // per year per program
$GRADE_MIN = 72.0; $GRADE_MAX = 96.0;

$activeSY = $pdo->query("SELECT id, year, semester FROM school_years WHERE status='active' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$activeSY) { die('No active school year found.'); }
$syId = (int)$activeSY['id'];
$sem  = (int)($activeSY['semester'] ?? 1);

$do = ($_SERVER['REQUEST_METHOD'] === 'POST') ? ($_POST['action'] ?? '') : '';

function rand_grade($min,$max){ return round($min + lcg_value()*($max-$min), 2); }
function random_name(){
  static $fn = ['John','Jane','Alex','Sam','Chris','Kim','Pat','Jordan','Taylor','Morgan','Jamie','Casey','Riley','Drew','Harper'];
  static $ln = ['Garcia','Smith','Johnson','Lee','Lopez','Cruz','Torres','Reyes','Santos','Flores','Gonzales','Perez','Ramos','Rivera','Morales'];
  return [$fn[array_rand($fn)], $ln[array_rand($ln)]];
}

if ($do === 'seed') {
  $perStudents = max(1, min(200, (int)($_POST['per_students'] ?? $PER_STUDENTS)));
  $gradeMin = max(0, min(100, (float)($_POST['grade_min'] ?? $GRADE_MIN)));
  $gradeMax = max($gradeMin, min(100, (float)($_POST['grade_max'] ?? $GRADE_MAX)));
  $years = isset($_POST['years']) && is_array($_POST['years']) ? array_values(array_intersect(array_map('intval', $_POST['years']), [1,2,3])) : $YEARS;

  $pdo->beginTransaction();
  try {
    // Programs
    $programs = $pdo->query("SELECT id, name FROM programs ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);

    // Ensure base assessment criteria/items per course (by program/year/semester)
    $courses = $pdo->prepare("SELECT id, program_id, year_level, semester FROM courses WHERE program_id=? AND year_level=? AND (semester IS NULL OR semester = ?)");

    // Prepared inserts (build dynamically to include email/username if present)
    $ucols = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
    $hasEmail = in_array('email', $ucols);
    $hasUsername = in_array('username', $ucols);
    $userFields = ['student_id','first_name','last_name','role','program_id','year_level','status'];
    if ($hasEmail) $userFields[] = 'email';
    if ($hasUsername) $userFields[] = 'username';
    $placeholders = implode(',', array_fill(0, count($userFields), '?'));
    $insUserSql = "INSERT INTO users (".implode(',', $userFields).") VALUES ($placeholders)";
    $insUser = $pdo->prepare($insUserSql);
    $findUserByStud = $pdo->prepare("SELECT id FROM users WHERE student_id=? LIMIT 1");
    $insEnroll = $pdo->prepare("INSERT INTO enrollments (student_id, course_id, school_year_id, status) VALUES (?,?,?, 'enrolled')");

    $insCrit = $pdo->prepare("INSERT INTO assessment_criteria (course_id, name, period, percentage) VALUES (?,?,?,?)");
    $insItem = $pdo->prepare("INSERT INTO assessment_items (criteria_id, name, total_score) VALUES (?,?,?)");

    $findCrit = $pdo->prepare("SELECT id FROM assessment_criteria WHERE course_id=? AND period=? AND name=? LIMIT 1");
    $findItemsByCrit = $pdo->prepare("SELECT id FROM assessment_items WHERE criteria_id=?");

    $insGrade = $pdo->prepare("INSERT INTO grades (enrollment_id, assessment_id, grade, status, submitted_at) VALUES (?,?,?,?, NOW()) ON DUPLICATE KEY UPDATE grade=VALUES(grade), status=VALUES(status), submitted_at=NOW()");

    $createdUsers = 0; $createdEnroll = 0; $createdItems = 0; $createdGrades = 0;

    foreach ($programs as $prog) {
      foreach ($years as $yl) {
        // Create students (ensure unique student_id)
        for ($i=0; $i<$perStudents; $i++) {
          // Generate unique student_id (YY + program + rand)
          $sid = 'S' . date('y') . str_pad((string)$prog['id'], 2, '0', STR_PAD_LEFT) . str_pad((string)random_int(10000,99999), 5, '0', STR_PAD_LEFT);
          $findUserByStud->execute([$sid]);
          if ($findUserByStud->fetchColumn()) { $i--; continue; }
          [$first,$last] = random_name();
          $vals = [$sid, $first, $last, 'student', (int)$prog['id'], $yl, 'active'];
          if ($hasEmail) { $vals[] = strtolower($sid).'@school.local'; }
          if ($hasUsername) { $vals[] = strtolower($sid); }
          $insUser->execute($vals);
          $stuId = (int)$pdo->lastInsertId();
          $createdUsers++;

          // Enroll to matching courses for program/year/active semester
          $courses->execute([(int)$prog['id'], $yl, $sem]);
          $courseRows = $courses->fetchAll(PDO::FETCH_ASSOC);
          foreach ($courseRows as $crs) {
            $insEnroll->execute([$stuId, (int)$crs['id'], $syId]);
            $enrollId = (int)$pdo->lastInsertId();
            $createdEnroll++;

            // Ensure criteria/items exist for this course
            $periods = ['prelim'=>['Quizzes'=>40,'Performance'=>30,'Exam'=>30], 'midterm'=>['Quizzes'=>40,'Performance'=>30,'Exam'=>30], 'finals'=>['Quizzes'=>40,'Performance'=>30,'Exam'=>30]];
            $itemsTpl = [ 'Quizzes'=>[['Quiz 1',20],['Quiz 2',20]], 'Performance'=>[['Task 1',25],['Task 2',25]], 'Exam'=>[['Exam',50]] ];
            $criteriaIds = [];
            foreach ($periods as $per=>$critDef) {
              foreach ($critDef as $cname=>$perc) {
                $findCrit->execute([(int)$crs['id'], $per, $cname]);
                $cid = (int)$findCrit->fetchColumn();
                if (!$cid) { $insCrit->execute([(int)$crs['id'], $cname, $per, (float)$perc]); $cid = (int)$pdo->lastInsertId(); }
                $criteriaIds[$per][$cname] = $cid;
                // Ensure items
                $findItemsByCrit->execute([$cid]);
                $haveItems = $findItemsByCrit->fetchAll(PDO::FETCH_COLUMN);
                if (!$haveItems) {
                  foreach ($itemsTpl[$cname] as [$nm,$max]) { $insItem->execute([$cid, $nm, (float)$max]); $createdItems++; }
                }
              }
            }

            // Grades for all items of this course
            // Reload items for this course
            $ai = $pdo->prepare("SELECT ai.id, ai.total_score FROM assessment_items ai INNER JOIN assessment_criteria ac ON ai.criteria_id=ac.id WHERE ac.course_id=?");
            $ai->execute([(int)$crs['id']]);
            foreach ($ai->fetchAll(PDO::FETCH_ASSOC) as $it) {
              $score = rand_grade($gradeMin, $gradeMax) / 100.0 * (float)$it['total_score'];
              $insGrade->execute([$enrollId, (int)$it['id'], round($score,2), 'complete']);
              $createdGrades++;
            }
          }
        }
      }
    }

    $pdo->commit();
    echo '<!doctype html><meta charset="utf-8"><div style="font-family:system-ui;padding:16px;">';
    echo '<h3>Seeding complete</h3>';
    echo '<div>New students: '.(int)$createdUsers.'</div>';
    echo '<div>New enrollments: '.(int)$createdEnroll.'</div>';
    echo '<div>New assessment items (first-time only): '.(int)$createdItems.'</div>';
    echo '<div>Grades created: '.(int)$createdGrades.'</div>';
    echo '<p><a href="seed_data.php">Back</a></p>';
    echo '</div>';
    exit;
  } catch (Exception $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo '<pre style="white-space:pre-wrap;font-family:system-ui;">Error: '.htmlspecialchars($e->getMessage())."\n".htmlspecialchars($e->getTraceAsString()).'</pre>';
    exit;
  }
}

// GET - show simple form
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Seed Data</title>
  <link rel="stylesheet" href="../assets/css/admin-style.css">
</head>
<body>
  <div class="admin-layout">
    <?php $active='grades'; require __DIR__.'/inc/sidebar.php'; ?>
    <main class="main-content">
      <div class="container">
        <?php $pageTitle='Seed Data'; require __DIR__.'/inc/header.php'; ?>
        <div class="card">
          <h3>Generate Students and Grades</h3>
          <form method="POST">
            <input type="hidden" name="action" value="seed">
            <div style="display:flex;gap:16px;flex-wrap:wrap;align-items:end;">
              <div>
                <label>Students per Year per Program</label>
                <input type="number" name="per_students" value="<?php echo (int)$PER_STUDENTS; ?>" min="1" max="200">
              </div>
              <div>
                <label>Years</label><br>
                <?php foreach ([1,2,3] as $yl): ?>
                  <label style="margin-right:8px;"><input type="checkbox" name="years[]" value="<?php echo $yl; ?>" checked> <?php echo $yl; ?></label>
                <?php endforeach; ?>
              </div>
              <div>
                <label>Grade range (%)</label><br>
                <input type="number" name="grade_min" value="<?php echo (float)$GRADE_MIN; ?>" min="0" max="100" step="0.1"> to 
                <input type="number" name="grade_max" value="<?php echo (float)$GRADE_MAX; ?>" min="0" max="100" step="0.1">
              </div>
              <div>
                <button type="submit" class="btn primary">Seed Now</button>
                <a class="btn" href="grades.php">Back to Grades</a>
              </div>
            </div>
            <p style="margin-top:10px;color:#6b7280;">This will add students, enroll them to program-year courses for the active school year (semester <?php echo (int)$sem; ?>), ensure assessments exist, and generate grades.</p>
          </form>
        </div>
      </div>
    </main>
  </div>
</body>
</html>
