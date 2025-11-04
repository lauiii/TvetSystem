<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../include/email-functions.php';
requireRole('admin');
if (!function_exists('get_admin_email')) { function get_admin_email(PDO $pdo = null) { return defined('SMTP_FROM') ? SMTP_FROM : ''; } }

$notice = '';
$error = '';

function current_v($const, $env) {
    if (defined($const)) return constant($const);
    $ev = getenv($env);
    return $ev !== false ? $ev : '';
}

// Path to local override file (git-ignored)
$localPath = __DIR__ . '/../config.local.php';
$presetPath = __DIR__ . '/../config.smtp_presets.json';

$presets = [];
if (file_exists($presetPath)) {
    $raw = @file_get_contents($presetPath);
    $arr = json_decode($raw, true);
    if (is_array($arr)) { $presets = $arr; }
}

function write_presets_file($path, $data) {
    $tmp = $path.'.tmp';
    if (file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT)) === false) {
        throw new Exception('Failed to write presets file.');
    }
    if (!@rename($tmp, $path)) { @unlink($tmp); throw new Exception('Failed to update presets file'); }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Apply or delete preset
    $action = $_POST['action'] ?? '';
    if ($action === 'apply_preset') {
        $key = trim($_POST['preset_key'] ?? '');
        if ($key === '' || !isset($presets[$key])) { $error = 'Preset not found.'; }
        else {
            $p = $presets[$key];
            $_POST['SMTP_HOST'] = $p['SMTP_HOST'] ?? '';
            $_POST['SMTP_PORT'] = $p['SMTP_PORT'] ?? 587;
            $_POST['SMTP_USER'] = $p['SMTP_USER'] ?? '';
            $_POST['SMTP_PASS'] = $p['SMTP_PASS'] ?? '';
            $_POST['SMTP_FROM'] = $p['SMTP_FROM'] ?? '';
            $_POST['SMTP_FROM_NAME'] = $p['SMTP_FROM_NAME'] ?? (defined('SITE_NAME')?SITE_NAME:'');
            $_POST['SMTP_SECURE'] = $p['SMTP_SECURE'] ?? 'tls';
            $_POST['SMTP_DEBUG'] = $p['SMTP_DEBUG'] ?? 0;
            $_POST['EMAIL_MODE'] = $p['EMAIL_MODE'] ?? '';
            $_POST['SMTP_FORCE_IPV4'] = $p['SMTP_FORCE_IPV4'] ?? 0;
            $_POST['SMTP_VERIFY_PEER'] = $p['SMTP_VERIFY_PEER'] ?? 1;
            // fall-through to normal save handler below
        }
    } elseif ($action === 'delete_preset') {
        $key = trim($_POST['preset_key'] ?? '');
        if ($key === '' || !isset($presets[$key])) { $error = 'Preset not found.'; }
        else {
            unset($presets[$key]);
            try { write_presets_file($presetPath, $presets); $notice = 'Preset deleted.'; }
            catch (Exception $e) { $error = $e->getMessage(); }
        }
    }

    $SMTP_HOST = trim($_POST['SMTP_HOST'] ?? '');
    $SMTP_PORT = (int)($_POST['SMTP_PORT'] ?? 587);
    $SMTP_USER = trim($_POST['SMTP_USER'] ?? '');
    $SMTP_PASS = trim($_POST['SMTP_PASS'] ?? '');
    $SMTP_FROM = trim($_POST['SMTP_FROM'] ?? '');
    $SMTP_FROM_NAME = trim($_POST['SMTP_FROM_NAME'] ?? SITE_NAME);
    $SMTP_SECURE = in_array(strtolower($_POST['SMTP_SECURE'] ?? 'tls'), ['ssl','tls'], true) ? strtolower($_POST['SMTP_SECURE']) : 'tls';
    $SMTP_DEBUG = (int)($_POST['SMTP_DEBUG'] ?? 0);
    $EMAIL_MODE = trim($_POST['EMAIL_MODE'] ?? ''); // '', 'queue', 'off'
    $SMTP_FORCE_IPV4 = (int)($_POST['SMTP_FORCE_IPV4'] ?? 0);
    $SMTP_VERIFY_PEER = (int)($_POST['SMTP_VERIFY_PEER'] ?? 1);

    try {
        // Generate config.local.php contents
        $php = "<?php\n";
        $def = function($k,$v) { $v = str_replace(['\\\\', "'"], ['\\\\\\\\', "\\'"], (string)$v); return "define('{$k}', '{$v}');\n"; };
        $defi = function($k,$v) { $v = (int)$v; return "define('{$k}', {$v});\n"; };

        if ($SMTP_HOST !== '') $php .= $def('SMTP_HOST', $SMTP_HOST);
        if ($SMTP_PORT > 0) $php .= $defi('SMTP_PORT', $SMTP_PORT);
        if ($SMTP_USER !== '') $php .= $def('SMTP_USER', $SMTP_USER);
        if ($SMTP_PASS !== '') $php .= $def('SMTP_PASS', $SMTP_PASS);
        if ($SMTP_FROM !== '') $php .= $def('SMTP_FROM', $SMTP_FROM);
        if ($SMTP_FROM_NAME !== '') $php .= $def('SMTP_FROM_NAME', $SMTP_FROM_NAME);
        if ($SMTP_SECURE !== '') $php .= $def('SMTP_SECURE', $SMTP_SECURE);
        $php .= $defi('SMTP_DEBUG', $SMTP_DEBUG);
        if ($EMAIL_MODE !== '') putenv('EMAIL_MODE='.$EMAIL_MODE);
        putenv('SMTP_FORCE_IPV4='.$SMTP_FORCE_IPV4);
        putenv('SMTP_VERIFY_PEER='.$SMTP_VERIFY_PEER);
        $php .= $defi('SMTP_DEBUG_OVERRIDE', $SMTP_DEBUG);

        // Write file atomically
        $tmp = $localPath.'.tmp';
        if (file_put_contents($tmp, $php) === false) throw new Exception('Failed to write temporary config.');
        if (!@rename($tmp, $localPath)) {
            @unlink($tmp);
            throw new Exception('Failed to update config.local.php');
        }

        $notice = 'Email settings saved to config.local.php. These will take effect immediately for new requests.';

        // Save as preset when requested
        if (($action === 'save_preset') && trim($_POST['preset_name'] ?? '') !== '') {
            $key = trim($_POST['preset_name']);
            $presets[$key] = [
                'SMTP_HOST' => $SMTP_HOST,
                'SMTP_PORT' => $SMTP_PORT,
                'SMTP_USER' => $SMTP_USER,
                'SMTP_PASS' => $SMTP_PASS,
                'SMTP_FROM' => $SMTP_FROM,
                'SMTP_FROM_NAME' => $SMTP_FROM_NAME,
                'SMTP_SECURE' => $SMTP_SECURE,
                'SMTP_DEBUG' => $SMTP_DEBUG,
                'EMAIL_MODE' => $EMAIL_MODE,
                'SMTP_FORCE_IPV4' => $SMTP_FORCE_IPV4,
                'SMTP_VERIFY_PEER' => $SMTP_VERIFY_PEER,
            ];
            write_presets_file($presetPath, $presets);
            $notice .= ' Preset saved.';
        }

        if (isset($_POST['send_test']) && $_POST['send_test'] === '1') {
            $to = get_admin_email($pdo);
            $ok = sendNotificationEmail($to, 'Admin', 'Test Email from Secret Mail Settings', '<p>This is a test email.</p>');
            $notice .= $ok ? ' Test email sent to '.$to.'.' : ' Test email failed. Check logs.';
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Pull current values for display
$cur = [
    'SMTP_HOST' => current_v('SMTP_HOST','SMTP_HOST'),
    'SMTP_PORT' => current_v('SMTP_PORT','SMTP_PORT') ?: 587,
    'SMTP_USER' => current_v('SMTP_USER','SMTP_USER'),
    'SMTP_PASS' => current_v('SMTP_PASS','SMTP_PASS'),
    'SMTP_FROM' => current_v('SMTP_FROM','SMTP_FROM'),
    'SMTP_FROM_NAME' => defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : SITE_NAME,
    'SMTP_SECURE' => current_v('SMTP_SECURE','SMTP_SECURE') ?: 'tls',
    'SMTP_DEBUG' => defined('SMTP_DEBUG_OVERRIDE') ? SMTP_DEBUG_OVERRIDE : (defined('SMTP_DEBUG') ? SMTP_DEBUG : 0),
    'EMAIL_MODE' => getenv('EMAIL_MODE') ?: '',
    'SMTP_FORCE_IPV4' => getenv('SMTP_FORCE_IPV4') !== false ? (int)getenv('SMTP_FORCE_IPV4') : 0,
    'SMTP_VERIFY_PEER' => getenv('SMTP_VERIFY_PEER') !== false ? (int)getenv('SMTP_VERIFY_PEER') : 1,
];
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Secret Mail Settings</title>
  <link rel="stylesheet" href="../assets/css/admin-style.css">
  <link rel="stylesheet" href="../assets/css/dark-mode.css">
  <script src="../assets/js/dark-mode.js" defer></script>
</head>
<body>
<div class="admin-layout">
  <?php $active=''; require __DIR__.'/inc/sidebar.php'; ?>
  <main class="main-content">
    <div class="container">
      <h2>Secret Mail Settings</h2>
      <p style="color:#64748b">Use this page to quickly change SMTP settings if emailing limits are exceeded. Values are stored in config.local.php.</p>
      <?php if ($error): ?><div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
      <?php if ($notice): ?><div class="alert alert-success"><?php echo htmlspecialchars($notice); ?></div><?php endif; ?>
      <div class="card">
        <form method="post" style="display:flex; gap:10px; align-items:end; margin-bottom:12px;">
          <div style="flex:1;">
            <label>Saved Presets</label>
            <select name="preset_key" style="width:100%">
              <option value="">-- Select preset --</option>
              <?php foreach($presets as $k=>$v): ?>
                <option value="<?php echo htmlspecialchars($k); ?>"><?php echo htmlspecialchars($k . ' — ' . ($v['SMTP_USER'] ?? '')); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <input type="hidden" name="action" value="apply_preset">
            <button class="btn" type="submit">Apply Preset</button>
          </div>
        </form>
        <form method="post" style="display:flex; gap:10px; align-items:end; margin-top:-6px; margin-bottom:16px;">
          <div style="flex:1;">
            <label>Delete Preset</label>
            <select name="preset_key" style="width:100%">
              <option value="">-- Select preset --</option>
              <?php foreach($presets as $k=>$v): ?>
                <option value="<?php echo htmlspecialchars($k); ?>"><?php echo htmlspecialchars($k . ' — ' . ($v['SMTP_USER'] ?? '')); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <input type="hidden" name="action" value="delete_preset">
            <button class="btn danger" onclick="return confirm('Delete selected preset?')">Delete</button>
          </div>
        </form>
        <form method="post" style="display:grid; grid-template-columns: repeat(auto-fit,minmax(260px,1fr)); gap:12px; align-items:end;">
          <div><label>SMTP Host</label><input name="SMTP_HOST" value="<?php echo htmlspecialchars($cur['SMTP_HOST']); ?>" required></div>
          <div><label>SMTP Port</label><input type="number" name="SMTP_PORT" value="<?php echo (int)$cur['SMTP_PORT']; ?>" required></div>
          <div><label>SMTP User</label><input name="SMTP_USER" value="<?php echo htmlspecialchars($cur['SMTP_USER']); ?>"></div>
          <div><label>SMTP Pass</label><input name="SMTP_PASS" value="<?php echo htmlspecialchars($cur['SMTP_PASS']); ?>" type="password"></div>
          <div><label>From Email</label><input name="SMTP_FROM" value="<?php echo htmlspecialchars($cur['SMTP_FROM']); ?>"></div>
          <div><label>From Name</label><input name="SMTP_FROM_NAME" value="<?php echo htmlspecialchars($cur['SMTP_FROM_NAME']); ?>"></div>
          <div><label>Security</label>
            <select name="SMTP_SECURE">
              <option value="tls" <?php echo strtolower($cur['SMTP_SECURE'])==='tls'?'selected':''; ?>>TLS (587)</option>
              <option value="ssl" <?php echo strtolower($cur['SMTP_SECURE'])==='ssl'?'selected':''; ?>>SSL (465)</option>
            </select>
          </div>
          <div><label>SMTP Debug</label>
            <select name="SMTP_DEBUG">
              <?php foreach([0,1,2,3,4] as $lvl): ?>
                <option value="<?php echo $lvl; ?>" <?php echo ((int)$cur['SMTP_DEBUG']===$lvl)?'selected':''; ?>><?php echo $lvl; ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div><label>Email Mode</label>
            <select name="EMAIL_MODE">
              <option value="" <?php echo $cur['EMAIL_MODE']===''?'selected':''; ?>>Send Now</option>
              <option value="queue" <?php echo strtolower($cur['EMAIL_MODE'])==='queue'?'selected':''; ?>>Queue</option>
              <option value="off" <?php echo strtolower($cur['EMAIL_MODE'])==='off'?'selected':''; ?>>Off/Disabled</option>
            </select>
          </div>
          <div><label>Force IPv4</label>
            <select name="SMTP_FORCE_IPV4">
              <option value="0" <?php echo ((int)$cur['SMTP_FORCE_IPV4']===0)?'selected':''; ?>>No</option>
              <option value="1" <?php echo ((int)$cur['SMTP_FORCE_IPV4']===1)?'selected':''; ?>>Yes</option>
            </select>
          </div>
          <div><label>Verify TLS Peer</label>
            <select name="SMTP_VERIFY_PEER">
              <option value="1" <?php echo ((int)$cur['SMTP_VERIFY_PEER']===1)?'selected':''; ?>>Yes (Recommended)</option>
              <option value="0" <?php echo ((int)$cur['SMTP_VERIFY_PEER']===0)?'selected':''; ?>>No (Debug only)</option>
            </select>
          </div>
          <div style="grid-column: 1 / -1; display:flex; gap:10px; align-items:center;">
            <label style="display:flex; align-items:center; gap:6px;"><input type="checkbox" name="send_test" value="1"> Send test to Admin</label>
            <button class="btn" type="submit" name="action" value="save_settings">Save Settings</button>
            <input type="text" name="preset_name" placeholder="Save as preset name" style="max-width:260px;">
            <button class="btn" type="submit" name="action" value="save_preset">Save as Preset</button>
            <a class="btn" href="students.php" style="text-decoration:none;">Back</a>
          </div>
        </form>
      </div>
      <div class="card" style="margin-top:12px;">
        <h3>Tips</h3>
        <ul>
          <li>Use Email Mode = Off to temporarily stop sending when limits are exceeded.</li>
          <li>Switch SMTP Host/User to a different provider, then Save and Send test.</li>
          <li>Set SMTP Debug to 2–3 to see verbose logs in your PHP error log if delivery fails.</li>
        </ul>
      </div>
    </div>
  </main>
</div>
</body>
</html>
