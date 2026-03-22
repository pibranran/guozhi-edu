<?php
// 1. 使用 require_once 彻底杜绝 Session 重复启动的报错
require_once "config.php";

// 获取参数与验证
$sch_id = isset($_GET['sch_id']) ? (int)$_GET['sch_id'] : 0;
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: index.php");
    exit();
}

$uuid = $_SESSION['uuid'];
$role = $_SESSION['role']; // 对应：teacher, headmaster, dean, admin

// 2. 查询老师姓名
// 🔥 已修改：uuid 现在是 VARCHAR 类型，使用 's' 绑定（原 'i' 已改为 's'）
$stmt = $conn->prepare("SELECT name FROM teachers WHERE uuid = ?");
$stmt->bind_param("s", $uuid);  // ←←← 关键修改：从 "i" 改为 "s"
$stmt->execute();
$teacher = $stmt->get_result()->fetch_assoc();
$realName = $teacher['name'] ?? "教职工";
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <script src="https://cdn.tailwindcss.com"></script>
    <title>教务管理系统</title>
    <style>
        .sidebar-transition { transition: width 0.3s cubic-bezier(0.4, 0, 0.2, 1); }
        .collapsed .menu-text, .collapsed .role-title { display: none; }
        .collapsed .sidebar-header { justify-content: center; }
        .collapsed .sidebar-item { justify-content: center; }
        input::-webkit-outer-spin-button, input::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
    </style>
</head>
<body class="flex h-screen bg-slate-50 text-slate-900 overflow-hidden">
    <?php 
        // 定义当前页，方便侧边栏做高亮逻辑（可选）
        $active_page = 'dashboard'; 
        include "sidebar.php"; 
    ?>
    <main class="flex-1 flex flex-col min-w-0">
        <header class="h-20 bg-white border-b flex items-center justify-between px-8 shrink-0">
            <div class="flex items-center gap-6">
                <div class="w-10 h-10 bg-amber-500 rounded-xl flex items-center justify-center shadow-lg shadow-amber-200 shrink-0">
                    <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"></path></svg>
                </div>
                <form action="dashboard.php" method="GET">
                    <select name="sch_id" onchange="this.form.submit()" class="bg-slate-100 border-none rounded-lg px-4 py-2.5 text-sm font-bold outline-none cursor-pointer hover:bg-slate-200 transition-colors">
                        <option value="0">--- 选择班级课程 ---</option>
                        <?php
                        $s_sql = "SELECT s.id, c.class_name, co.course_name FROM schedule s 
                                  JOIN classes c ON s.class_id = c.class_id 
                                  JOIN courses co ON s.course_id = co.course_id 
                                  WHERE s.teacher_uuid = ?";
                        $s_stmt = $conn->prepare($s_sql);
                        $s_stmt->bind_param("s", $uuid); 
                        $s_stmt->execute();
                        $s_res = $s_stmt->get_result();
                        while($s_row = $s_res->fetch_assoc()){
                            $sel = ($sch_id == $s_row['id']) ? "selected" : "";
                            echo "<option value='{$s_row['id']}' $sel>{$s_row['class_name']} - {$s_row['course_name']}</option>";
                        }
                        ?>
                    </select>
                </form>
            </div>
            <div class="flex items-center gap-3">
                <div class="text-right leading-tight">
                    <div class="text-sm font-bold text-slate-900"><?php echo htmlspecialchars($realName); ?></div>
                    <div class="text-[10px] text-slate-400 font-mono tracking-tighter uppercase">ID: <?php echo $uuid; ?></div>
                </div>
                <div class="w-10 h-10 rounded-full bg-slate-900 text-white flex items-center justify-center font-bold border-2 border-white shadow-sm shrink-0">
                    <?php echo mb_substr($realName, 0, 1); ?>
                </div>
            </div>
        </header>

        <div class="p-8 flex-1 overflow-auto bg-slate-50/50">
            <?php if ($sch_id > 0): ?>
                <form action="save_scores.php" method="POST" class="bg-white border rounded-[24px] shadow-sm overflow-hidden">
                    <input type="hidden" name="sch_id" value="<?php echo $sch_id; ?>">
                    <div class="p-5 bg-white border-b flex justify-between items-center">
                        <div>
                            <h2 class="font-bold text-slate-800">成绩录入清单</h2>
                            <p class="text-xs text-slate-400">总评成绩由任课老师手动核算输入</p>
                        </div>
                        <button type="submit" class="bg-slate-900 text-white px-8 py-2.5 rounded-xl font-bold hover:bg-black transition-all shadow-lg shadow-slate-200">同步至云端</button>
                    </div>
                    <table class="w-full text-left">
                        <thead class="bg-slate-50/50 border-b text-[11px] text-slate-400 uppercase tracking-widest font-bold">
                            <tr>
                                <th class="p-5">学生信息</th>
                                <th class="p-5">平时成绩</th>
                                <th class="p-5">期中成绩</th>
                                <th class="p-5">期末成绩</th>
                                <th class="p-5 text-amber-600">总评 (手动)</th>
                                <th class="p-5 text-red-500">补考</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php
                            $st_sql = "SELECT st.uuid, st.name, 
                                              sc.daily_score, sc.mid_score, sc.final_score, 
                                              sc.total_score, sc.resit_score 
                                       FROM scores sc 
                                       JOIN students st ON sc.student_uuid = st.uuid 
                                       WHERE sc.schedule_id = ? 
                                       ORDER BY st.uuid ASC";

                            $st_stmt = $conn->prepare($st_sql);
                            $st_stmt->bind_param("i", $sch_id);
                            $st_stmt->execute();
                            $st_res = $st_stmt->get_result();

                            while($r = $st_res->fetch_assoc()): 
                                $s_uuid = $r['uuid'];
                                $row_class = ($r['name'] == '⚠️ 未注册学生') ? 'bg-red-50' : 'hover:bg-slate-50/50';
                                ?>

                                <tr class="text-sm hover:bg-slate-50/50 transition-colors group">
                                    <td class="p-5">
                                        <div class="font-bold text-slate-800"><?php echo htmlspecialchars($r['name'] ?? '未命名'); ?></div>
                                        <div class="text-[10px] text-slate-400 font-mono tracking-tight"><?php echo $r['uuid']; ?></div>
                                    </td>
                                    <td class="p-5"><input type="number" step="0.1" name="daily[<?php echo $r['uuid']; ?>]" class="w-20 border-slate-200 border rounded-lg px-3 py-1.5 focus:ring-2 focus:ring-slate-200 outline-none" value="<?php echo $r['daily_score'] ?? ''; ?>"></td>
                                    <td class="p-5"><input type="number" step="0.1" name="mid[<?php echo $r['uuid']; ?>]" class="w-20 border-slate-200 border rounded-lg px-3 py-1.5 focus:ring-2 focus:ring-slate-200 outline-none" value="<?php echo $r['mid_score'] ?? ''; ?>"></td>
                                    <td class="p-5"><input type="number" step="0.1" name="final[<?php echo $r['uuid']; ?>]" class="w-20 border-slate-200 border rounded-lg px-3 py-1.5 focus:ring-2 focus:ring-slate-200 outline-none" value="<?php echo $r['final_score'] ?? ''; ?>"></td>
                                    <td class="p-5"><input type="number" step="0.1" name="total[<?php echo $r['uuid']; ?>]" class="w-24 border-2 border-amber-100 bg-amber-50/50 rounded-lg px-3 py-1.5 font-bold text-amber-700 focus:border-amber-400 outline-none" value="<?php echo $r['total_score'] ?? ''; ?>"></td>
                                    <td class="p-5"><input type="number" step="0.1" name="resit[<?php echo $r['uuid']; ?>]" class="w-20 border-slate-200 border rounded-lg px-3 py-1.5 text-red-500 focus:ring-2 focus:ring-red-100 outline-none" value="<?php echo $r['resit_score'] ?? ''; ?>"></td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </form>
            <?php else: ?>
                <div class="h-full flex flex-col items-center justify-center text-slate-300 border-4 border-dashed rounded-[48px] border-slate-200">
                    <div class="text-6xl mb-4">📂</div>
                    <div class="text-xl font-bold tracking-widest uppercase">请在上方选择班级以开始录入</div>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <script>
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            sidebar.classList.toggle('w-64');
            sidebar.classList.toggle('w-20');
            sidebar.classList.toggle('collapsed');
        }
    </script>
</body>
</html>