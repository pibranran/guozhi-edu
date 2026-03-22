<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../vendor/autoload.php'; // 引入 PhpSpreadsheet

use PhpOffice\PhpSpreadsheet\IOFactory;

// 权限验证
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => '未授权']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_FILES['file'])) {
    echo json_encode(['success' => false, 'message' => '请上传文件']);
    exit;
}

$file = $_FILES['file'];
if ($file['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'message' => '文件上传失败']);
    exit;
}

// 验证文件类型
$allowed = ['xlsx', 'xls'];
$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if (!in_array($ext, $allowed)) {
    echo json_encode(['success' => false, 'message' => '仅支持 .xlsx 或 .xls 文件']);
    exit;
}

try {
    $spreadsheet = IOFactory::load($file['tmp_name']);
    $sheet = $spreadsheet->getActiveSheet();
    $rows = $sheet->toArray();

    // 去除空行和表头
    array_shift($rows); // 移除表头行
    $rows = array_filter($rows, function($row) {
        return !empty(array_filter($row));
    });

    if (empty($rows)) {
        echo json_encode(['success' => false, 'message' => '文件无有效数据']);
        exit;
    }

    // 获取班级映射（班级名 -> class_id）
    $classMap = [];
    $classResult = $conn->query("SELECT class_id, class_name FROM classes");
    while ($c = $classResult->fetch_assoc()) {
        $classMap[$c['class_name']] = $c['class_id'];
    }

    $inserted = 0;
    $skipped = 0;

    // 准备批量插入语句
    $stmt = $conn->prepare("INSERT INTO students (uuid, name, class_id) VALUES (?, ?, ?)");
    if (!$stmt) {
        throw new Exception('数据库预处理失败');
    }

    foreach ($rows as $row) {
        // 假设 Excel 列：A=学号，B=姓名，C=班级
        $uuid = trim($row[0] ?? '');
        $name = trim($row[1] ?? '');
        $className = trim($row[2] ?? '');

        if ($uuid === '' || $name === '') {
            $skipped++;
            continue; // 学号或姓名不能为空
        }

        // 检查学号是否已存在
        $check = $conn->prepare("SELECT uuid FROM students WHERE uuid = ?");
        $check->bind_param("s", $uuid);
        $check->execute();
        if ($check->get_result()->num_rows > 0) {
            $skipped++;
            continue; // 学号重复，跳过
        }

        $classId = isset($classMap[$className]) ? $classMap[$className] : null;
        $stmt->bind_param("sss", $uuid, $name, $classId);
        if ($stmt->execute()) {
            $inserted++;
        } else {
            $skipped++;
        }
    }

    echo json_encode(['success' => true, 'inserted' => $inserted, 'skipped' => $skipped]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => '处理失败：' . $e->getMessage()]);
}