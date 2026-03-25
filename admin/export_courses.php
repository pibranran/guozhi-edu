<?php
require_once __DIR__ . "/../config.php";
require_once __DIR__ . "/../vendor/autoload.php";

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

// 权限验证
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'admin') {
    die("无权限");
}

// 查询所有课程
$query = "SELECT id, course_code, course_name FROM courses ORDER BY id ASC";
$result = $conn->query($query);

// 创建 Excel
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

// 设置表头
$sheet->setCellValue('A1', '课程ID');
$sheet->setCellValue('B1', '课程代码');
$sheet->setCellValue('C1', '课程名称');

// 写入数据
$row = 2;
while ($data = $result->fetch_assoc()) {
    $sheet->setCellValue('A' . $row, $data['id']);
    $sheet->setCellValue('B' . $row, $data['course_code']);
    $sheet->setCellValue('C' . $row, $data['course_name']);
    $row++;
}

// 下载
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="课程列表_' . date('Y-m-d') . '.xlsx"');
header('Cache-Control: max-age=0');
$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;