<?php
header("Content-Type: application/json; charset=UTF-8");
require_once __DIR__ . "/../config.php";
require_once __DIR__ . "/../vendor/autoload.php";

use PhpOffice\PhpSpreadsheet\IOFactory;

// 1. 权限验证
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(["success" => false, "message" => "无权限操作"]);
    exit;
}

// 2. 校验文件上传
if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(["success" => false, "message" => "文件上传失败"]);
    exit;
}

$file = $_FILES['file']['tmp_name'];
$allowedExts = ['xlsx', 'xls'];
$fileExt = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));

// 3. 校验文件格式
if (!in_array($fileExt, $allowedExts)) {
    echo json_encode(["success" => false, "message" => "仅支持 .xlsx / .xls 格式文件"]);
    exit;
}

try {
    // 4. 读取Excel文件
    $spreadsheet = IOFactory::load($file);
    $worksheet = $spreadsheet->getActiveSheet();
    $highestRow = $worksheet->getHighestRow(); // 获取总行数
    $inserted = 0; // 成功插入数
    $skipped = 0;  // 跳过（重复）数

    // 5. 遍历Excel数据（从第2行开始，第1行是表头）
    for ($row = 2; $row <= $highestRow; $row++) {
        // 读取单元格数据（B列=课程代码，C列=课程名称；A列是课程ID，导入时忽略）
        $courseCode = trim($worksheet->getCell('B' . $row)->getValue() ?? '');
        $courseName = trim($worksheet->getCell('C' . $row)->getValue() ?? '');

        // 跳过空行
        if (empty($courseCode) || empty($courseName)) {
            $skipped++;
            continue;
        }

        // 6. 校验课程代码是否已存在
        $checkStmt = $conn->prepare("SELECT id FROM courses WHERE course_code = ?");
        $checkStmt->bind_param("s", $courseCode);
        $checkStmt->execute();
        $checkRes = $checkStmt->get_result();

        if ($checkRes->num_rows > 0) {
            // 代码重复，跳过
            $skipped++;
            continue;
        }

        // 7. 插入新课程
        $insertStmt = $conn->prepare("INSERT INTO courses (course_code, course_name) VALUES (?, ?)");
        $insertStmt->bind_param("ss", $courseCode, $courseName);
        
        if ($insertStmt->execute()) {
            $inserted++;
        } else {
            $skipped++;
        }
    }

    // 8. 返回导入结果
    echo json_encode([
        "success" => true,
        "inserted" => $inserted,
        "skipped" => $skipped,
        "message" => "导入完成"
    ]);

} catch (Exception $e) {
    // 捕获Excel读取/处理异常
    echo json_encode(["success" => false, "message" => "文件解析失败：" . $e->getMessage()]);
}

// 关闭数据库连接（可选，根据config.php是否自动关闭）
$conn->close();
exit;
?>