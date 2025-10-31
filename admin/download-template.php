<?php
/**
 * Download CSV template for bulk enrollment
 * Columns: First Name, Last Name, Email, Program, Year Level
 */

// If ?raw=1 requested, send CSV directly. Otherwise show a small page with the link (uses admin layout).
if (isset($_GET['raw']) && $_GET['raw']) {
	header('Content-Type: text/csv; charset=utf-8');
	header('Content-Disposition: attachment; filename="bulk_enroll_template.csv"');

	$output = fopen('php://output', 'w');
	fputcsv($output, ['First Name', 'Last Name', 'Email', 'Program', 'Year Level']);
	fputcsv($output, ['Juan', 'Dela Cruz', 'juan@example.edu', 'BSIT', '1']);
	fclose($output);
	exit;
}

// Render a small admin page with shared sidebar/header that links to the raw CSV
require_once __DIR__ . '/inc/sidebar.php';
require_once __DIR__ . '/inc/header.php';
// Note: this is intentionally minimal — the bulk-enroll page links directly to ?raw=1 for downloads.
?>
<!doctype html>
<html>
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width,initial-scale=1">
	<title>Download Template - Admin</title>
	<link rel="stylesheet" href="../assets/css/admin-style.css">
	<style>.card{padding:18px;background:#fff;border-radius:10px;box-shadow:0 6px 20px rgba(0,0,0,0.04)}</style>
</head>
<body>
	<div class="admin-layout">
		<?php $active = ''; /* no menu active */ ?>
		<?php require __DIR__ . '/inc/sidebar.php'; ?>

		<main class="main-content">
			<div class="container">
				<?php $pageTitle = 'Download Template'; require __DIR__ . '/inc/header.php'; ?>
				<div class="card">
					<p>You can download the CSV template for bulk enrollment using the button below.</p>
					<p><a class="btn primary" href="download-template.php?raw=1">Download CSV Template</a></p>
				</div>
			</div>
		</main>
	</div>
</body>
</html>

