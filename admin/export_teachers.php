<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../vendor/autoload.php'; // 引入 PhpSpreadsheet

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;

if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'admin') {
    die('无权访问');
}

// 获取教师数据
$sql = "SELECT uuid, name, LastLoginTime FROM teachers ORDER BY uuid ASC";
$result = $conn->query($sql);

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

// 设置表头
$headers = ['工号', '姓名', '最后登录时间'];
$sheet->fromArray($headers, null, 'A1');

// 样式
$sheet->getStyle('A1:C1')->getFont()->setBold(true);
$sheet->getStyle('A1:C1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFEEEEEE');

$row = 2;
while ($rowData = $result->fetch_assoc()) {
    $sheet->setCellValue('A' . $row, $rowData['uuid']);
    $sheet->setCellValue('B' . $row, $rowData['name']);
    $sheet->setCellValue('C' . $row, $rowData['LastLoginTime'] ?? '从未登录');
    $row++;
}

foreach (range('A', 'C') as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="教师名单_'.date('Ymd').'.xlsx"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;