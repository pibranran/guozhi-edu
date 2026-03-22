<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

header('Content-Type: application/json; charset=utf-8'); // 防止任何 PHP 警告破坏 JSON

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
    array_shift($rows); // 去表头
    $rows = array_filter($rows, fn($r) => !empty(array_filter($r)));

    $inserted = 0; $skipped = 0;

    $stmt = $conn->prepare("INSERT INTO majors (major_id, major_name, dean_uuid) VALUES (?, ?, ?)");

    foreach ($rows as $row) {
        $major_id   = (int)trim($row[0] ?? 0);
        $major_name = trim($row[1] ?? '');
        $dean_uuid  = trim($row[2] ?? '');   // ← 第3列必须是 专业主任UUID（可为空）

        if ($major_id <= 0 || $major_name === '') {
            $skipped++;
            continue;
        }

        // 检查重复ID...
        $check = $conn->prepare("SELECT major_id FROM majors WHERE major_id = ?");
        $check->bind_param("i", $major_id);
        $check->execute();
        if ($check->get_result()->num_rows > 0) {
            $skipped++;
            continue;
        }

        $stmt->bind_param("iss", $major_id, $major_name, $dean_uuid ?: null);
        if ($stmt->execute()) $inserted++; else $skipped++;
    }

    echo json_encode(['success' => true, 'inserted' => $inserted, 'skipped' => $skipped]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => '处理失败：' . $e->getMessage()]);
}
?>