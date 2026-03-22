<?php
require_once __DIR__ . '/../config.php';

// 权限验证
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => '未授权的访问']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => '仅支持 POST 请求']);
    exit();
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input && $_POST) {
    $input = $_POST;
}

if (!isset($input['action'])) {
    echo json_encode(['success' => false, 'message' => '缺少 action 参数']);
    exit();
}

$action = $input['action'];

switch ($action) {
    case 'add':
        addStudent($input);
        break;
    case 'update':
        updateStudent($input);
        break;
    case 'delete':
        deleteStudent($input);
        break;
    case 'delete_batch':
        deleteBatchStudents($input);
        break;
    case 'reset_password':
        resetPassword($input);
        break;
    case 'reset_password_batch':
        resetBatchPasswords($input);
        break;
    default:
        echo json_encode(['success' => false, 'message' => '无效的 action']);
}
exit();

// ---------- 功能函数 ----------
function addStudent($data) {
    global $conn;
    $uuid = trim($data['uuid'] ?? '');
    $name = trim($data['name'] ?? '');
    $class_id = !empty($data['class_id']) ? $data['class_id'] : null;

    if (empty($uuid) || empty($name)) {
        echo json_encode(['success' => false, 'message' => '学号和姓名不能为空']);
        return;
    }

    // 检查学号是否已存在
    $check = $conn->prepare("SELECT uuid FROM students WHERE uuid = ?");
    $check->bind_param("s", $uuid);
    $check->execute();
    if ($check->get_result()->num_rows > 0) {
        echo json_encode(['success' => false, 'message' => "学号 $uuid 已存在"]);
        return;
    }

    // 插入学生，密码默认为 DEFAULT_PASSWORD
    $pwd = DEFAULT_PASSWORD;
    $stmt = $conn->prepare("INSERT INTO students (uuid, name, class_id, passwd) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("ssss", $uuid, $name, $class_id, $pwd);
    if ($stmt->execute()) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => $conn->error]);
    }
}

function updateStudent($data) {
    global $conn;
    $uuid = $data['uuid'] ?? '';
    $name = trim($data['name'] ?? '');
    $class_id = !empty($data['class_id']) ? $data['class_id'] : null;
    $old_uuid = $data['old_uuid'] ?? $uuid;

    if (empty($uuid) || empty($name)) {
        echo json_encode(['success' => false, 'message' => '学号和姓名不能为空']);
        return;
    }

    if ($uuid !== $old_uuid) {
        $check = $conn->prepare("SELECT uuid FROM students WHERE uuid = ? AND uuid != ?");
        $check->bind_param("ss", $uuid, $old_uuid);
        $check->execute();
        if ($check->get_result()->num_rows > 0) {
            echo json_encode(['success' => false, 'message' => "学号 $uuid 已被占用"]);
            return;
        }
    }

    $stmt = $conn->prepare("UPDATE students SET uuid = ?, name = ?, class_id = ? WHERE uuid = ?");
    $stmt->bind_param("ssss", $uuid, $name, $class_id, $old_uuid);
    if ($stmt->execute()) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => $conn->error]);
    }
}

function deleteStudent($data) {
    global $conn;
    $uuid = $data['uuid'] ?? '';
    if (empty($uuid)) {
        echo json_encode(['success' => false, 'message' => '学号不能为空']);
        return;
    }
    $stmt = $conn->prepare("DELETE FROM students WHERE uuid = ?");
    $stmt->bind_param("s", $uuid);
    if ($stmt->execute()) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => $conn->error]);
    }
}

function deleteBatchStudents($data) {
    global $conn;
    $uuids = $data['uuids'] ?? [];
    if (!is_array($uuids) || count($uuids) === 0) {
        echo json_encode(['success' => false, 'message' => '请选择要删除的学生']);
        return;
    }
    $placeholders = implode(',', array_fill(0, count($uuids), '?'));
    $types = str_repeat('s', count($uuids));
    $stmt = $conn->prepare("DELETE FROM students WHERE uuid IN ($placeholders)");
    $stmt->bind_param($types, ...$uuids);
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'deleted' => $stmt->affected_rows]);
    } else {
        echo json_encode(['success' => false, 'message' => $conn->error]);
    }
}

function resetPassword($data) {
    global $conn;
    $uuid = $data['uuid'] ?? '';
    if (empty($uuid)) {
        echo json_encode(['success' => false, 'message' => '学号不能为空']);
        return;
    }
    $pwd = DEFAULT_PASSWORD;
    $stmt = $conn->prepare("UPDATE students SET passwd = ? WHERE uuid = ?");
    $stmt->bind_param("ss", $pwd, $uuid);
    if ($stmt->execute()) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => $conn->error]);
    }
}

function resetBatchPasswords($data) {
    global $conn;
    $uuids = $data['uuids'] ?? [];
    if (!is_array($uuids) || count($uuids) === 0) {
        echo json_encode(['success' => false, 'message' => '请选择学生']);
        return;
    }
    $placeholders = implode(',', array_fill(0, count($uuids), '?'));
    $types = str_repeat('s', count($uuids));
    $pwd = DEFAULT_PASSWORD;
    $sql = "UPDATE students SET passwd = ? WHERE uuid IN ($placeholders)";
    $stmt = $conn->prepare($sql);
    $params = array_merge([$pwd], $uuids);
    $stmt->bind_param("s" . $types, ...$params);
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'reset_count' => $stmt->affected_rows]);
    } else {
        echo json_encode(['success' => false, 'message' => $conn->error]);
    }
}
?>