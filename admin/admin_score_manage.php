<?php
require_once __DIR__ ."/../config.php";

$sch_id = isset($_GET['sch_id']) ? (int)$_GET['sch_id'] : 0;
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'admin') {
    header("Location: /../index.php");
    exit();
}

$uuid = $_SESSION['uuid'];
$role = $_SESSION['role']; 

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
</head>
<body class="flex h-screen bg-slate-50 text-slate-900 overflow-hidden">
    <?php 
        $active_page = 'admin_scores'; 
        include __DIR__ . "/../sidebar.php";
    ?>
    <main class="flex-1 flex flex-col min-w-0">
        <header class="h-20 bg-white border-b flex items-center justify-between px-8 shrink-0">
            <div class="flex items-center gap-6">
                <div class="w-10 h-10 bg-indigo-600 rounded-xl flex items-center justify-center shadow-lg shadow-indigo-100 shrink-0">
                    <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"></path></svg>
                </div>
                <form action="admin_score_manage.php" method="GET">
                    <select name="sch_id" onchange="this.form.submit()" class="bg-slate-100 border-none rounded-lg px-4 py-2.5 text-sm font-bold outline-none cursor-pointer hover:bg-slate-200 transition-colors max-w-md">
                        <option value="0">--- 全校成绩总览 ---</option>
                        <?php
                        $s_sql = "SELECT s.id, c.class_name, co.course_name, t.name as teacher_name 
                                  FROM schedule s 
                                  JOIN classes c ON s.class_id = c.class_id 
                                  JOIN courses co ON s.course_id = co.id 
                                  JOIN teachers t ON s.teacher_uuid = t.uuid
                                  WHERE s.is_active = 1 
                                  ORDER BY c.class_name ASC, co.course_name ASC";
                        $s_res = $conn->query($s_sql);
                        if ($s_res && $s_res->num_rows > 0) {
                            while($s_row = $s_res->fetch_assoc()){
                                $sel = ($sch_id == (int)$s_row['id']) ? "selected" : "";
                                echo "<option value='{$s_row['id']}' $sel>{$s_row['class_name']} - {$s_row['course_name']} ({$s_row['teacher_name']})</option>";
                            }
                        } else {
                            echo "<option disabled>暂无排课数据</option>";
                        }
                        ?>
                    </select>
                </form>
            </div>
            <div class="flex items-center gap-3">
                <div class="text-right leading-tight mr-4">
                    <div class="text-sm font-bold text-slate-900"><?php echo htmlspecialchars($realName); ?></div>
                    <div class="text-[10px] text-indigo-500 font-bold uppercase tracking-widest">Admin Control</div>
                </div>
            </div>
        </header>

        <div class="p-8 flex-1 overflow-auto bg-slate-50/50">
            <?php if ($sch_id > 0): ?>
                <div class="flex justify-between items-center mb-6">
                    <p class="text-slate-400 text-sm">正在管理：该班级所有学生的原始成绩</p>
                    <div class="flex gap-3">
                        <button onclick="exportScores(<?= $sch_id ?>)" class="bg-green-600 text-white px-4 py-2 rounded-xl font-bold text-sm shadow-sm hover:bg-green-700 transition-all">📎 导出 Excel</button>
                    </div>
                </div>

                <form action="api_save_scores.php" method="POST" class="bg-white border rounded-[24px] shadow-sm overflow-hidden">
                    <input type="hidden" name="sch_id" value="<?php echo $sch_id; ?>">
                    <div class="p-5 bg-white border-b flex justify-between items-center">
                        <div>
                            <h2 class="font-bold text-slate-800">成绩管理面板</h2>
                            <p class="text-xs text-slate-400">管理员可直接覆盖修正总评及补考成绩</p>
                        </div>
                        <button type="submit" class="bg-indigo-600 text-white px-8 py-2.5 rounded-xl font-bold hover:bg-indigo-700 transition-all shadow-lg shadow-indigo-200">保存全局修改</button>
                    </div>
                    <table class="w-full text-left">
                        <thead class="bg-slate-50/50 border-b text-[11px] text-slate-400 uppercase tracking-widest font-bold">
                            <tr>
                                <th class="p-5">学生信息</th>
                                <th class="p-5">平时</th>
                                <th class="p-5">期中</th>
                                <th class="p-5">期末</th>
                                <th class="p-5 text-indigo-600 font-black">总评</th>
                                <th class="p-5 text-red-500">补考</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php
                            $st_sql = "
                                SELECT st.uuid, st.name, 
                                       COALESCE(sc.daily_score, '') AS daily_score,
                                       COALESCE(sc.mid_score, '') AS mid_score,
                                       COALESCE(sc.final_score, '') AS final_score,
                                       COALESCE(sc.total_score, '') AS total_score,
                                       COALESCE(sc.resit_score, '') AS resit_score
                                FROM students st
                                JOIN classes c ON st.class_id = c.class_id
                                JOIN schedule s ON s.class_id = c.class_id
                                LEFT JOIN scores sc ON sc.student_uuid = st.uuid AND sc.schedule_id = s.id
                                WHERE s.id = ?
                                ORDER BY st.uuid ASC
                            ";
                            $st_stmt = $conn->prepare($st_sql);
                            $st_stmt->bind_param("i", $sch_id);
                            $st_stmt->execute();
                            $st_res = $st_stmt->get_result();

                            if ($st_res && $st_res->num_rows > 0) {
                                while($r = $st_res->fetch_assoc()):
                            ?>
                            <tr class="text-sm hover:bg-slate-50/50 transition-colors group">
                                <td class="p-5">
                                    <div class="font-bold text-slate-800"><?php echo htmlspecialchars($r['name'] ?? '未命名'); ?></div>
                                    <div class="text-[10px] text-slate-400 font-mono"><?php echo $r['uuid']; ?></div>
                                </td>
                                <td class="p-5"><input type="number" step="0.1" name="daily[<?php echo $r['uuid']; ?>]" class="w-20 border-slate-200 border rounded-lg px-3 py-1.5 focus:ring-2 focus:ring-indigo-100 outline-none" value="<?php echo $r['daily_score']; ?>"></td>
                                <td class="p-5"><input type="number" step="0.1" name="mid[<?php echo $r['uuid']; ?>]" class="w-20 border-slate-200 border rounded-lg px-3 py-1.5 focus:ring-2 focus:ring-indigo-100 outline-none" value="<?php echo $r['mid_score']; ?>"></td>
                                <td class="p-5"><input type="number" step="0.1" name="final[<?php echo $r['uuid']; ?>]" class="w-20 border-slate-200 border rounded-lg px-3 py-1.5 focus:ring-2 focus:ring-indigo-100 outline-none" value="<?php echo $r['final_score']; ?>"></td>
                                <td class="p-5"><input type="number" step="0.1" name="total[<?php echo $r['uuid']; ?>]" class="w-24 border-2 border-indigo-100 bg-indigo-50/50 rounded-lg px-3 py-1.5 font-bold text-indigo-700 focus:border-indigo-400 outline-none" value="<?php echo $r['total_score']; ?>"></td>
                                <td class="p-5"><input type="number" step="0.1" name="resit[<?php echo $r['uuid']; ?>]" class="w-20 border-slate-200 border rounded-lg text-red-500 focus:ring-2 focus:ring-red-100 outline-none" value="<?php echo $r['resit_score']; ?>"></td>
                            </tr>
                            <?php 
                                endwhile; 
                            } else {
                                echo '<tr><td colspan="6" class="p-5 text-center text-slate-400">该班级暂无学生</td></tr>';
                            }
                            ?>
                        </tbody>
                    </table>
                </form>

            <?php else: ?>
                <!-- 管理员：直接显示【全校所有成绩记录】 -->
                <div class="bg-white border rounded-[24px] shadow-sm overflow-hidden">
                    <div class="p-5 bg-white border-b flex justify-between items-center">
                        <div>
                            <h2 class="font-bold text-slate-800">全校所有成绩总表</h2>
                            <p class="text-xs text-slate-400">管理员模式 - 查看全部成绩数据</p>
                        </div>
                    </div>
                    <table class="w-full text-left">
                        <thead class="bg-slate-50/50 border-b text-[11px] text-slate-400 uppercase tracking-widest font-bold">
                            <tr>
                                <th class="p-5">班级</th>
                                <th class="p-5">课程</th>
                                <th class="p-5">学号</th>
                                <th class="p-5">姓名</th>
                                <th class="p-5">平时</th>
                                <th class="p-5">期中</th>
                                <th class="p-5">期末</th>
                                <th class="p-5">总分</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php
                            // 核心：直接查 scores 成绩表，关联所有信息
                            $all_sql = "
                                SELECT 
                                    c.class_name,
                                    co.course_name,
                                    st.uuid,
                                    st.name,
                                    sc.daily_score,
                                    sc.mid_score,
                                    sc.final_score,
                                    sc.total_score
                                FROM scores sc
                                JOIN students st ON sc.student_uuid = st.uuid
                                JOIN schedule s ON sc.schedule_id = s.id
                                JOIN classes c ON s.class_id = c.class_id
                                JOIN courses co ON s.course_id = co.id
                                ORDER BY c.class_name, co.course_name, st.uuid
                            ";
                            $all_res = $conn->query($all_sql);

                            if ($all_res && $all_res->num_rows > 0) {
                                while($row = $all_res->fetch_assoc()){
                                    echo "
                                    <tr class='text-sm hover:bg-slate-100'>
                                        <td class='p-5'>{$row['class_name']}</td>
                                        <td class='p-5'>{$row['course_name']}</td>
                                        <td class='p-5'>{$row['uuid']}</td>
                                        <td class='p-5 font-medium'>{$row['name']}</td>
                                        <td class='p-5'>{$row['daily_score']}</td>
                                        <td class='p-5'>{$row['mid_score']}</td>
                                        <td class='p-5'>{$row['final_score']}</td>
                                        <td class='p-5 font-bold text-indigo-600'>{$row['total_score']}</td>
                                    </tr>";
                                }
                            } else {
                                echo '<tr><td colspan="8" class="p-5 text-center text-slate-400">暂无任何成绩数据</td></tr>';
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <script>
        function exportScores(schId) {
            window.location.href = 'export_scores.php?sch_id=' + schId;
        }
    </script>
</body>
</html>