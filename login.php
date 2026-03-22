<?php
require_once "config.php";

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: index.php");
    exit();
}

$role   = $_POST['role'] ?? '';
$uuid   = trim($_POST['UUID'] ?? '');
$passwd = $_POST['passwd'] ?? '';

if (empty($uuid) || empty($passwd) || !in_array($role, ['student', 'teacher', 'admin'])) {
    $_SESSION['error_msg'] = '参数错误，请重新登录';
    header("Location: index.php");
    exit();
}

// 🔥 兼容旧版 PHP：用 if-elseif 代替 match()
if ($role === 'student') {
    $table = 'students';
} elseif ($role === 'teacher') {
    $table = 'teachers';
} elseif ($role === 'admin') {
    $table = 'admin';
} else {
    $table = null;
}

if (!$table) {
    $_SESSION['error_msg'] = '角色错误';
    header("Location: index.php");
    exit();
}

// 🔥 关键：全部使用字符串绑定 "s"（适配 VARCHAR uuid）
$stmt = $conn->prepare("SELECT uuid, name, passwd FROM `$table` WHERE uuid = ?");
$stmt->bind_param("s", $uuid);
$stmt->execute();
$result = $stmt->get_result();

if ($row = $result->fetch_assoc()) {
    if (password_verify($passwd, $row['passwd'])) {
        // 登录成功
        $_SESSION['logged_in'] = true;
        $_SESSION['uuid']      = $row['uuid'];
        $_SESSION['role']      = $role;
        $_SESSION['name']      = $row['name'] ?? '未知用户';

        // 更新最后登录时间
        $update = $conn->prepare("UPDATE `$table` SET LastLoginTime = NOW() WHERE uuid = ?");
        $update->bind_param("s", $uuid);
        $update->execute();

        if($role === 'admin') {
            header("Location: /admin/admin_students.php");
            exit();
        }

        header("Location: dashboard.php");
        exit();
    }
}

// 登录失败
$_SESSION['error_msg'] = '学号/工号或密码错误';
header("Location: index.php");
exit();
?>