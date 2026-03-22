<?php
// 1. 使用 require_once 彻底杜绝 Session 重复启动的报错
require_once __DIR__ ."/../config.php";

// 获取参数与验证
$sch_id = isset($_GET['sch_id']) ? (int)$_GET['sch_id'] : 0;
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: /../index.php");
    exit();
}

$uuid = $_SESSION['uuid'];
$role = $_SESSION['role']; 

// 2. 查询当前操作者的姓名（保持原样）
$stmt = $conn->prepare("SELECT name FROM teachers WHERE uuid = ?");
$stmt->bind_param("s", $uuid);
$stmt->execute();
$teacher = $stmt->get_result()->fetch_assoc();
$realName = $teacher['name'] ?? "管理员";
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <script src="https://cdn.tailwindcss.com"></script>
    <title>管理端 - 成绩概览</title>
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
        $active_page = 'admin_scores'; // 建议改为管理相关的 active 状态
        include "sidebar.php"; 
    ?>
    <main class="flex-1 flex flex-col min-w-0">
        <header class="h-20 bg-white border-b flex items-center justify-between px-8 shrink-0">
            <div class="flex items-center gap-6">
                <div class="w-10 h-10 bg-indigo-600 rounded-xl flex items-center justify-center shadow-lg shadow-indigo-100 shrink-0">
                    <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"></path></svg>
                </div>
                <form action="dashboard.php" method="GET">
                    <select name="sch_id" onchange="this.form.submit()" class="bg-slate-100 border-none rounded-lg px-4 py-2.5 text-sm font-bold outline-none cursor-pointer hover:bg-slate-200 transition-colors max-w-md">
                        <option value="0">--- 全校班级课程概览 ---</option>
                        <?php
                        $s_sql = "SELECT s.id, c.class_name, co.course_name, t.name as teacher_name 
                                  FROM schedule s 
                                  JOIN classes c ON s.class_id = c.class_id 
                                  JOIN courses co ON s.course_id = co.course_id 
                                  JOIN teachers t ON s.teacher_uuid = t.uuid
                                  ORDER BY c.class_name ASC";
                        $s_res = $conn->query($s_sql);
                        while($s_row = $s_res->fetch_assoc()){
                            $sel = ($sch_id == $s_row['id']) ? "selected" : "";
                            // 增加任课老师显示，方便管理辨认
                            echo "<option value='{$s_row['id']}' $sel>{$s_row['class_name']} - {$s_row['course_name']} ({$s_row['teacher_name']})</option>";
                        }
                        ?>
                    </select>
                </form>
            </div>
            <div class="flex items-center gap-3">
                <div class="text-right leading-tight">
                    <div class="text-sm font-bold text-slate-900"><?php echo htmlspecialchars($realName); ?></div>
                    <div class="text-[10px] text-indigo-500 font-bold uppercase"><?php echo $role; ?></div>
                </div>
                <div class="w-10 h-10 rounded-full bg-indigo-600 text-white flex items-center justify-center font-bold border-2 border-white shadow-sm shrink-0">
                    管
                </div>
            </div>
        </header>

        <div class="p-8 flex-1 overflow-auto bg-slate-50/50">
            <?php if ($sch_id > 0): ?>
                <form action="save_scores.php" method="POST" class="bg-white border rounded-[24px] shadow-sm overflow-hidden">
                    <input type="hidden" name="sch_id" value="<?php echo $sch_id; ?>">
                    <div class="p-5 bg-white border-b flex justify-between items-center">
                        <div>
                            <h2 class="font-bold text-slate-800">成绩管理面板</h2>
                            <p class="text-xs text-slate-400">管理员权限：支持直接修正或查看全班成绩</p>
                        </div>
                        <button type="submit" class="bg-indigo-600 text-white px-8 py-2.5 rounded-xl font-bold hover:bg-indigo-700 transition-all shadow-lg shadow-indigo-200">保存全局修改</button>
                    </div>
                    <table class="w-full text-left">
                        <thead class="bg-slate-50/50 border-b text-[11px] text-slate-400 uppercase tracking-widest font-bold">
                            <tr>
                                <th class="p-5">学生信息</th>
                                <th class="p-5">平时成绩</th>
                                <th class="p-5">期中成绩</th>
                                <th class="p-5">期末成绩</th>
                                <th class="p-5 text-amber-600">总评</th>
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
                                ?>
                                <tr class="text-sm hover:bg-slate-50/50 transition-colors group">
                                    <td class="p-5">
                                        <div class="font-bold text-slate-800"><?php echo htmlspecialchars($r['name'] ?? '未命名'); ?></div>
                                        <div class="text-[10px] text-slate-400 font-mono"><?php echo $r['uuid']; ?></div>
                                    </td>
                                    <td class="p-5"><input type="number" step="0.1" name="daily[<?php echo $r['uuid']; ?>]" class="w-20 border-slate-200 border rounded-lg px-3 py-1.5 focus:ring-2 focus:ring-indigo-100 outline-none" value="<?php echo $r['daily_score'] ?? ''; ?>"></td>
                                    <td class="p-5"><input type="number" step="0.1" name="mid[<?php echo $r['uuid']; ?>]" class="w-20 border-slate-200 border rounded-lg px-3 py-1.5 focus:ring-2 focus:ring-indigo-100 outline-none" value="<?php echo $r['mid_score'] ?? ''; ?>"></td>
                                    <td class="p-5"><input type="number" step="0.1" name="final[<?php echo $r['uuid']; ?>]" class="w-20 border-slate-200 border rounded-lg px-3 py-1.5 focus:ring-2 focus:ring-indigo-100 outline-none" value="<?php echo $r['final_score'] ?? ''; ?>"></td>
                                    <td class="p-5"><input type="number" step="0.1" name="total[<?php echo $r['uuid']; ?>]" class="w-24 border-2 border-amber-100 bg-amber-50/50 rounded-lg px-3 py-1.5 font-bold text-amber-700 focus:border-amber-400 outline-none" value="<?php echo $r['total_score'] ?? ''; ?>"></td>
                                    <td class="p-5"><input type="number" step="0.1" name="resit[<?php echo $r['uuid']; ?>]" class="w-20 border-slate-200 border rounded-lg px-3 py-1.5 text-red-500 focus:ring-2 focus:ring-red-100 outline-none" value="<?php echo $r['resit_score'] ?? ''; ?>"></td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </form>
            <?php else: ?>
                <div class="h-full flex flex-col items-center justify-center text-slate-300 border-4 border-dashed rounded-[48px] border-slate-200">
                    <div class="text-6xl mb-4">🏫</div>
                    <div class="text-xl font-bold tracking-widest uppercase">请选择需要管理的班级课程</div>
                    <p class="text-sm mt-2">您可以查看并修改全校任意课程的成绩记录</p>
                </div>
            <?php endif; ?>
        </div>
    </main>
</body>
</html>