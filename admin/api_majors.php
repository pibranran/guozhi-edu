<?php
ob_start();
header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', 0);
error_reporting(0);

require_once __DIR__ . '/../config.php';

// 权限验证
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'admin') {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => '未授权的访问']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => '仅支持 POST 请求']);
    exit();
}

// 接收输入
$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$action = $input['action'] ?? '';

switch ($action) {
    case 'add':              addMajor($input); break;
    case 'update':           updateMajor($input); break;
    case 'delete':           deleteMajor($input); break;
    case 'delete_batch':     deleteBatchMajors($input); break;
    case 'reset_password':   resetDeanPassword($input); break;
    case 'reset_password_batch': resetBatchDeanPasswords($input); break;
    default:
        echo json_encode(['success' => false, 'message' => '无效的 action']);
}
exit();

// ====================== 业务逻辑函数 ======================

function addMajor($data) {
    global $conn;
    $major_id = (int)($data['major_id'] ?? 0);
    $major_name = trim($data['major_name'] ?? '');
    $dean_uuid = !empty($data['dean_uuid']) ? $data['dean_uuid'] : null;

    if ($major_id <= 0 || empty($major_name)) {
        echo json_encode(['success' => false, 'message' => '编号和名称不能为空']);
        return;
    }

    $stmt = $conn->prepare("INSERT INTO majors (major_id, major_name, dean_uuid) VALUES (?, ?, ?)");
    $stmt->bind_param("iss", $major_id, $major_name, $dean_uuid);
    
    echo json_encode($stmt->execute() 
        ? ['success' => true, 'message' => '添加成功']
        : ['success' => false, 'message' => '添加失败: ' . $conn->error]
    );
}

function updateMajor($data) {
    global $conn;
    $old_id = (int)($data['old_id'] ?? 0);
    $new_id = (int)($data['major_id'] ?? 0);
    $name = trim($data['major_name'] ?? '');
    $dean_uuid = !empty($data['dean_uuid']) ? $data['dean_uuid'] : null;

    if ($old_id <= 0 || $new_id <= 0 || empty($name)) {
        echo json_encode(['success' => false, 'message' => '参数不完整']);
        return;
    }

    // 更新专业表信息
    $stmt = $conn->prepare("UPDATE majors SET major_id = ?, major_name = ?, dean_uuid = ? WHERE major_id = ?");
    $stmt->bind_param("issi", $new_id, $name, $dean_uuid, $old_id);
    
    echo json_encode($stmt->execute() 
        ? ['success' => true, 'message' => '更新成功']
        : ['success' => false, 'message' => '更新失败: ' . $conn->error]
    );
}

function deleteMajor($data) {
    global $conn;
    $major_id = (int)($data['major_id'] ?? 0);
    if ($major_id <= 0) {
        echo json_encode(['success' => false, 'message' => '无效的专业ID']);
        return;
    }

    $stmt = $conn->prepare("DELETE FROM majors WHERE major_id = ?");
    $stmt->bind_param("i", $major_id);
    
    echo json_encode($stmt->execute() 
        ? ['success' => true, 'message' => '删除成功']
        : ['success' => false, 'message' => '删除失败: ' . $conn->error]
    );
}

function deleteBatchMajors($data) {
    global $conn;
    $ids = $data['major_ids'] ?? [];
    if (empty($ids)) {
        echo json_encode(['success' => false, 'message' => '未选择任何项']);
        return;
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $conn->prepare("DELETE FROM majors WHERE major_id IN ($placeholders)");
    $stmt->bind_param(str_repeat('i', count($ids)), ...$ids);
    
    $success = $stmt->execute();
    echo json_encode([
        'success' => $success,
        'message' => $success ? '批量删除成功' : '删除失败: ' . $conn->error
    ]);
}

function resetDeanPassword($data) {
    global $conn;
    $major_id = (int)($data['major_id'] ?? 0);
    if ($major_id <= 0) {
        echo json_encode(['success' => false, 'message' => '专业ID不能为空']);
        return;
    }

    $stmt = $conn->prepare("SELECT dean_uuid FROM majors WHERE major_id = ?");
    $stmt->bind_param("i", $major_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();

    if (!$row || empty($row['dean_uuid'])) {
        echo json_encode(['success' => false, 'message' => '该专业未绑定专业主任']);
        return;
    }

    $dean_uuid = $row['dean_uuid'];
    $pwd = DEFAULT_PASSWORD; // 调用 config.php 定义的常量

    $reset = $conn->prepare("UPDATE teachers SET passwd = ? WHERE uuid = ?");
    $reset->bind_param("ss", $pwd, $dean_uuid);
    
    echo json_encode($reset->execute() 
        ? ['success' => true, 'message' => '专业主任密码已重置']
        : ['success' => false, 'message' => $conn->error]
    );
}

function resetBatchDeanPasswords($data) {
    global $conn;
    $major_ids = $data['major_ids'] ?? [];
    if (empty($major_ids)) {
        echo json_encode(['success' => false, 'message' => '请选择专业']);
        return;
    }

    $placeholders = implode(',', array_fill(0, count($major_ids), '?'));
    $stmt = $conn->prepare("SELECT dean_uuid FROM majors WHERE major_id IN ($placeholders)");
    $stmt->bind_param(str_repeat('i', count($major_ids)), ...$major_ids);
    $stmt->execute();
    $result = $stmt->get_result();

    $dean_uuids = [];
    while ($row = $result->fetch_assoc()) {
        if (!empty($row['dean_uuid'])) $dean_uuids[] = $row['dean_uuid'];
    }

    if (empty($dean_uuids)) {
        echo json_encode(['success' => false, 'message' => '选中的专业都没有绑定主任']);
        return;
    }

    $placeholders2 = implode(',', array_fill(0, count($dean_uuids), '?'));
    $pwd = DEFAULT_PASSWORD;
    $reset = $conn->prepare("UPDATE teachers SET passwd = ? WHERE uuid IN ($placeholders2)");
    $params = array_merge([$pwd], $dean_uuids);
    $reset->bind_param("s" . str_repeat('s', count($dean_uuids)), ...$params);

    $success = $reset->execute();
    echo json_encode([
        'success' => $success,
        'message' => $success ? '已重置 ' . count($dean_uuids) . ' 位主任的密码' : $conn->error
    ]);
}
?>