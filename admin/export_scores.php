<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../vendor/autoload.php'; 

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;

// 1. 权限验证
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'admin') {
    die('无权访问');
}

$sch_id = isset($_GET['sch_id']) ? (int)$_GET['sch_id'] : 0;
if (!$sch_id) die('参数错误');

// 2. 获取课程基本信息用于文件名
$info_sql = "SELECT c.class_name, co.course_name 
             FROM schedule s 
             JOIN classes c ON s.class_id = c.class_id 
             JOIN courses co ON s.course_id = co.course_id 
             WHERE s.id = ?";
$info_stmt = $conn->prepare($info_sql);
$info_stmt->bind_param("i", $sch_id);
$info_stmt->execute();
$info = $info_stmt->get_result()->fetch_assoc();
$fileNamePrefix = ($info['class_name'] ?? '未知班级') . "_" . ($info['course_name'] ?? '课程');

// 3. 获取该课程下的所有学生及成绩
$sql = "SELECT st.uuid, st.name, sc.daily_score, sc.mid_score, sc.final_score, sc.total_score, sc.resit_score 
        FROM scores sc 
        JOIN students st ON sc.student_uuid = st.uuid 
        WHERE sc.schedule_id = ? 
        ORDER BY st.uuid ASC";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $sch_id);
$stmt->execute();
$result = $stmt->get_result();

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

// 4. 设置表头
$headers = ['学号', '姓名', '平时成绩', '期中成绩', '期末成绩', '总评成绩', '补考成绩'];
$sheet->fromArray($headers, null, 'A1');

// 表头样式：加粗 + 背景色
$sheet->getStyle('A1:G1')->getFont()->setBold(true);
$sheet->getStyle('A1:G1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFE0E7FF'); // 浅蓝色

// 5. 填充数据
$row = 2;
while ($r = $result->fetch_assoc()) {
    $sheet->setCellValue('A' . $row, $r['uuid']);
    $sheet->setCellValue('B' . $row, $r['name']);
    $sheet->setCellValue('C' . $row, $r['daily_score']);
    $sheet->setCellValue('D' . $row, $r['mid_score']);
    $sheet->setCellValue('E' . $row, $r['final_score']);
    $sheet->setCellValue('F' . $row, $r['total_score']);
    $sheet->setCellValue('G' . $row, $r['resit_score']);
    $row++;
}

// 自动调整列宽
foreach (range('A', 'G') as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}

// 6. 输出 Excel 文件
$writer = new Xlsx($spreadsheet);
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $fileNamePrefix . '_成绩单_' . date('YmdHi') . '.xlsx"');
$writer->save('php://output');
exit;