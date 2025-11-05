<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../include/functions.php';
requireRole('admin');

$error = '';$msg='';

// Realtime status endpoint
if (($_GET['action'] ?? '') === 'sections_status') {
  header('Content-Type: application/json');
  try {
    // Instructor names best-effort
    $ucols = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
    $nameExpr = '';
    if (in_array('first_name', $ucols, true) && in_array('last_name', $ucols, true)) { $nameExpr = "CONCAT(u.first_name,' ',u.last_name)"; }
    elseif (in_array('name', $ucols, true)) { $nameExpr = "u.name"; }

    $names = [];
    if ($nameExpr !== '') {
      $q = $pdo->query("SELECT ins.section_id, GROUP_CONCAT($nameExpr ORDER BY u.id SEPARATOR ', ') AS names FROM instructor_sections ins INNER JOIN users u ON ins.instructor_id=u.id GROUP BY ins.section_id");
      foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) { $names[(int)$r['section_id']] = (string)($r['names'] ?? ''); }
    }

    $rows = $pdo->query("SELECT s.id, (SELECT COUNT(*) FROM enrollments e WHERE e.section_id=s.id) AS enrolled_count FROM sections s")->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $r) {
      $sid = (int)$r['id'];
      $out[] = [
        'section_id' => $sid,
        'enrolled_count' => (int)($r['enrolled_count'] ?? 0),
        'instructors' => trim($names[$sid] ?? ''),
      ];
    }
    echo json_encode(['ok'=>true,'data'=>$out]);
  } catch (Exception $e) {
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
  }
  exit;
}

// Helpers
function has_enrollment_section(PDO $pdo): bool {
  try { $cols = $pdo->query("SHOW COLUMNS FROM enrollments")->fetchAll(PDO::FETCH_COLUMN); } catch (Exception $e) { return false; }
  return in_array('section_id',$cols,true);
}

// Action: Rebalance over-capacity courses for Active School Year
if ($_SERVER['REQUEST_METHOD']==='POST' && (($_POST['action']??'')==='rebalance_overcapacity')){
  try{
    $syRow=$pdo->query("SELECT id FROM school_years WHERE status='active' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $syId=(int)($syRow['id']??0);
    if(!$syId) throw new Exception('No active school year.');

    // Courses with enrollments in active SY
    $courseIds = $pdo->query("SELECT DISTINCT course_id FROM enrollments WHERE school_year_id = " . (int)$syId)->fetchAll(PDO::FETCH_COLUMN);
    $touched=0; $created=0; $moved=0; $scanned=count($courseIds);
    foreach ($courseIds as $cid) {
      $cid=(int)$cid; if($cid<=0) continue;
      $res = rebalance_sections_for_course($pdo, $cid, $syId, 30);
      $touched += 1;
      $created += (int)($res['created_sections']??0);
      $moved   += (int)($res['moved_enrollments']??0);
    }
    $msg = "Rebalanced $touched course(s) (scanned $scanned). Created $created new section(s), moved $moved enrollment(s).";
  }catch(Exception $e){ $error = $e->getMessage(); }
}

// Action: bulk for active SY + semester, all programs, years 1..3
if ($_SERVER['REQUEST_METHOD']==='POST' && (($_POST['action']??'')==='bulk_active_all')){
  try{
    // Active SY + semester
    $syRow=$pdo->query("SELECT id, semester FROM school_years WHERE status='active' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $syId=(int)($syRow['id']??0); $semester=(int)($syRow['semester']??0);
    if(!$syId) throw new Exception('No active school year.');
    if($semester<=0) throw new Exception('Active semester not set on active school year.');

    // Defaults
    $capacityDefault=30;
    $created=0; $touched=0; $insCount=0; $updCount=0; $skip=0; $noSec=0; $progTouched=0;
    // Program list
    $programsAll=$pdo->query('SELECT id, code FROM programs ORDER BY code')->fetchAll(PDO::FETCH_ASSOC);
    if(!$programsAll) throw new Exception('No programs found.');

    // Ensure schema niceties once
    try{$pdo->exec("ALTER TABLE sections MODIFY section_code VARCHAR(50)");}catch(Exception $e){}
    try{$pdo->exec("ALTER TABLE sections ADD UNIQUE KEY uniq_course_section (course_id, section_code)");}catch(Exception $e){}
    $hasSec=has_enrollment_section($pdo);

    foreach($programsAll as $pRow){ $program_id=(int)$pRow['id']; $progTouched++;
      foreach([1,2,3] as $year_level){
        // Count students
        $st=$pdo->prepare("SELECT COUNT(*) FROM users WHERE role='student' AND program_id=? AND year_level=? AND (status IS NULL OR status='active')");
        $st->execute([$program_id,$year_level]);
        $studentCount=(int)$st->fetchColumn();
        // Courses for this bucket
        $cst=$pdo->prepare('SELECT id FROM courses WHERE program_id=? AND year_level=? AND semester=?');
        $cst->execute([$program_id,$year_level,$semester]);
        $courses=$cst->fetchAll(PDO::FETCH_COLUMN);
        if(!$courses) continue;
        // Auto-create sections as needed
        $next=function(array $exist,int $i){$n=$i;$code='';while($n>=0){$code=chr(($n%26)+65).$code;$n=intdiv($n,26)-1;} $base=$i;$k=0;while(in_array($code,$exist,true)){$k++;$n=$base+$k;$code='';while($n>=0){$code=chr(($n%26)+65).$code;$n=intdiv($n,26)-1;}}return $code;};
        $pdo->beginTransaction();
        try{
          foreach($courses as $cid){
            $s=$pdo->prepare('SELECT section_code FROM sections WHERE course_id=? ORDER BY section_code');
            $s->execute([(int)$cid]);
            $exist=array_map(fn($r)=>(string)$r['section_code'],$s->fetchAll(PDO::FETCH_ASSOC));
            $current=count($exist); $need=(int)ceil(($studentCount>0?$studentCount:1)/$capacityDefault); $toCreate=max(0,$need-$current); if($toCreate<=0) continue; $touched++;
            for($i=0;$i<$toCreate;$i++){
              $code=$next($exist,$current+$i);
              $ins=$pdo->prepare("INSERT INTO sections (course_id, section_code, section_name, capacity, enrolled_count, status) VALUES (?,?,?,?,0,'active')");
              $ins->execute([(int)$cid,$code,$code,$capacityDefault]);
              $exist[]=$code; $created++;
            }
          }
          $pdo->commit();
        }catch(Exception $ie){ $pdo->rollBack(); throw $ie; }

        // Enroll all students to those courses and assign to sections
        // Preload students and courses
        $students=$pdo->prepare("SELECT id FROM users WHERE role='student' AND program_id=? AND year_level=? AND (status IS NULL OR status='active')");
        $students->execute([$program_id,$year_level]);
        $stuIds=$students->fetchAll(PDO::FETCH_COLUMN);
        if(!$stuIds) continue;
        $courseIds=$courses;
        // Available sections per course with occupancy for active SY
        $avail=[]; foreach($courseIds as $cid){ $q=$pdo->prepare('SELECT id, capacity FROM sections WHERE course_id=? AND status=\'active\' ORDER BY section_code'); $q->execute([(int)$cid]); $rows=$q->fetchAll(PDO::FETCH_ASSOC); $avail[(int)$cid]=[]; foreach($rows as $r){ $occ=$hasSec? (int)$pdo->query("SELECT COUNT(*) FROM enrollments WHERE course_id=".(int)$cid." AND section_id=".(int)$r['id']." AND school_year_id=$syId")->fetchColumn() : 0; $avail[(int)$cid][]= ['id'=>(int)$r['id'],'cap'=>(int)$r['capacity'],'occ'=>$occ]; } }
        $pick=function(array &$l){ usort($l,fn($a,$b)=>($b['cap']-$b['occ'])<=>($a['cap']-$a['occ']) ?: ($a['occ']<=>$b['occ'])); foreach($l as &$s){ if($s['occ']<$s['cap']){ $s['occ']++; return $s['id']; } } return null; };
        foreach($stuIds as $uid){ foreach($courseIds as $cid){ $ex=$pdo->prepare('SELECT id, section_id FROM enrollments WHERE student_id=? AND course_id=? AND school_year_id=? LIMIT 1'); $ex->execute([(int)$uid,(int)$cid,$syId]); $er=$ex->fetch(PDO::FETCH_ASSOC); $sec=null; if(!empty($avail[(int)$cid])) $sec=$pick($avail[(int)$cid]); if($sec===null){$noSec++; continue;} if($er){ if($hasSec && empty($er['section_id'])){ $u=$pdo->prepare('UPDATE enrollments SET section_id=? WHERE id=? AND section_id IS NULL'); $u->execute([(int)$sec,(int)$er['id']]); if($u->rowCount()>0){ $updCount++; $pdo->prepare('UPDATE sections SET enrolled_count = enrolled_count + 1 WHERE id=?')->execute([(int)$sec]); } else {$skip++;} } else {$skip++;} continue; } if($hasSec){ $i=$pdo->prepare('INSERT INTO enrollments (student_id,course_id,school_year_id,section_id,status) VALUES (?,?,?,?,\'enrolled\')'); $i->execute([(int)$uid,(int)$cid,$syId,(int)$sec]); } else { $i=$pdo->prepare('INSERT INTO enrollments (student_id,course_id,school_year_id,status) VALUES (?,?,?,\'enrolled\')'); $i->execute([(int)$uid,(int)$cid,$syId]); } if($i->rowCount()>0){ $insCount++; if($hasSec){ $pdo->prepare('UPDATE sections SET enrolled_count=enrolled_count+1 WHERE id=?')->execute([(int)$sec]); } } } }
      }
    }

    $msg = "Bulk (active SY/Sem) — Created $created section(s) across $touched course(s); Enrollments: inserted $insCount, updated $updCount, skipped $skip, no-section $noSec across $progTouched program(s).";
  }catch(Exception $e){ $error = $e->getMessage(); }
}

// Actions: auto-create sections
if ($_SERVER['REQUEST_METHOD']==='POST' && (($_POST['action']??'')==='auto_sections')){
  try{
    $program_id=(int)($_POST['program_id']??0);
    $year_level=(int)($_POST['year_level']??0);
    $semester=(int)($_POST['semester']??0);
    $capacity=max(1,(int)($_POST['capacity']??30));
    if(!$program_id||!$year_level||!$semester) throw new Exception('Program, year and semester required');
    $st=$pdo->prepare("SELECT COUNT(*) FROM users WHERE role='student' AND program_id=? AND year_level=? AND (status IS NULL OR status='active')");
    $st->execute([$program_id,$year_level]);
    $studentCount=(int)$st->fetchColumn();
    $cst=$pdo->prepare('SELECT id FROM courses WHERE program_id=? AND year_level=? AND semester=?');
    $cst->execute([$program_id,$year_level,$semester]);
    $courses=$cst->fetchAll(PDO::FETCH_COLUMN);
    if(!$courses) throw new Exception('No courses for selection');
    try{$pdo->exec("ALTER TABLE sections MODIFY section_code VARCHAR(50)");}catch(Exception $e){}
    try{$pdo->exec("ALTER TABLE sections ADD UNIQUE KEY uniq_course_section (course_id, section_code)");}catch(Exception $e){}
    $next=function(array $exist,int $i){$n=$i;$code='';while($n>=0){$code=chr(($n%26)+65).$code;$n=intdiv($n,26)-1;} $base=$i;$k=0;while(in_array($code,$exist,true)){$k++;$n=$base+$k;$code='';while($n>=0){$code=chr(($n%26)+65).$code;$n=intdiv($n,26)-1;}}return $code;};
    $created=0;$touched=0; $pdo->beginTransaction();
    foreach($courses as $cid){
      $s=$pdo->prepare('SELECT section_code FROM sections WHERE course_id=? ORDER BY section_code');
      $s->execute([(int)$cid]);
      $exist=array_map(fn($r)=>(string)$r['section_code'],$s->fetchAll(PDO::FETCH_ASSOC));
      $current=count($exist); $need=(int)ceil($studentCount/$capacity); $toCreate=max(0,$need-$current); if($toCreate<=0) continue; $touched++;
      for($i=0;$i<$toCreate;$i++){
        $code=$next($exist,$current+$i);
        $ins=$pdo->prepare("INSERT INTO sections (course_id, section_code, section_name, capacity, enrolled_count, status) VALUES (?,?,?,?,0,'active')");
        $ins->execute([(int)$cid,$code,$code,$capacity]);
        $exist[]=$code; $created++;
      }
    }
    $pdo->commit();
    $msg=$created?"Created $created section(s) across $touched course(s)":"No new sections needed";
  }catch(Exception $e){ if($pdo->inTransaction())$pdo->rollBack(); $error=$e->getMessage(); }
}

// Actions: reset sections
if ($_SERVER['REQUEST_METHOD']==='POST' && (($_POST['action']??'')==='reset_sections')){
  try{
    $program_id=(int)($_POST['program_id']??0); $year_level=(int)($_POST['year_level']??0); $semester=(int)($_POST['semester']??0);
    $sql='SELECT s.id FROM sections s INNER JOIN courses c ON s.course_id=c.id WHERE 1=1'; $p=[];
    if($program_id){$sql.=' AND c.program_id=?';$p[]=$program_id;} if($year_level){$sql.=' AND c.year_level=?';$p[]=$year_level;} if($semester){$sql.=' AND c.semester=?';$p[]=$semester;}
    $st=$pdo->prepare($sql); $st->execute($p); $ids=$st->fetchAll(PDO::FETCH_COLUMN);
    if(!$ids){$msg='No sections matched';}
    else{
      $in=implode(',',array_fill(0,count($ids),'?')); $pdo->beginTransaction();
      $pdo->prepare("DELETE FROM instructor_sections WHERE section_id IN ($in)")->execute($ids);
      try{$pdo->prepare("DELETE FROM enrollments WHERE section_id IN ($in)")->execute($ids);}catch(Exception $e){}
      $pdo->prepare("DELETE FROM sections WHERE id IN ($in)")->execute($ids);
      $pdo->commit(); $msg='Deleted '.count($ids).' section(s).';
    }
  }catch(Exception $e){ if($pdo->inTransaction())$pdo->rollBack(); $error=$e->getMessage(); }
}

// Actions: cleanup duplicate sections
if ($_SERVER['REQUEST_METHOD']==='POST' && (($_POST['action']??'')==='dedupe_sections')){
  try{
    try{$pdo->exec("ALTER TABLE sections MODIFY section_code VARCHAR(50)");}catch(Exception $e){}
    $dups=$pdo->query("SELECT course_id, UPPER(TRIM(section_code)) ncode, MIN(id) keep_id, GROUP_CONCAT(id) all_ids, COUNT(*) cnt FROM sections GROUP BY course_id, UPPER(TRIM(section_code)) HAVING cnt>1")->fetchAll(PDO::FETCH_ASSOC);
    $moved=0;$removed=0; foreach($dups as $d){ $keep=(int)$d['keep_id']; $ids=array_map('intval',array_filter(explode(',',(string)$d['all_ids']))); $toDel=array_values(array_diff($ids,[$keep])); if(!$toDel) continue; $in=implode(',',array_fill(0,count($toDel),'?')); try{$pdo->prepare("UPDATE enrollments SET section_id=? WHERE section_id IN ($in)")->execute(array_merge([$keep],$toDel));}catch(Exception $e){} $pdo->prepare("DELETE FROM instructor_sections WHERE section_id IN ($in)")->execute($toDel); $pdo->prepare("DELETE FROM sections WHERE id IN ($in)")->execute($toDel); $removed+=count($toDel);} 
    try{$pdo->exec("ALTER TABLE sections ADD UNIQUE KEY uniq_course_section (course_id, section_code)");}catch(Exception $e){}
    $msg="Removed $removed duplicate section rows.";
  }catch(Exception $e){ $error=$e->getMessage(); }
}

// Actions: mass enroll students to sections (selected bucket)
if ($_SERVER['REQUEST_METHOD']==='POST' && (($_POST['action']??'')==='mass_enroll')){
  try{
    $program_id=(int)($_POST['program_id']??0); $year_level=(int)($_POST['year_level']??0); $semester=(int)($_POST['semester']??0);
    if(!$program_id||!$year_level||!$semester) throw new Exception('Program, year and semester required');
    $syRow=$pdo->query("SELECT id FROM school_years WHERE status='active' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC); $sy=(int)($syRow['id']??0); if(!$sy) throw new Exception('No active school year');
    $hasSec=has_enrollment_section($pdo);
    $students=$pdo->prepare("SELECT id FROM users WHERE role='student' AND program_id=? AND year_level=? AND (status IS NULL OR status='active')"); $students->execute([$program_id,$year_level]); $stuIds=$students->fetchAll(PDO::FETCH_COLUMN);
    $cst=$pdo->prepare('SELECT id FROM courses WHERE program_id=? AND year_level=? AND semester=?'); $cst->execute([$program_id,$year_level,$semester]); $courseIds=$cst->fetchAll(PDO::FETCH_COLUMN);
    $avail=[]; foreach($courseIds as $cid){ $q=$pdo->prepare('SELECT id, capacity FROM sections WHERE course_id=? AND status=\'active\' ORDER BY section_code'); $q->execute([(int)$cid]); $rows=$q->fetchAll(PDO::FETCH_ASSOC); $avail[(int)$cid]=[]; foreach($rows as $r){ $occ=$hasSec? (int)$pdo->query("SELECT COUNT(*) FROM enrollments WHERE course_id=".(int)$cid." AND section_id=".(int)$r['id']." AND school_year_id=$sy")->fetchColumn() : 0; $avail[(int)$cid][]= ['id'=>(int)$r['id'],'cap'=>(int)$r['capacity'],'occ'=>$occ]; } }
    $pick=function(array &$l){ usort($l,fn($a,$b)=>($b['cap']-$b['occ'])<=>($a['cap']-$a['occ']) ?: ($a['occ']<=>$b['occ'])); foreach($l as &$s){ if($s['occ']<$s['cap']){ $s['occ']++; return $s['id']; } } return null; };
    $insCount=0;$updCount=0;$skip=0;$noSec=0;
    foreach($stuIds as $uid){ foreach($courseIds as $cid){ $ex=$pdo->prepare('SELECT id, section_id FROM enrollments WHERE student_id=? AND course_id=? AND school_year_id=? LIMIT 1'); $ex->execute([(int)$uid,(int)$cid,$sy]); $er=$ex->fetch(PDO::FETCH_ASSOC); $sec=null; if(!empty($avail[(int)$cid])) $sec=$pick($avail[(int)$cid]); if($sec===null){$noSec++; continue;} if($er){ if($hasSec && empty($er['section_id'])){ $u=$pdo->prepare('UPDATE enrollments SET section_id=? WHERE id=? AND section_id IS NULL'); $u->execute([(int)$sec,(int)$er['id']]); if($u->rowCount()>0){ $updCount++; $pdo->prepare('UPDATE sections SET enrolled_count = enrolled_count + 1 WHERE id=?')->execute([(int)$sec]); } else {$skip++;} } else {$skip++;} continue; } if($hasSec){ $i=$pdo->prepare('INSERT INTO enrollments (student_id,course_id,school_year_id,section_id,status) VALUES (?,?,?,?,\'enrolled\')'); $i->execute([(int)$uid,(int)$cid,$sy,(int)$sec]); } else { $i=$pdo->prepare('INSERT INTO enrollments (student_id,course_id,school_year_id,status) VALUES (?,?,?,\'enrolled\')'); $i->execute([(int)$uid,(int)$cid,$sy]); } if($i->rowCount()>0){ $insCount++; if($hasSec){ $pdo->prepare('UPDATE sections SET enrolled_count=enrolled_count+1 WHERE id=?')->execute([(int)$sec]); } } } }
    $msg="Auto-enroll: inserted $insCount, updated $updCount, skipped $skip, no-section $noSec.";
  }catch(Exception $e){ $error=$e->getMessage(); }
}

// Data for UI
$programs=$pdo->query('SELECT id, code, name FROM programs ORDER BY code')->fetchAll(PDO::FETCH_ASSOC);
$rooms=$pdo->query("SELECT id, room_code, room_name, capacity FROM rooms WHERE status = 'active' ORDER BY room_code")->fetchAll();
$sections=$pdo->query("SELECT s.*, c.course_code, c.course_name, c.year_level, p.id AS program_id, p.code AS program_code, (SELECT COUNT(*) FROM enrollments e WHERE e.section_id=s.id) AS enrolled_count FROM sections s LEFT JOIN courses c ON s.course_id=c.id LEFT JOIN programs p ON c.program_id=p.id ORDER BY c.year_level, p.code, c.course_code, s.section_code")->fetchAll(PDO::FETCH_ASSOC);

$progIndex=[];
foreach($programs as $p){ $progIndex[(int)$p['id']]=$p; }
$sectionsByYearProgram=[1=>[],2=>[],3=>[]];
foreach($sections as $s){
  $yl=(int)($s['year_level']??0);
  $pid=(int)($s['program_id']??0);
  if(isset($sectionsByYearProgram[$yl]) && $pid){
    if(!isset($sectionsByYearProgram[$yl][$pid])) $sectionsByYearProgram[$yl][$pid]=[];
    $sectionsByYearProgram[$yl][$pid][]=$s;
  }
}
?>
<!doctype html>
<html>
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Sections - Admin</title>
    <link rel="icon" type="image/svg+xml" href="../public/assets/icon/logo.svg">
    <link rel="stylesheet" href="../assets/css/admin-style.css">
    <link rel="stylesheet" href="../assets/css/dark-mode.css">
    <script src="../assets/js/dark-mode.js" defer></script>
  </head>
<body>
<div class="admin-layout">
  <?php $active='sections'; require __DIR__.'/inc/sidebar.php'; ?>
  <main class="main-content">
    <div class="container">
      <?php $pageTitle='Course Sections'; require __DIR__.'/inc/header.php'; ?>

      <?php if($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
      <?php if($msg): ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

      <!-- Tools -->
      <div class="card" style="margin-bottom:16px;">
        <h3>Tools</h3>
        <div class="alert" style="background:#f8fafc; border:1px solid #e5e7eb; color:#334155; margin-bottom:12px;">
          This tool will use the Active School Year and its Semester automatically.
        </div>
        <!-- Rebalance Over-capacity (Active SY) -->
        <form method="POST" style="margin-bottom:12px; display:flex; gap:10px; align-items:center;" onsubmit="return confirm('Rebalance all courses with enrollments this Active School Year? This may move students between sections and create new sections to meet capacity.');">
          <input type="hidden" name="action" value="rebalance_overcapacity">
          <button class="btn" type="submit">Rebalance Over-capacity — Active SY</button>
        </form>

        <!-- One-click bulk for active SY/Sem -->
        <form method="POST" style="margin-bottom:18px; display:flex; gap:10px; align-items:center;" onsubmit="return confirm('Run for Active School Year and Semester across ALL programs and years 1–3?');">
          <input type="hidden" name="action" value="bulk_active_all">
          <button class="btn" type="submit">Run for Active SY & Semester — All Programs, Years 1–3</button>
        </form>
        
        <!-- Auto-Create Sections -->
        <h4>Auto-Create Sections</h4>
        <form method="POST" style="display:grid; grid-template-columns: repeat(auto-fit,minmax(200px,1fr)); gap:12px; align-items:end;" onsubmit="return confirm('Auto-create sections for selected criteria?');">
          <input type="hidden" name="action" value="auto_sections">
          <div><label>Program</label><select name="program_id" required><?php foreach($programs as $p): ?><option value="<?= (int)$p['id'] ?>"><?= htmlspecialchars($p['code']) ?></option><?php endforeach; ?></select></div>
          <div><label>Year</label><select name="year_level" required><option value="1">1st</option><option value="2">2nd</option><option value="3">3rd</option></select></div>
          <div><label>Semester</label><select name="semester" required><option value="1">1st</option><option value="2">2nd</option><option value="3">Summer</option></select></div>
          <div><label>Capacity</label><input type="number" name="capacity" value="30" min="1" required></div>
          <div><button class="btn">Auto-Create Sections</button></div>
        </form>
        
        <!-- Assign Students -->
        <h4 style="margin-top:20px;">Assign Students</h4>
        <form method="POST" style="display:grid; grid-template-columns: repeat(auto-fit,minmax(200px,1fr)); gap:12px; align-items:end;" onsubmit="return confirm('Assign students to sections?');">
          <input type="hidden" name="action" value="mass_enroll">
          <div><label>Program</label><select name="program_id" required><?php foreach($programs as $p): ?><option value="<?= (int)$p['id'] ?>"><?= htmlspecialchars($p['code']) ?></option><?php endforeach; ?></select></div>
          <div><label>Year</label><select name="year_level" required><option value="1">1st</option><option value="2">2nd</option><option value="3">3rd</option></select></div>
          <div><label>Semester</label><select name="semester" required><option value="1">1st</option><option value="2">2nd</option><option value="3">Summer</option></select></div>
          <div><button class="btn">Assign Students</button></div>
        </form>
        
        <!-- Cleanup Duplicates -->
        <h4 style="margin-top:20px;">Cleanup Duplicate Sections</h4>
        <form method="POST" style="display:flex; gap:10px; align-items:center;" onsubmit="return confirm('Cleanup duplicate sections now?');">
          <input type="hidden" name="action" value="dedupe_sections"><button class="btn">Cleanup Duplicate Sections</button>
        </form>
        
        <!-- Reset Sections -->
        <h4 style="margin-top:20px;">Reset Sections</h4>
        <form method="POST" style="display:grid; grid-template-columns: repeat(auto-fit,minmax(200px,1fr)); gap:12px; align-items:end;" onsubmit="return confirm('Reset (delete) selected sections?');">
          <input type="hidden" name="action" value="reset_sections">
          <div><label>Program</label><select name="program_id"><option value="0">All</option><?php foreach($programs as $p): ?><option value="<?= (int)$p['id'] ?>"><?= htmlspecialchars($p['code']) ?></option><?php endforeach; ?></select></div>
          <div><label>Year</label><select name="year_level"><option value="0">All</option><option value="1">1st</option><option value="2">2nd</option><option value="3">3rd</option></select></div>
          <div><label>Semester</label><select name="semester"><option value="0">All</option><option value="1">1st</option><option value="2">2nd</option><option value="3">Summer</option></select></div>
          <div><button class="btn danger">Reset Sections</button></div>
        </form>
      </div>

      <!-- Sections Tables by Year and Program -->
      <?php $yearNames=[1=>'1st Year',2=>'2nd Year',3=>'3rd Year']; foreach([1,2,3] as $yr): $groups=$sectionsByYearProgram[$yr] ?? []; ?>
      <div class="card" style="margin-top:16px;">
        <h3>Sections - <?= htmlspecialchars($yearNames[$yr]) ?></h3>
        <?php if(!$groups): ?>
          <p class="text-muted">No sections for this year.</p>
        <?php else: ?>
          <?php foreach($groups as $pid => $rows): $pinfo=$progIndex[$pid] ?? null; ?>
          <div class="card collapsed-card" style="margin-top:12px;">
            <div class="card-header">
              <h3 class="card-title">
                <?= htmlspecialchars($pinfo['code'] ?? ('Program #'.$pid)) ?><?= isset($pinfo['name']) && $pinfo['name'] ? ' - '.htmlspecialchars($pinfo['name']) : '' ?>
              </h3>
              <div class="card-tools">
                <button type="button" class="btn btn-tool" data-card-widget="collapse" aria-expanded="false" aria-controls="">
                  <span class="icon">+</span>
                </button>
              </div>
            </div>
            <div class="card-body">
              <div class="table-responsive">
                <table class="table table-striped report-table">
                  <thead><tr><th>Program</th><th>Course</th><th>Section</th><th>Room</th><th class="text-right">Capacity</th><th>Enrolled</th><th>Instructors</th></tr></thead>
                  <tbody>
                    <?php foreach($rows as $s): ?>
                      <tr>
                        <td><?= htmlspecialchars($s['program_code']??'') ?></td>
                        <td><?= htmlspecialchars(($s['course_code']??'').' - '.($s['course_name']??'')) ?></td>
                        <td><?= htmlspecialchars($s['section_code']) ?></td>
                        <td><?= htmlspecialchars($s['room_code']??'No Room') ?></td>
                        <td class="text-right"><span class="badge badge-secondary"><?= (int)($s['capacity']??0) ?></span></td>
                        <td><span class="sec-enrolled" data-section-id="<?= (int)$s['id'] ?>"><?= (int)($s['enrolled_count']??0) ?></span></td>
                        <td><span class="sec-instructors" data-section-id="<?= (int)$s['id'] ?>"><?= htmlspecialchars(trim($instructorNamesBySection[(int)$s['id']] ?? '') ?: '—') ?></span></td>
                        <td>
                          <div style="display:flex; gap:6px; flex-wrap:wrap;">
                            <a class="btn" style="padding:4px 8px;" href="../instructor/print_period_grades.php?section_id=<?= (int)$s['id'] ?>&period=prelim" target="_blank">Prelim</a>
                            <a class="btn" style="padding:4px 8px;" href="../instructor/print_period_grades.php?section_id=<?= (int)$s['id'] ?>&period=midterm" target="_blank">Midterm</a>
                            <a class="btn" style="padding:4px 8px;" href="../instructor/print_period_grades.php?section_id=<?= (int)$s['id'] ?>&period=finals" target="_blank">Finals</a>
                          </div>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>

    </div>
  </main>
</div>
<script>
  (function(){
    function initCard(card){
      var body = card.querySelector('.card-body');
      if(!body) return;
      if(card.classList.contains('collapsed-card')){
        body.style.display = 'none';
      }
    }
    function toggleCard(card, btn){
      var body = card.querySelector('.card-body');
      if(!body) return;
      var collapsed = card.classList.toggle('collapsed-card');
      body.style.display = collapsed ? 'none' : '';
      if(btn){ btn.setAttribute('aria-expanded', collapsed ? 'false' : 'true'); }
      var ico = btn ? btn.querySelector('i, .icon') : null;
      if(ico){
        // Toggle plus/minus if using font icons or fallback text
        if(ico.classList.contains('fa-plus')){ ico.classList.remove('fa-plus'); ico.classList.add('fa-minus'); }
        else if(ico.classList.contains('fa-minus')){ ico.classList.remove('fa-minus'); ico.classList.add('fa-plus'); }
        else { ico.textContent = collapsed ? '+' : '−'; }
      }
    }
    document.addEventListener('DOMContentLoaded', function(){
      var cards = document.querySelectorAll('.card');
      cards.forEach(initCard);
      document.querySelectorAll('[data-card-widget="collapse"]').forEach(function(btn){
        btn.addEventListener('click', function(e){
          e.preventDefault();
          var card = btn.closest('.card');
          if(card) toggleCard(card, btn);
        });
      });
    });
  })();

  // Realtime polling for instructors and enrolled counts
  (function(){
    function applyStatus(list){
      list.forEach(function(it){
        var sid = String(it.section_id);
        var ec = document.querySelector('.sec-enrolled[data-section-id="'+sid+'"]');
        if (ec) ec.textContent = String(it.enrolled_count||0);
        var ins = document.querySelector('.sec-instructors[data-section-id="'+sid+'"]');
        if (ins) ins.textContent = (it.instructors && it.instructors.trim()) ? it.instructors : '—';
      });
    }
    function tick(){
      fetch('sections.php?action=sections_status', {credentials:'same-origin'})
        .then(function(r){ return r.json(); })
        .then(function(j){ if(j&&j.ok&&Array.isArray(j.data)) applyStatus(j.data); })
        .catch(function(){});
    }
    document.addEventListener('DOMContentLoaded', function(){
      tick();
      setInterval(tick, 12000);
    });
  })();
</script>
</body>
</html>
