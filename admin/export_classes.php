<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;

if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'admin') {
    die('无权访问');
}

$sql = "SELECT c.class_id, c.class_name, m.major_name, t.name AS teacher_name
        FROM classes c
        LEFT JOIN majors m ON c.major_id = m.major_id
        LEFT JOIN teachers t ON c.headmaster_uuid = t.uuid
        ORDER BY c.class_id ASC";

$result = $conn->query($sql);
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$headers = ['班级编号', '班级名称', '所属专业', '班主任'];
$sheet->fromArray($headers, null, 'A1');

$sheet->getStyle('A1:D1')->getFont()->setBold(true);
$sheet->getStyle('A1:D1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFDDDDDD');

$row = 2;
while ($d = $result->fetch_assoc()) {
    $sheet->setCellValue('A'.$row, $d['class_id']);
    $sheet->setCellValue('B'.$row, $d['class_name']);
    $sheet->setCellValue('C'.$row, $d['major_name']);
    $sheet->setCellValue('D'.$row, $d['teacher_name']);
    $row++;
}

foreach (range('A', 'D') as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="classes_'.date('YmdHis').'.xlsx"');
$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
?>