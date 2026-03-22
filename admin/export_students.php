<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../vendor/autoload.php'; // 引入 PhpSpreadsheet

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;

// 权限验证
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'admin') {
    die('无权访问');
}

// 获取学生数据，关联班级、专业（可根据需要调整字段）
$sql = "SELECT s.uuid, s.name, c.class_name, m.major_name 
        FROM students s 
        LEFT JOIN classes c ON s.class_id = c.class_id 
        LEFT JOIN majors m ON c.major_id = m.major_id 
        ORDER BY s.uuid ASC";
$result = $conn->query($sql);

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

// 设置表头
$headers = ['学号', '姓名', '班级', '专业'];
$sheet->fromArray($headers, null, 'A1');

// 样式：表头加粗、背景色
$sheet->getStyle('A1:D1')->getFont()->setBold(true);
$sheet->getStyle('A1:D1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFDDDDDD');

// 填充数据
$row = 2;
while ($rowData = $result->fetch_assoc()) {
    $sheet->setCellValue('A' . $row, $rowData['uuid']);
    $sheet->setCellValue('B' . $row, $rowData['name']);
    $sheet->setCellValue('C' . $row, $rowData['class_name'] ?? '');
    $sheet->setCellValue('D' . $row, $rowData['major_name'] ?? '');
    $row++;
}

// 自动调整列宽
foreach (range('A', 'D') as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}

// 输出文件
$writer = new Xlsx($spreadsheet);
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="students_' . date('YmdHis') . '.xlsx"');
$writer->save('php://output');
exit;