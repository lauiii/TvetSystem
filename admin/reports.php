<?php
/**
 * Admin - Generate Reports
 * Create various reports with PDF/Excel export functionality
 */
require_once __DIR__ . '/../config.php';
requireRole('admin');

$error = '';
$msg = '';

// Check if TCPDF is available for PDF export
$tcpdf_available = class_exists('TCPDF');
$phpspreadsheet_available = class_exists('PhpOffice\PhpSpreadsheet\Spreadsheet');

// Report type selection
$report_type = $_GET['type'] ?? 'students';

// Generate report data based on type
$report_data = [];
$report_title = '';

switch ($report_type) {
    case 'students':
        $report_title = 'Student Enrollment Report';
        $userCols = [];
        try {
            $colStmt = $pdo->query("SHOW COLUMNS FROM users");
            $userCols = $colStmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (Exception $e) {
            $userCols = ['id','student_id','first_name','last_name','name','email','role'];
        }

        $studentSelect = ['u.student_id', 'u.email', 'u.year_level', 'p.name as program_name', 'COUNT(e.id) as enrolled_courses', 'u.status', 'u.created_at'];
        $orderBy = 'u.id';
        if (in_array('first_name', $userCols) && in_array('last_name', $userCols)) {
            $studentSelect[] = 'u.first_name';
            $studentSelect[] = 'u.last_name';
            $orderBy = 'u.last_name, u.first_name';
        } elseif (in_array('name', $userCols)) {
            $studentSelect[] = 'u.name';
        }

        $stmt = $pdo->query("
            SELECT " . implode(', ', $studentSelect) . "
            FROM users u
            LEFT JOIN programs p ON u.program_id = p.id
            LEFT JOIN enrollments e ON u.id = e.student_id
            WHERE u.role = 'student'
            GROUP BY u.id
            ORDER BY $orderBy
        ");
        $report_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        break;

    case 'courses':
        $report_title = 'Course Enrollment Report';
        $userCols = [];
        try {
            $colStmt = $pdo->query("SHOW COLUMNS FROM users");
            $userCols = $colStmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (Exception $e) {
            $userCols = ['id','student_id','first_name','last_name','name','email','role'];
        }

        $instructorSelect = ['COUNT(e.id) as enrolled_students'];
        if (in_array('first_name', $userCols) && in_array('last_name', $userCols)) {
            $instructorSelect[] = 'u_instructor.first_name as instructor_first';
            $instructorSelect[] = 'u_instructor.last_name as instructor_last';
        } elseif (in_array('name', $userCols)) {
            $instructorSelect[] = 'u_instructor.name as instructor_name';
        }

        $stmt = $pdo->query("
            SELECT
                c.course_code,
                c.course_name,
                c.year_level,
                c.semester,
                p.name as program_name,
                " . implode(', ', $instructorSelect) . "
            FROM courses c
            LEFT JOIN programs p ON c.program_id = p.id
            LEFT JOIN users u_instructor ON c.instructor_id = u_instructor.id
            LEFT JOIN enrollments e ON c.id = e.course_id
            GROUP BY c.id
            ORDER BY p.name, c.year_level, c.semester, c.course_code
        ");
        $report_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        break;

    case 'grades':
        $report_title = 'Grade Summary Report';
        $userCols = [];
        try {
            $colStmt = $pdo->query("SHOW COLUMNS FROM users");
            $userCols = $colStmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (Exception $e) {
            $userCols = ['id','student_id','first_name','last_name','name','email','role'];
        }

        $studentSelect = ['u.student_id', 'c.course_code', 'c.course_name', 'AVG(g.grade) as average_grade', 'COUNT(g.id) as assessments_completed', 'sy.year as school_year', 'sy.semester'];
        $orderBy = 'u.id, c.course_code';
        if (in_array('first_name', $userCols) && in_array('last_name', $userCols)) {
            $studentSelect[] = 'u.first_name';
            $studentSelect[] = 'u.last_name';
            $orderBy = 'u.last_name, u.first_name, c.course_code';
        } elseif (in_array('name', $userCols)) {
            $studentSelect[] = 'u.name';
        }

        $stmt = $pdo->query("
            SELECT " . implode(', ', $studentSelect) . "
            FROM users u
            INNER JOIN enrollments e ON u.id = e.student_id
            INNER JOIN courses c ON e.course_id = c.id
            INNER JOIN school_years sy ON e.school_year_id = sy.id
            LEFT JOIN grades g ON e.id = g.enrollment_id
            WHERE u.role = 'student' AND g.grade IS NOT NULL
            GROUP BY u.id, c.id, sy.id
            ORDER BY $orderBy
        ");
        $report_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        break;

    case 'flags':
        $report_title = 'Student Flags Report';
        $userCols = [];
        try {
            $colStmt = $pdo->query("SHOW COLUMNS FROM users");
            $userCols = $colStmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (Exception $e) {
            $userCols = ['id','student_id','first_name','last_name','name','email','role'];
        }

        $studentSelect = ['u.student_id', 'c.course_code', 'c.course_name', 'f.issue', 'f.description', 'f.status', 'f.created_at'];
        if (in_array('first_name', $userCols) && in_array('last_name', $userCols)) {
            $studentSelect[] = 'u.first_name';
            $studentSelect[] = 'u.last_name';
            $studentSelect[] = 'u_flagger.first_name as flagger_first';
            $studentSelect[] = 'u_flagger.last_name as flagger_last';
        } elseif (in_array('name', $userCols)) {
            $studentSelect[] = 'u.name';
            $studentSelect[] = 'u_flagger.name as flagger_name';
        }

        $stmt = $pdo->query("
            SELECT " . implode(', ', $studentSelect) . "
            FROM flags f
            INNER JOIN users u ON f.student_id = u.id
            INNER JOIN courses c ON f.course_id = c.id
            LEFT JOIN users u_flagger ON f.flagged_by = u_flagger.id
            ORDER BY f.status ASC, f.created_at DESC
        ");
        $report_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        break;
}

// Handle export
if (isset($_GET['export'])) {
    $export_format = $_GET['export'];

    if ($export_format === 'csv') {
        // CSV Export
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . strtolower(str_replace(' ', '_', $report_title)) . '.csv"');

        $output = fopen('php://output', 'w');

        // Headers
        if (count($report_data) > 0) {
            fputcsv($output, array_keys($report_data[0]));
        }

        // Data
        foreach ($report_data as $row) {
            fputcsv($output, $row);
        }

        fclose($output);
        exit;

    } elseif ($export_format === 'pdf' && $tcpdf_available) {
        // PDF Export using TCPDF
        require_once '../vendor/tecnickcom/tcpdf/tcpdf.php';

        $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
        $pdf->SetCreator(PDF_CREATOR);
        $pdf->SetAuthor('College Grading System');
        $pdf->SetTitle($report_title);

        $pdf->setHeaderFont(Array(PDF_FONT_NAME_MAIN, '', PDF_FONT_SIZE_MAIN));
        $pdf->setFooterFont(Array(PDF_FONT_NAME_DATA, '', PDF_FONT_SIZE_DATA));
        $pdf->SetDefaultMonospacedFont(PDF_FONT_MONOSPACED);
        $pdf->SetMargins(PDF_MARGIN_LEFT, PDF_MARGIN_TOP, PDF_MARGIN_RIGHT);
        $pdf->SetHeaderMargin(PDF_MARGIN_HEADER);
        $pdf->SetFooterMargin(PDF_MARGIN_FOOTER);
        $pdf->SetAutoPageBreak(TRUE, PDF_MARGIN_BOTTOM);
        $pdf->setImageScale(PDF_IMAGE_SCALE_RATIO);

        $pdf->AddPage();

        // Title
        $pdf->SetFont('helvetica', 'B', 16);
        $pdf->Cell(0, 10, $report_title, 0, 1, 'C');
        $pdf->Ln(10);

        // Table
        $pdf->SetFont('helvetica', '', 8);

        if (count($report_data) > 0) {
            // Headers
            $pdf->SetFillColor(230, 230, 230);
            $pdf->SetTextColor(0);
            $pdf->SetDrawColor(0, 0, 0);
            $pdf->SetLineWidth(0.3);
            $pdf->SetFont('', 'B');

            $headers = array_keys($report_data[0]);
            $w = array_fill(0, count($headers), 40); // Default width

            foreach ($headers as $i => $header) {
                $pdf->Cell($w[$i], 7, ucwords(str_replace('_', ' ', $header)), 1, 0, 'C', 1);
            }
            $pdf->Ln();

            // Data
            $pdf->SetFillColor(255, 255, 255);
            $pdf->SetTextColor(0);
            $pdf->SetFont('');

            $fill = 0;
            foreach ($report_data as $row) {
                foreach ($row as $value) {
                    $pdf->Cell(40, 6, $value, 'LR', 0, 'L', $fill);
                }
                $pdf->Ln();
                $fill = !$fill;
            }
        }

        $pdf->Output(strtolower(str_replace(' ', '_', $report_title)) . '.pdf', 'D');
        exit;

    } elseif ($export_format === 'excel' && $phpspreadsheet_available) {
        // Excel Export using PhpSpreadsheet
        require_once '../vendor/autoload.php';

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle($report_title);

        if (count($report_data) > 0) {
            // Headers
            $headers = array_keys($report_data[0]);
            $col = 'A';
            foreach ($headers as $header) {
                $sheet->setCellValue($col . '1', ucwords(str_replace('_', ' ', $header)));
                $sheet->getStyle($col . '1')->getFont()->setBold(true);
                $sheet->getStyle($col . '1')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFE0E0E0');
                $col++;
            }

            // Data
            $row = 2;
            foreach ($report_data as $data_row) {
                $col = 'A';
                foreach ($data_row as $value) {
                    $sheet->setCellValue($col . $row, $value);
                    $col++;
                }
                $row++;
            }

            // Auto-size columns
            foreach (range('A', $col) as $columnID) {
                $sheet->getColumnDimension($columnID)->setAutoSize(true);
            }
        }

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . strtolower(str_replace(' ', '_', $report_title)) . '.xlsx"');
        header('Cache-Control: max-age=0');

        $writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, 'Xlsx');
        $writer->save('php://output');
        exit;
    }
}

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Reports - Admin</title>
    <link rel="stylesheet" href="../assets/css/admin-style.css">
</head>
<body>
    <div class="admin-layout">
        <?php $active = 'reports'; require __DIR__ . '/inc/sidebar.php'; ?>

        <main class="main-content">
            <div class="container">
                <?php $pageTitle = 'Reports'; require __DIR__ . '/inc/header.php'; ?>

                <div class="card">
                    <?php if ($error): ?><div class="err"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
                    <?php if ($msg): ?><div class="ok"><?php echo htmlspecialchars($msg); ?></div><?php endif; ?>

                    <h3>Select Report Type</h3>
                    <form method="GET" class="report-form">
                        <div class="form-row">
                            <label>Report Type:</label>
                            <select name="type" onchange="this.form.submit()">
                                <option value="students" <?php echo $report_type === 'students' ? 'selected' : ''; ?>>Student Enrollment Report</option>
                                <option value="courses" <?php echo $report_type === 'courses' ? 'selected' : ''; ?>>Course Enrollment Report</option>
                                <option value="grades" <?php echo $report_type === 'grades' ? 'selected' : ''; ?>>Grade Summary Report</option>
                                <option value="flags" <?php echo $report_type === 'flags' ? 'selected' : ''; ?>>Student Flags Report</option>
                            </select>
                        </div>
                    </form>

                    <div class="export-buttons">
                        <a href="?type=<?php echo $report_type; ?>&export=csv" class="btn primary">Export CSV</a>
                        <?php if ($tcpdf_available): ?>
                            <a href="?type=<?php echo $report_type; ?>&export=pdf" class="btn primary">Export PDF</a>
                        <?php else: ?>
                            <span class="btn disabled" title="TCPDF library not installed">PDF Export (Not Available)</span>
                        <?php endif; ?>
                        <?php if ($phpspreadsheet_available): ?>
                            <a href="?type=<?php echo $report_type; ?>&export=excel" class="btn primary">Export Excel</a>
                        <?php else: ?>
                            <span class="btn disabled" title="PhpSpreadsheet library not installed">Excel Export (Not Available)</span>
                        <?php endif; ?>
                    </div>
                </div>

                <div style="height:20px"></div>
                <div class="card">
                    <h3><?php echo htmlspecialchars($report_title); ?> (<?php echo count($report_data); ?> records)</h3>

                    <?php if (count($report_data) === 0): ?>
                        <p>No data available for this report.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="report-table">
                                <thead>
                                    <tr>
                                        <?php foreach (array_keys($report_data[0]) as $header): ?>
                                            <th><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $header))); ?></th>
                                        <?php endforeach; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($report_data as $row): ?>
                                        <tr>
                                            <?php foreach ($row as $value): ?>
                                                <td><?php echo htmlspecialchars($value ?? 'N/A'); ?></td>
                                            <?php endforeach; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <style>
        .report-form {
            margin-bottom: 20px;
        }

        .form-row {
            margin-bottom: 15px;
        }

        .form-row label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
        }

        .form-row select {
            width: 100%;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }

        .export-buttons {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .table-responsive {
            overflow-x: auto;
        }

        .report-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 600px;
        }

        .report-table th,
        .report-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e0e0e0;
        }

        .report-table th {
            background: #6a0dad;
            color: white;
            font-weight: 600;
        }

        .report-table tr:hover {
            background: #f8f9fa;
        }

        .btn.disabled {
            background: #ccc;
            color: #666;
            cursor: not-allowed;
        }

        @media (max-width: 768px) {
            .export-buttons {
                flex-direction: column;
            }

            .export-buttons .btn {
                width: 100%;
            }
        }
    </style>
</body>
</html>
