<?php
require_once __DIR__ . '/../config.php';

// 1. 权限验证
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

// 2. 获取输入数据
$input = json_decode(file_get_contents('php://input'), true);
if (!$input && $_POST) {
    $input = $_POST;
}

if (!isset($input['action'])) {
    echo json_encode(['success' => false, 'message' => '缺少 action 参数']);
    exit();
}

$action = $input['action'];

// 3. 路由分发
switch ($action) {
    case 'add':
        addTeacher($input);
        break;
    case 'update':
        updateTeacher($input);
        break;
    case 'delete':
        deleteTeacher($input);
        break;
    case 'delete_batch':
        deleteBatchTeachers($input);
        break;
    case 'reset_password':
        resetPassword($input);
        break;
    case 'reset_password_batch':
        resetBatchPasswords($input);
        break;
    default:
        echo json_encode(['success' => false, 'message' => '未知操作: ' . $action]);
}

/**
 * 新增教师
 */
function addTeacher($data) {
    global $conn;
    $uuid = trim($data['uuid'] ?? '');
    $name = trim($data['name'] ?? '');

    if (empty($uuid) || empty($name)) {
        echo json_encode(['success' => false, 'message' => '工号和姓名不能为空']);
        return;
    }

    $stmt = $conn->prepare("INSERT INTO teachers (uuid, name) VALUES (?, ?)");
    $stmt->bind_param("ss", $uuid, $name);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => '添加失败，工号可能已存在']);
    }
}

/**
 * 更新教师信息
 */
function updateTeacher($data) {
    global $conn;
    $new_uuid = trim($data['uuid'] ?? '');
    $old_uuid = trim($data['old_uuid'] ?? '');
    $name = trim($data['name'] ?? '');

    if (empty($new_uuid) || empty($name) || empty($old_uuid)) {
        echo json_encode(['success' => false, 'message' => '必填项不能为空']);
        return;
    }

    $stmt = $conn->prepare("UPDATE teachers SET uuid = ?, name = ? WHERE uuid = ?");
    $stmt->bind_param("sss", $new_uuid, $name, $old_uuid);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => $conn->error]);
    }
}

/**
 * 删除单个教师（含职务清理）
 */
function deleteTeacher($data) {
    global $conn;
    $uuid = $data['uuid'] ?? '';

    if (empty($uuid)) {
        echo json_encode(['success' => false, 'message' => '工号不能为空']);
        return;
    }

    // 开启事务处理关联数据
    $conn->begin_transaction();
    try {
        // 解除专业院长职务
        $conn->query("UPDATE majors SET dean_uuid = NULL WHERE dean_uuid = '$uuid'");
        // 解除班主任职务
        $conn->query("UPDATE classes SET headmaster_uuid = NULL WHERE headmaster_uuid = '$uuid'");
        
        // 删除教师主体
        $stmt = $conn->prepare("DELETE FROM teachers WHERE uuid = ?");
        $stmt->bind_param("s", $uuid);
        $stmt->execute();

        $conn->commit();
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => '删除失败: ' . $e->getMessage()]);
    }
}

/**
 * 批量删除教师
 */
function deleteBatchTeachers($data) {
    global $conn;
    $uuids = $data['uuids'] ?? [];

    if (!is_array($uuids) || count($uuids) === 0) {
        echo json_encode(['success' => false, 'message' => '未选择任何教师']);
        return;
    }

    $conn->begin_transaction();
    try {
        foreach ($uuids as $uuid) {
            $u = $conn->real_escape_string($uuid);
            $conn->query("UPDATE majors SET dean_uuid = NULL WHERE dean_uuid = '$u'");
            $conn->query("UPDATE classes SET headmaster_uuid = NULL WHERE headmaster_uuid = '$u'");
        }

        $placeholders = implode(',', array_fill(0, count($uuids), '?'));
        $stmt = $conn->prepare("DELETE FROM teachers WHERE uuid IN ($placeholders)");
        $stmt->bind_param(str_repeat('s', count($uuids)), ...$uuids);
        $stmt->execute();

        $conn->commit();
        echo json_encode(['success' => true, 'deleted' => $stmt->affected_rows]);
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => '批量删除失败']);
    }
}

/**
 * 重置单个教师密码
 */
function resetPassword($data) {
    global $conn;
    $uuid = $data['uuid'] ?? '';
    // 默认初始密码 Hash
    $pwd = '$2y$10$55cONnGdX6beGToMZtDsC.FzyZTxp24W0P9nNE0nZAQP/d4Aua6US'; 

    $stmt = $conn->prepare("UPDATE teachers SET passwd = ? WHERE uuid = ?");
    $stmt->bind_param("ss", $pwd, $uuid);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => '重置失败']);
    }
}

/**
 * 批量重置教师密码
 */
function resetBatchPasswords($data) {
    global $conn;
    $uuids = $data['uuids'] ?? [];

    if (!is_array($uuids) || count($uuids) === 0) {
        echo json_encode(['success' => false, 'message' => '未选择任何教师']);
        return;
    }

    $pwd = '$2y$10$55cONnGdX6beGToMZtDsC.FzyZTxp24W0P9nNE0nZAQP/d4Aua6US';
    $placeholders = implode(',', array_fill(0, count($uuids), '?'));
    
    $sql = "UPDATE teachers SET passwd = ? WHERE uuid IN ($placeholders)";
    $stmt = $conn->prepare($sql);
    
    $params = array_merge([$pwd], $uuids);
    $types = 's' . str_repeat('s', count($uuids));
    
    $stmt->bind_param($types, ...$params);

    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'updated' => $stmt->affected_rows]);
    } else {
        echo json_encode(['success' => false, 'message' => $conn->error]);
    }
}