<?php
header("Content-Type: application/json; charset=UTF-8");
require_once __DIR__ . "/../config.php";

// 权限验证
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(["success" => false, "message" => "无权限操作"]);
    exit;
}

$input = file_get_contents("php://input");
$data = json_decode($input, true) ?: $_POST;
$action = $data['action'] ?? '';

// ------------------------------
// 新增课程
// ------------------------------
if ($action === 'add') {
    $course_code = trim($data['course_code']);
    $course_name = trim($data['course_name']);

    if (!$course_code || !$course_name) {
        echo json_encode(["success" => false, "message" => "课程代码和名称不能为空"]);
        exit;
    }

    // 检查课程代码是否重复
    $stmt = $conn->prepare("SELECT id FROM courses WHERE course_code = ?");
    $stmt->bind_param("s", $course_code);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($res->num_rows > 0) {
        echo json_encode(["success" => false, "message" => "课程代码已存在"]);
        exit;
    }

    // 插入（id 自增，不用管）
    $insert = $conn->prepare("INSERT INTO courses (course_code, course_name) VALUES (?, ?)");
    $insert->bind_param("ss", $course_code, $course_name);

    if ($insert->execute()) {
        echo json_encode(["success" => true]);
    } else {
        echo json_encode(["success" => false, "message" => "添加失败：" . $conn->error]);
    }
    exit;
}

// ------------------------------
// 修改课程
// ------------------------------
if ($action === 'update') {
    $id = intval($data['id']);
    $course_code = trim($data['course_code']);
    $course_name = trim($data['course_name']);

    if (!$id || !$course_code || !$course_name) {
        echo json_encode(["success" => false, "message" => "参数不完整"]);
        exit;
    }

    // 检查重复（排除自己）
    $stmt = $conn->prepare("SELECT id FROM courses WHERE course_code = ? AND id != ?");
    $stmt->bind_param("si", $course_code, $id);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($res->num_rows > 0) {
        echo json_encode(["success" => false, "message" => "课程代码已存在"]);
        exit;
    }

    $update = $conn->prepare("UPDATE courses SET course_code=?, course_name=? WHERE id=?");
    $update->bind_param("ssi", $course_code, $course_name, $id);

    if ($update->execute()) {
        echo json_encode(["success" => true]);
    } else {
        echo json_encode(["success" => false, "message" => "修改失败"]);
    }
    exit;
}

// ------------------------------
// 删除单个课程
// ------------------------------
if ($action === 'delete') {
    $id = intval($data['id']);

    if (!$id) {
        echo json_encode(["success" => false, "message" => "缺少课程ID"]);
        exit;
    }

    $del = $conn->prepare("DELETE FROM courses WHERE id=?");
    $del->bind_param("i", $id);

    if ($del->execute()) {
        echo json_encode(["success" => true]);
    } else {
        echo json_encode(["success" => false, "message" => "删除失败，可能存在关联数据"]);
    }
    exit;
}

// ------------------------------
// 批量删除课程
// ------------------------------
if ($action === 'delete_batch') {
    $ids = $data['ids'] ?? [];
    if (!is_array($ids) || count($ids) === 0) {
        echo json_encode(["success" => false, "message" => "未选择课程"]);
        exit;
    }

    $ids = array_map('intval', $ids);
    $in = implode(',', $ids);

    $sql = "DELETE FROM courses WHERE id IN ($in)";
    if ($conn->query($sql)) {
        echo json_encode(["success" => true]);
    } else {
        echo json_encode(["success" => false, "message" => "批量删除失败"]);
    }
    exit;
}

// ------------------------------
// 无效 action
// ------------------------------
echo json_encode(["success" => false, "message" => "无效操作"]);