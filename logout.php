<?php
    require 'config.php';

    // 判断有没有sesion
    if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    $_SESSION['error_msg'] = "请重新登录";
    header("Location: index.php");
    exit();
    }

    // 清空数据
    $_SESSION = array();
    
    // 销毁文件
    session_destroy();

    header("Location: /index.php");
    exit();
?>