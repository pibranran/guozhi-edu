<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

header('Content-Type: application/json');

if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => '未授权']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_FILES['file'])) {
    echo json_encode(['success' => false, 'message' => '请上传文件']);
    exit;
}

$file = $_FILES['file'];
try {
    $spreadsheet = IOFactory::load($file['tmp_name']);
    $sheet = $spreadsheet->getActiveSheet();
    $rows = $sheet->toArray();

    array_shift($rows); // 跳过表头

    $inserted = 0;
    $skipped = 0;

    $stmt = $conn->prepare("INSERT INTO teachers (uuid, name) VALUES (?, ?)");

    foreach ($rows as $row) {
        $uuid = trim($row[0] ?? '');
        $name = trim($row[1] ?? '');

        if ($uuid === '' || $name === '') {
            $skipped++;
            continue;
        }

        // 检查重复
        $check = $conn->prepare("SELECT uuid FROM teachers WHERE uuid = ?");
        $check->bind_param("s", $uuid);
        $check->execute();
        if ($check->get_result()->num_rows > 0) {
            $skipped++;
            continue;
        }

        $stmt->bind_param("ss", $uuid, $name);
        if ($stmt->execute()) {
            $inserted++;
        } else {
            $skipped++;
        }
    }

    echo json_encode([
        'success' => true, 
        'message' => "导入完成。成功: $inserted, 跳过: $skipped"
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Excel 解析失败: ' . $e->getMessage()]);
}