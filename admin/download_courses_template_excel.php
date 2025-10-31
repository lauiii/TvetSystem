<?php
// Admin: Download Excel template for importing courses
require_once __DIR__ . '/../config.php';

// Ensure PhpSpreadsheet is available
try {
    require_once __DIR__ . '/../vendor/autoload.php';
} catch (Throwable $e) {
    header('Content-Type: text/plain; charset=utf-8');
    http_response_code(500);
    echo "PhpSpreadsheet not installed. Run: composer require phpoffice/phpspreadsheet\n";
    exit;
}

if (!class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) {
    header('Content-Type: text/plain; charset=utf-8');
    http_response_code(500);
    echo "PhpSpreadsheet not installed. Run: composer require phpoffice/phpspreadsheet\n";
    exit;
}

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;

$headers = ['program_code','course_code','course_name','units','year_level','semester'];
$sample = [
    ['DIT','DIT101','Introduction to IT',3,1,1],
    ['DIT','DIT102','Computer Fundamentals',3,1,1],
    ['DIST','DIST201','Mobile Development',3,2,1],
];

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Courses Import');

// Write headers
$col = 1; $row = 1;
foreach ($headers as $h) { $sheet->setCellValueByColumnAndRow($col++, $row, $h); }

// Style headers
$headerRange = 'A1:' . $sheet->getCellByColumnAndRow(count($headers), 1)->getCoordinate();
$sheet->getStyle($headerRange)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFF3E8FF');
$sheet->getStyle($headerRange)->getFont()->setBold(true)->getColor()->setARGB('FF4B048F');
$sheet->getStyle($headerRange)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
$sheet->getStyle($headerRange)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setARGB('FFDDC8FF');

// Samples
$row = 2;
foreach ($sample as $r) {
    $col = 1;
    foreach ($r as $v) { $sheet->setCellValueByColumnAndRow($col++, $row, $v); }
    $row++;
}

// Column widths
foreach (range(1, count($headers)) as $c) { $sheet->getColumnDimensionByColumn($c)->setAutoSize(true); }

// Freeze header
$sheet->freezePane('A2');

// Data types/alignments (limit to sample rows to avoid large cell collections)
$maxSampleRow = max(2, count($sample) + 1);
$sheet->getStyle('D2:F' . $maxSampleRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

// Output
$filename = 'courses_import_template.xlsx';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');
$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
