<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;

// 权限验证
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'admin') {
    die('无权访问');
}

// 获取专业数据（关联主任姓名）
$sql = "SELECT m.major_id, m.major_name, COALESCE(t.name, '') as dean_name 
        FROM majors m 
        LEFT JOIN teachers t ON m.dean_uuid = t.uuid 
        ORDER BY m.major_id ASC";
$result = $conn->query($sql);

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

// 设置表头
$headers = ['专业ID', '专业名称', '主任姓名'];
$sheet->fromArray($headers, null, 'A1');

// 样式
$sheet->getStyle('A1:C1')->getFont()->setBold(true);
$sheet->getStyle('A1:C1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFDDDDDD');

// 填充数据
$row = 2;
while ($rowData = $result->fetch_assoc()) {
    $sheet->setCellValue('A' . $row, $rowData['major_id']);
    $sheet->setCellValue('B' . $row, $rowData['major_name']);
    $sheet->setCellValue('C' . $row, $rowData['dean_name']);
    $row++;
}

// 自动调整列宽
foreach (range('A', 'C') as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}

// 输出文件
$writer = new Xlsx($spreadsheet);
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="majors_' . date('YmdHis') . '.xlsx"');
$writer->save('php://output');
exit;
?>