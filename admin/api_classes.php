<?php
require_once __DIR__ . '/../config.php';

// 权限验证
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => '未授权的访问']);
    exit();
}

// 强制读取 JSON 输入
$input = json_decode(file_get_contents('php://input'), true);
if (json_last_error() !== JSON_ERROR_NONE) {
    echo json_encode(['success' => false, 'message' => '请求格式错误，请检查 JSON 格式']);
    exit();
}

if (!isset($input['action'])) {
    echo json_encode(['success' => false, 'message' => '缺少 action 参数']);
    exit();
}

$action = $input['action'];

switch ($action) {
    case 'add':
        addClass($input);
        break;
    case 'update':
        updateClass($input);
        break;
    case 'delete':
        deleteClass($input);
        break;
    case 'delete_batch':
        deleteBatchClasses($input);
        break;
    default:
        echo json_encode(['success' => false, 'message' => '无效的 action']);
}
exit();

// 新增班级
function addClass($data) {
    global $conn;
    $class_id = trim($data['class_id'] ?? '');
    $class_name = trim($data['class_name'] ?? '');
    $major_id = !empty($data['major_id']) ? $data['major_id'] : null;
    $headmaster_uuid = $data['headmaster_uuid'] ?? null;

    if (empty($class_id) || empty($class_name) || empty($major_id)) {
        echo json_encode(['success' => false, 'message' => '班级编号、名称和所属专业不能为空']);
        return;
    }

    $check = $conn->prepare("SELECT class_id FROM classes WHERE class_id = ?");
    $check->bind_param("s", $class_id);
    $check->execute();
    if ($check->get_result()->num_rows > 0) {
        echo json_encode(['success' => false, 'message' => "班级编号 $class_id 已存在"]);
        return;
    }

    $stmt = $conn->prepare("INSERT INTO classes (class_id, class_name, major_id, headmaster_uuid) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("ssis", $class_id, $class_name, $major_id, $headmaster_uuid);
    if ($stmt->execute()) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => $conn->error]);
    }
}

// 更新班级
function updateClass($data) {
    global $conn;
    $class_id = trim($data['class_id'] ?? '');
    $class_name = trim($data['class_name'] ?? '');
    $major_id = !empty($data['major_id']) ? $data['major_id'] : null;
    $headmaster_uuid = $data['headmaster_uuid'] ?? null;
    $old_class_id = $data['old_class_id'] ?? $class_id;

    if (empty($class_id) || empty($class_name) || empty($major_id)) {
        echo json_encode(['success' => false, 'message' => '班级编号、名称和所属专业不能为空']);
        return;
    }

    if ($class_id !== $old_class_id) {
        $check = $conn->prepare("SELECT class_id FROM classes WHERE class_id = ? AND class_id != ?");
        $check->bind_param("ss", $class_id, $old_class_id);
        $check->execute();
        if ($check->get_result()->num_rows > 0) {
            echo json_encode(['success' => false, 'message' => "班级编号 $class_id 已被占用"]);
            return;
        }
    }

    $stmt = $conn->prepare("UPDATE classes SET class_id = ?, class_name = ?, major_id = ?, headmaster_uuid = ? WHERE class_id = ?");
    $stmt->bind_param("ssiss", $class_id, $class_name, $major_id, $headmaster_uuid, $old_class_id);
    if ($stmt->execute()) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => $conn->error]);
    }
}

// 删除单个班级（修复外键检查 + 错误提示）
function deleteClass($data) {
    global $conn;
    $class_id = $data['class_id'] ?? '';
    if (empty($class_id)) {
        echo json_encode(['success' => false, 'message' => '班级编号不能为空']);
        return;
    }

    // 检查是否有关联学生
    $check_student = $conn->prepare("SELECT uuid FROM students WHERE class_id = ? LIMIT 1");
    $check_student->bind_param("s", $class_id);
    $check_student->execute();
    if ($check_student->get_result()->num_rows > 0) {
        echo json_encode(['success' => false, 'message' => '删除失败：该班级下仍有学生，请先处理学生数据']);
        return;
    }

    // 检查是否有关联排课
    $check_schedule = $conn->prepare("SELECT id FROM schedule WHERE class_id = ? LIMIT 1");
    $check_schedule->bind_param("s", $class_id);
    $check_schedule->execute();
    if ($check_schedule->get_result()->num_rows > 0) {
        echo json_encode(['success' => false, 'message' => '删除失败：该班级存在关联排课记录，请先删除排课']);
        return;
    }

    $stmt = $conn->prepare("DELETE FROM classes WHERE class_id = ?");
    $stmt->bind_param("s", $class_id);
    if ($stmt->execute()) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => '数据库错误：' . $conn->error]);
    }
}

// 批量删除班级
function deleteBatchClasses($data) {
    global $conn;
    $class_ids = $data['class_ids'] ?? [];
    if (!is_array($class_ids) || count($class_ids) === 0) {
        echo json_encode(['success' => false, 'message' => '请选择要删除的班级']);
        return;
    }

    $placeholders = implode(',', array_fill(0, count($class_ids), '?'));
    $types = str_repeat('s', count($class_ids));

    // 检查关联学生
    $check_student = $conn->prepare("SELECT class_id FROM students WHERE class_id IN ($placeholders) LIMIT 1");
    $check_student->bind_param($types, ...$class_ids);
    $check_student->execute();
    if ($check_student->get_result()->num_rows > 0) {
        echo json_encode(['success' => false, 'message' => '删除失败：部分班级下仍有学生']);
        return;
    }

    // 检查关联排课
    $check_schedule = $conn->prepare("SELECT id FROM schedule WHERE class_id IN ($placeholders) LIMIT 1");
    $check_schedule->bind_param($types, ...$class_ids);
    $check_schedule->execute();
    if ($check_schedule->get_result()->num_rows > 0) {
        echo json_encode(['success' => false, 'message' => '删除失败：部分班级存在关联排课']);
        return;
    }

    $stmt = $conn->prepare("DELETE FROM classes WHERE class_id IN ($placeholders)");
    $stmt->bind_param($types, ...$class_ids);
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'deleted' => $stmt->affected_rows]);
    } else {
        echo json_encode(['success' => false, 'message' => '数据库错误：' . $conn->error]);
    }
}
?>