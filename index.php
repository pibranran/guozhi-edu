<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <?php
        require 'config.php';
        if (isset($_SESSION['error_msg'])) {
            echo "<script>alert('" . $_SESSION['error_msg'] . "');</script>";
            unset($_SESSION['error_msg']); // 弹完即焚，防止刷新重复弹窗
        }
        if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
            // 既然已经登录了，直接去后台，别在这儿磨蹭
            // 管理员单独指定
            if ($_SESSION['role'] == 'admin') {
                header("Location: /admin/admin_server.php");
                exit();
            }
            header("Location: dashboard.php");
            exit();
        }
    ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>果汁教务 - 登录 | Campus Elite 教务系统</title>
    <script src="https://cdn.tailwindcss.com/3.4.17"></script>
    <link rel="icon" type="image/png" href="/jiaowu.png">
    <link rel="stylesheet" href="styles.css">
    <script>
        function forgetPasswd()
        {
            alert("请联系管理员");
        }
        function afterUse() {
            alert('测试账户\n账户：20262026\n密码：');
        }
        // afterUse()
    </script>
</head>
<body onload="afterUse()">
    <div class="container">
        <div class="header-section">
            <h1 class="logo-text">
                EDU<span class="logo-highlight">&nbsp;A.M.S</span>
            </h1>
            <p class="system-version">教务管理系统</p>
        </div>

        <div class="login-card">
            <h2 class="login-title">身份验证 / <span class="login-subtitle">Login</span></h2>
            
            <form action="login.php" method="POST">
                <div class="role-selector">
                    <label class="role-option">
                        <input type="radio" name="role" value="student" class="role-input" checked>
                        <div class="role-label">学生</div>
                    </label>
                    <label class="role-option">
                        <input type="radio" name="role" value="teacher" class="role-input">
                        <div class="role-label">教师</div>
                    </label>
                    <label class="role-option">
                        <input type="radio" name="role" value="admin" class="role-input">
                        <div class="role-label">管理员</div>
                    </label>
                </div>

                <div class="input-group">
                    <label class="input-label">学号 / 工号 (UID)</label>
                    <input type="text" placeholder="Enter your ID" class="input-field" id="UUID" name="UUID" required pattern="[0-9]{8}">
                </div>

                <div class="input-group">
                    <label class="input-label">安全密码</label>
                    <input type="password" placeholder="••••••••" class="input-field" id="passwd" name="passwd" required minlength="6">
                </div>

                <div class="login-footer">
                    <input type="button" onclick="forgetPasswd()" value="忘记密码?" class="forgot-password-btn">
                </div>

                <button type="submit" class="login-btn">
                    进入系统
                </button>
            </form>
        </div>

        <div class="footer-section">
            <p class="footer-text">
                果汁教务 | 系统设计：<a href="https://www.ccf.org.cn/Activities/Forum/ccf-svef/wyhdt/zxwy/2021-07-07/732301.shtml" class="designer-link">李辰</span></a>
            </p>
        </div>
    </div>
</body>
</html>
