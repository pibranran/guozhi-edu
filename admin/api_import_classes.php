<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\IOFactory;

// 权限验证
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => '无权限']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_FILES['file'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => '请求错误']);
    exit();
}

$file = $_FILES['file'];
$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if (!in_array($ext, ['xlsx', 'xls'])) {
    echo json_encode(['success' => false, 'message' => '仅支持 xlsx/xls']);
    exit();
}

try {
    $spreadsheet = IOFactory::load($file['tmp_name']);
    $sheet = $spreadsheet->getActiveSheet();
    $highestRow = $sheet->getHighestRow();

    $inserted = 0;
    $skipped = 0;

    for ($row = 2; $row <= $highestRow; $row++) {
        // 读取 Excel 列（按你导出的格式）
        $class_id     = trim($sheet->getCell('A' . $row)->getValue() ?? '');
        $class_name   = trim($sheet->getCell('B' . $row)->getValue() ?? '');
        $major_name   = trim($sheet->getCell('C' . $row)->getValue() ?? '');
        $teacher_input = trim($sheet->getCell('D' . $row)->getValue() ?? ''); // 这里存 UUID 或姓名

        if (!$class_id || !$class_name || !$major_name) {
            $skipped++;
            continue;
        }

        // ======================
        // 匹配专业（不变）
        // ======================
        $major_stmt = $conn->prepare("SELECT major_id FROM majors WHERE major_name = ?");
        $major_stmt->bind_param("s", $major_name);
        $major_stmt->execute();
        $major_result = $major_stmt->get_result();
        $major_row = $major_result->fetch_assoc();

        if (!$major_row) {
            $skipped++;
            continue;
        }
        $major_id = $major_row['major_id'];

        // ======================
        // 🔥 核心：教师支持 UUID / 姓名 二选一
        // ======================
        $headmaster_uuid = null;
        if (!empty($teacher_input)) {
            // 先尝试按 UUID 查询
            $t_stmt = $conn->prepare("SELECT uuid FROM teachers WHERE uuid = ? LIMIT 1");
            $t_stmt->bind_param("s", $teacher_input);
            $t_stmt->execute();
            $t_res = $t_stmt->get_result();

            if ($t_res->num_rows > 0) {
                // 找到 UUID → 直接用
                $headmaster_uuid = $t_res->fetch_assoc()['uuid'];
            } else {
                // 没找到 UUID → 按姓名查
                $t_stmt2 = $conn->prepare("SELECT uuid FROM teachers WHERE name = ? LIMIT 1");
                $t_stmt2->bind_param("s", $teacher_input);
                $t_stmt2->execute();
                $t_res2 = $t_stmt2->get_result();

                if ($t_res2->num_rows > 0) {
                    $headmaster_uuid = $t_res2->fetch_assoc()['uuid'];
                }
            }
        }

        // 检查班级是否重复
        $check_stmt = $conn->prepare("SELECT class_id FROM classes WHERE class_id = ?");
        $check_stmt->bind_param("s", $class_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();

        if ($check_result->num_rows > 0) {
            $skipped++;
            continue;
        }

        // 插入班级
        $insert_stmt = $conn->prepare("
            INSERT INTO classes (class_id, class_name, major_id, headmaster_uuid)
            VALUES (?, ?, ?, ?)
        ");
        $insert_stmt->bind_param("ssis", $class_id, $class_name, $major_id, $headmaster_uuid);

        if ($insert_stmt->execute()) {
            $inserted++;
        } else {
            $skipped++;
        }
    }

    echo json_encode([
        'success' => true,
        'inserted' => $inserted,
        'skipped' => $skipped
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}