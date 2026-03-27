<?php
// 💡 防炸核心：确保身份变量随时可用
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 定义三个文件位置常量
define('admin_url', '/admin/');
define('teacher_url', '/../');
define('headmaster_url', '/../');
define('dean_url', '/../');


// 自动获取身份，防止主页面没定义 $role 时报错
$role = $role ?? ($_SESSION['role'] ?? 'guest');
?>

<aside id="sidebar" class="sidebar-transition w-64 bg-slate-900 text-white flex flex-col overflow-y-auto shrink-0 h-screen">
    <div class="sidebar-header p-6 flex items-center justify-between border-b border-slate-800">
        <span class="menu-text text-2xl font-bold italic text-amber-500 tracking-tighter">EDU.AMS</span>
        <button onclick="toggleSidebar()" class="p-1.5 hover:bg-slate-800 rounded-lg text-slate-400 transition-colors">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path>
            </svg>
        </button>
    </div>
    
    <nav class="flex-1 px-4 py-6 space-y-6">
        <?php if ($role === 'admin'): ?>
            <div>
                <p class="role-title px-4 text-[10px] text-slate-500 uppercase tracking-[2px] mb-2 font-bold">系统管理</p>
                <div class="space-y-1 text-sm">
                    <a href="<?= admin_url ?>admin_server.php" class="sidebar-item flex items-center gap-3 px-4 py-2 text-slate-400 hover:text-white hover:bg-slate-800 rounded-lg transition-all">
                        <span>🖥</span><span class="menu-text">服务器概览</span>
                    </a>
                    <a href="<?= admin_url ?>admin_dept.php" class="sidebar-item flex items-center gap-3 px-4 py-2 text-slate-400 hover:text-white hover:bg-slate-800 rounded-lg transition-all">
                        <span>⚙</span><span class="menu-text">专业管理</span>
                    </a>
                    <a href="<?= admin_url ?>admin_teachers.php" class="sidebar-item flex items-center gap-3 px-4 py-2 text-slate-400 hover:text-white hover:bg-slate-800 rounded-lg transition-all">
                        <span>👨‍🏫</span><span class="menu-text">教师管理</span>
                    </a>
                    <a href="<?= admin_url ?>admin_courses.php" class="sidebar-item flex items-center gap-3 px-4 py-2 text-slate-400 hover:text-white hover:bg-slate-800 rounded-lg transition-all">
                        <span>📖</span><span class="menu-text">课程管理</span>
                    </a>
                    <a href="<?= admin_url ?>admin_students.php" class="sidebar-item flex items-center gap-3 px-4 py-2 text-slate-400 hover:text-white hover:bg-slate-800 rounded-lg transition-all">
                        <span>🎓</span><span class="menu-text">学生管理</span>
                    </a>
                    <a href="<?= admin_url ?>admin_score_manage.php" class="sidebar-item flex items-center gap-3 px-4 py-2 text-slate-400 hover:text-white hover:bg-slate-800 rounded-lg transition-all">
                        <span>📝</span><span class="menu-text">成绩管理</span>
                    </a>
                    <a href="<?= admin_url ?>admin_class.php" class="sidebar-item flex items-center gap-3 px-4 py-2 text-slate-400 hover:text-white hover:bg-slate-800 rounded-lg transition-all">
                        <span>🏫</span><span class="menu-text">班级管理</span>
                    </a>
                    <a href="<?= admin_url ?>admin_schedule.php" class="sidebar-item flex items-center gap-3 px-4 py-2 text-slate-400 hover:text-white hover:bg-slate-800 rounded-lg transition-all">
                        <span>📅</span><span class="menu-text">排课管理</span>
                    </a>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($role === 'dean'): ?>
            <div>
                <p class="role-title px-4 text-[10px] text-slate-500 uppercase tracking-[2px] mb-2 font-bold">教务管理</p>
                <div class="space-y-1 text-sm">
                    <a href="all_scores.php" class="sidebar-item flex items-center gap-3 px-4 py-2 text-slate-400 hover:text-white hover:bg-slate-800 rounded-lg transition-all">
                        <span class="text-lg">📊</span><span class="menu-text">全校成绩概况</span>
                    </a>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($role === 'headmaster'): ?>
            <div>
                <p class="role-title px-4 text-[10px] text-slate-500 uppercase tracking-[2px] mb-2 font-bold">班级管理</p>
                <div class="space-y-1 text-sm">
                    <a href="my_class.php" class="sidebar-item flex items-center gap-3 px-4 py-2 text-slate-400 hover:text-white hover:bg-slate-800 rounded-lg transition-all">
                        <span class="text-lg">🏫</span><span class="menu-text">我的班级</span>
                    </a>
                    <a href="class_scores.php" class="sidebar-item flex items-center gap-3 px-4 py-2 text-slate-400 hover:text-white hover:bg-slate-800 rounded-lg transition-all">
                        <span class="text-lg">📈</span><span class="menu-text">班级成绩统计</span>
                    </a>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($role === 'teacher' || $role === 'headmaster'): ?>
            <div>
                <p class="role-title px-4 text-[10px] text-slate-500 uppercase tracking-[2px] mb-2 font-bold">教务业务</p>
                <div class="space-y-1 text-sm">
                    <a href="<?= teacher_url ?>dashboard.php" class="sidebar-item flex items-center gap-3 px-4 py-2 text-slate-400 hover:text-white hover:bg-slate-800 rounded-lg transition-all">
                        <span>📝</span><span class="menu-text">成绩录入</span>
                    </a>
                </div>
            </div>
        <?php endif; ?>
    </nav>

    <a href="/../logout.php" class="sidebar-item p-6 bg-red-900/10 text-red-500 text-center border-t border-slate-800 hover:bg-red-900/20 transition-all flex items-center justify-center gap-3 mt-auto">
        <span class="text-xl">🚪</span><span class="menu-text font-bold">退出系统</span>
    </a>
</aside>

<style>
/* 侧边栏过渡动画 */
.sidebar-transition {
    transition: width 0.2s ease-in-out;
}

/* 折叠状态样式 */
.sidebar-collapsed {
    width: 5rem !important; /* 覆盖默认宽度，仅显示图标区域 */
}

/* 折叠时隐藏所有文字 */
.sidebar-collapsed .menu-text {
    display: none;
}

/* 折叠时隐藏分类标题 */
.sidebar-collapsed .role-title {
    display: none;
}

/* 折叠时菜单项内容居中，移除间距 */
.sidebar-collapsed .sidebar-item {
    justify-content: center;
    gap: 0;
    padding-left: 0.5rem !important;
    padding-right: 0.5rem !important;
}

/* 折叠时侧边栏头部内容居中调整（保留按钮） */
.sidebar-collapsed .sidebar-header {
    justify-content: flex-end;
    padding-left: 0.75rem;
    padding-right: 0.75rem;
}

/* 折叠时导航区域的内边距调整，让图标更紧凑 */
.sidebar-collapsed nav {
    padding-left: 0.25rem;
    padding-right: 0.25rem;
}

/* 折叠时底部的退出按钮居中 */
.sidebar-collapsed .mt-auto.sidebar-item {
    justify-content: center;
    gap: 0;
    padding-left: 0.5rem;
    padding-right: 0.5rem;
}
</style>

<script>
// 侧边栏折叠功能 + localStorage 持久化状态
function toggleSidebar() {
    const sidebar = document.getElementById('sidebar');
    if (!sidebar) return;
    
    const isCollapsed = sidebar.classList.contains('sidebar-collapsed');
    if (isCollapsed) {
        sidebar.classList.remove('sidebar-collapsed');
        localStorage.setItem('sidebarCollapsed', 'false');
    } else {
        sidebar.classList.add('sidebar-collapsed');
        localStorage.setItem('sidebarCollapsed', 'true');
    }
}

// 页面加载时恢复折叠状态
document.addEventListener('DOMContentLoaded', function() {
    const sidebar = document.getElementById('sidebar');
    if (!sidebar) return;
    
    const savedState = localStorage.getItem('sidebarCollapsed');
    if (savedState === 'true') {
        sidebar.classList.add('sidebar-collapsed');
    } else {
        sidebar.classList.remove('sidebar-collapsed');
    }
});
</script>