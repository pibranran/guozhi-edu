<?php
require_once __DIR__ . "/../config.php";

// 1. 权限与身份获取
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'admin') {
    header("Location: /../index.php");
    exit();
}
$role = $_SESSION['role'] ?? 'admin';

// 2. 获取专业 (Majors) 和 班级 (Classes) 用于展示老师的职务（专业主任/班主任）
$all_majors = [];
$major_res = $conn->query("SELECT major_id, major_name, dean_uuid FROM majors ORDER BY major_name ASC");
$dean_map = []; // 记录谁是哪个专业的专业主任
if ($major_res) {
    while ($m = $major_res->fetch_assoc()) {
        $all_majors[] = $m;
        if ($m['dean_uuid']) {
            $dean_map[$m['dean_uuid']] = $m['major_name'] . " (专业主任)";
        }
    }
}

$headmaster_map = []; // 记录谁是哪个班的班主任
$class_res = $conn->query("SELECT class_name, headmaster_uuid FROM classes WHERE headmaster_uuid IS NOT NULL");
if ($class_res) {
    while ($c = $class_res->fetch_assoc()) {
        $headmaster_map[$c['headmaster_uuid']] = $c['class_name'] . " (班主任)";
    }
}

// 3. 生成建议工号（根据当前年份，仿照学生学号逻辑）
$currentYear = date('Y');
$next_uuid = $currentYear . '1001';
$max_res = $conn->query("SELECT MAX(uuid) as max_uuid FROM teachers");
if ($max_res) {
    $max_row = $max_res->fetch_assoc();
    if ($max_row && isset($max_row['max_uuid']) && $max_row['max_uuid']) {
        $max_uuid = $max_row['max_uuid'];
        $year = substr($max_uuid, 0, 4);
        $num = (int)substr($max_uuid, -4);
        if ($year == $currentYear) {
            $next_num = $num + 1;
            $next_uuid = $currentYear . str_pad($next_num, 4, '0', STR_PAD_LEFT);
        } else {
            $next_uuid = $currentYear . '1001';
        }
    }
}

// 4. 分页与筛选设置
$perPage = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 20;
$currentPage = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($currentPage < 1) $currentPage = 1;

$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$year_filter = isset($_GET['year']) ? trim($_GET['year']) : '';

// 5. 构建筛选条件
$where = [];
$params = [];
$types = "";

if ($search) {
    $where[] = "(name LIKE ? OR uuid LIKE ?)";
    $t = "%$search%";
    $params[] = $t;
    $params[] = $t;
    $types .= "ss";
}
if ($year_filter) {
    $where[] = "LEFT(uuid, 4) = ?";
    $params[] = $year_filter;
    $types .= "s";
}
$whereClause = $where ? "WHERE " . implode(" AND ", $where) : "";

// 6. 总记录数
$totalSql = "SELECT COUNT(*) as total FROM teachers $whereClause";
$totalStmt = $conn->prepare($totalSql);
if ($types) {
    $totalStmt->bind_param($types, ...$params);
}
$totalStmt->execute();
$totalResult = $totalStmt->get_result();
$totalRow = $totalResult->fetch_assoc();
$totalRecords = $totalRow['total'];
$totalPages = ceil($totalRecords / $perPage);

// 7. 计算 OFFSET
$offset = ($currentPage - 1) * $perPage;

// 8. 获取当前页教师数据（增加了关联课程查询）
// 使用 LEFT JOIN 关联 schedule(s) 和 courses(co)
// 使用 GROUP_CONCAT 将多门课程合并，DISTINCT 防止重复，SEPARATOR 指定分隔符
$sql = "SELECT t.uuid, t.name, t.LastLoginTime, 
               GROUP_CONCAT(DISTINCT co.course_name SEPARATOR '、') as taught_courses
        FROM teachers t
        LEFT JOIN schedule s ON t.uuid = s.teacher_uuid
        LEFT JOIN courses co ON s.course_id = co.course_id
        $whereClause 
        GROUP BY t.uuid
        ORDER BY t.uuid DESC 
        LIMIT ? OFFSET ?";

$stmt = $conn->prepare($sql);

// 注意：这里的 $params 是筛选（Search/Year）的参数
$allParams = array_merge($params, [$perPage, $offset]);
$allTypes = $types . "ii";

if ($allTypes) {
    $stmt->bind_param($allTypes, ...$allParams);
}

$stmt->execute();
$res = $stmt->get_result();

// 9. 构建查询字符串
$queryParams = [];
if ($search) $queryParams['search'] = $search;
if ($year_filter) $queryParams['year'] = $year_filter;
$queryString = http_build_query($queryParams);
$baseUrl = 'admin_teachers.php?' . ($queryString ? $queryString . '&' : '');
?>

<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <script src="https://cdn.tailwindcss.com"></script>
    <title>教师管理 - EDU.AMS</title>
</head>
<body class="flex h-screen bg-slate-50 overflow-hidden">
    <?php include __DIR__ . "/../sidebar.php"; ?>
    <main class="flex-1 flex flex-col min-w-0">
        <header class="h-20 bg-white border-b flex items-center justify-between px-8 shrink-0">
            <h1 class="text-xl font-bold">教师师资中心</h1>
            <form method="GET" class="relative">
                <input type="text" name="search" placeholder="搜索姓名或工号..." value="<?=htmlspecialchars($search)?>" class="bg-slate-100 rounded-xl px-10 py-2 text-sm outline-none">
            </form>
        </header>
        
        <div class="p-8 flex-1 overflow-auto">
            <div class="max-w-6xl mx-auto">
                <div class="flex justify-between mb-6">
                    <p class="text-slate-400">系统内在职教师名单</p>
                    <div class="space-x-2">
                        <button onclick="exportTeachers()" class="bg-green-600 text-white px-4 py-2 rounded-xl font-bold">📎 导出 Excel</button>
                        <label class="bg-blue-600 text-white px-4 py-2 rounded-xl font-bold cursor-pointer">
                            📂 导入 Excel
                            <input type="file" id="importFile" accept=".xlsx, .xls" class="hidden" onchange="importTeachers(this)">
                        </label>
                        <button onclick="openModal('add', '<?=$next_uuid?>')" class="bg-slate-900 text-white px-6 py-2 rounded-xl font-bold">+ 注册教师</button>
                    </div>
                </div>

                <div class="flex justify-between mb-4 flex-wrap gap-4">
                    <div class="flex items-center space-x-4">
                        <div class="flex items-center">
                            <input type="checkbox" id="selectAll" class="form-checkbox h-4 w-4 text-indigo-600 mr-2">
                            <span class="text-sm text-slate-400">全选</span>
                        </div>
                        <button id="deleteSelected" class="hidden bg-red-500 text-white px-4 py-2 rounded text-sm">批量删除</button>
                        <button id="resetSelected" class="hidden bg-yellow-500 text-white px-4 py-2 rounded text-sm">重置密码</button>
                    </div>
                    <div class="flex space-x-2">
                        <select id="filter_year" class="bg-slate-100 rounded px-3 py-1 text-sm">
                            <option value="">所有入职年份</option>
                            <?php
                            $yearSql = "SELECT DISTINCT SUBSTRING(uuid, 1, 4) as year FROM teachers ORDER BY year DESC";
                            $yearRes = $conn->query($yearSql);
                            while ($y = $yearRes->fetch_assoc()) {
                                $selected = ($year_filter == $y['year']) ? 'selected' : '';
                                echo "<option value='{$y['year']}' $selected>{$y['year']}年</option>";
                            }
                            ?>
                        </select>
                        <button id="filterApply" class="bg-indigo-500 text-white px-4 py-1 rounded text-sm">筛选</button>
                        <button id="filterReset" class="bg-gray-300 text-gray-700 px-4 py-1 rounded text-sm">重置</button>
                    </div>
                </div>

                <div class="bg-white border rounded-[24px] overflow-hidden shadow-sm">
                    <table class="w-full text-left">
                        <thead class="bg-slate-50 border-b text-[11px] text-slate-400 uppercase font-bold">
                            <tr>
                                <th class="p-5">选择</th>
                                <th class="p-5">工号</th>
                                <th class="p-5">姓名</th>
                                <th class="p-5">负责/职务</th>
                                <th class="p-5">所教课程</th> <th class="p-5">最后登录</th>
                                <th class="p-5 text-center">操作</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php while ($row = $res->fetch_assoc()): ?>
                            <tr class="hover:bg-slate-50 transition-colors">
                                <td class="p-5 text-center w-12">
                                    <input type="checkbox" class="teacher-checkbox rounded border-slate-300 text-amber-500 focus:ring-amber-500" value="<?=$row['uuid']?>">
                                </td>

                                <td class="p-5 font-mono text-xs text-slate-400 w-24"><?=$row['uuid']?></td>

                                <td class="p-5 font-bold text-slate-700 w-32"><?=$row['name']?></td>

                                <td class="p-5 text-xs truncate-cell" style="max-width: 180px;" title="<?php 
                                    $roles = [];
                                    if(isset($dean_map[$row['uuid']])) $roles[] = $dean_map[$row['uuid']] . "(专业主任)";
                                    if(isset($headmaster_map[$row['uuid']])) $roles[] = $headmaster_map[$row['uuid']] . "(班主任)";
                                    echo $roles ? implode(" / ", $roles) : "普通教师";
                                ?>">
                                    <?php 
                                        $displayRoles = [];
                                        if(isset($dean_map[$row['uuid']])) $displayRoles[] = "<span class='text-indigo-600 font-semibold'>".$dean_map[$row['uuid']]."</span>";
                                        if(isset($headmaster_map[$row['uuid']])) $displayRoles[] = "<span class='text-emerald-600'>".$headmaster_map[$row['uuid']]."</span>";
                                        echo $displayRoles ? implode(" / ", $displayRoles) : "<span class='text-slate-300'>普通教师</span>";
                                    ?>
                                </td>

                                <td class="p-5 truncate-cell" style="max-width: 250px;" title="<?= htmlspecialchars($row['taught_courses'] ?? '暂无排课') ?>">
                                    <div class="flex gap-1 items-center">
                                        <?php if ($row['taught_courses']): ?>
                                            <?php 
                                            $c_list = explode('、', $row['taught_courses']);
                                            foreach($c_list as $index => $c): 
                                                if($index > 2) { echo "<span class='text-slate-300 text-[10px]'>+</span>"; break; } // 超过3个显示加号
                                            ?>
                                                <span class="bg-slate-100 text-slate-600 px-2 py-0.5 rounded text-[10px] whitespace-nowrap"><?=htmlspecialchars($c)?></span>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <span class="text-slate-300 text-[10px]">暂无排课</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="p-5 text-xs text-slate-400"><?= $row['LastLoginTime'] ?? '从未登录' ?></td>
                                <td class="p-5 text-center">
                                    <button onclick="openModal('edit', '<?=$row['uuid']?>', '<?=htmlspecialchars($row['name'])?>')" class="text-amber-600 mr-2">✏️</button>
                                    <button onclick="deleteTeacher('<?=$row['uuid']?>')" class="text-red-500 mr-2">🗑️</button>
                                    <button onclick="resetPassword('<?=$row['uuid']?>')" class="text-blue-500">🔑</button>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                    </tbody>
                    </table>
                </div>

                <div class="mt-6 flex flex-col sm:flex-row sm:justify-between items-center gap-4">
                    <div class="text-sm text-slate-400">
                        共 <?= $totalRecords ?> 名教师，当前页 <?= $currentPage ?> / <?= $totalPages ?>
                    </div>
                    <div class="flex gap-2">
                        <?php if ($currentPage > 1): ?>
                            <a href="<?=$baseUrl?>page=<?=$currentPage-1?>&per_page=<?=$perPage?>" class="px-3 py-1 bg-slate-200 rounded hover:bg-slate-300 text-slate-700">上一页</a>
                        <?php endif; ?>
                        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                            <a href="<?=$baseUrl?>page=<?=$i?>&per_page=<?=$perPage?>" class="px-3 py-1 <?= $i == $currentPage ? 'bg-slate-900 text-white' : 'bg-slate-200 rounded hover:bg-slate-300 text-slate-700' ?>"><?= $i ?></a>
                        <?php endfor; ?>
                        <?php if ($currentPage < $totalPages): ?>
                            <a href="<?=$baseUrl?>page=<?=$currentPage+1?>&per_page=<?=$perPage?>" class="px-3 py-1 bg-slate-200 rounded hover:bg-slate-300 text-slate-700">下一页</a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <div id="sideModal" class="hidden fixed inset-0 bg-slate-900/50 backdrop-blur-sm z-50 flex justify-end">
        <div class="w-[400px] bg-white h-full p-8 shadow-2xl">
            <h2 id="modalTitle" class="text-xl font-bold mb-10">教师注册</h2>
            <div class="space-y-6">
                <div>
                    <label class="block text-[10px] font-bold text-slate-400 uppercase mb-2">工号</label>
                    <input type="text" id="m_uuid" class="w-full bg-slate-100 rounded-xl px-4 py-3 outline-none">
                </div>
                <div>
                    <label class="block text-[10px] font-bold text-slate-400 uppercase mb-2">教师姓名</label>
                    <input type="text" id="m_name" class="w-full bg-slate-100 rounded-xl px-4 py-3 outline-none border-2 border-transparent focus:border-amber-500">
                </div>
                <p class="text-xs text-slate-400 bg-slate-50 p-4 rounded-lg italic">
                    注：教师权限默认为普通教师。如需分配专业主任或班级班主任身份，请前往“专业管理”或“课程管理”页面进行指定。
                </p>
                <div class="pt-6 flex gap-4">
                    <button type="button" onclick="closeModal()" class="flex-1 font-bold text-slate-400">取消</button>
                    <button type="button" id="saveBtn" class="flex-[2] bg-slate-900 text-white py-4 rounded-xl font-bold">保存档案</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        let currentModalMode = 'add';
        let editingUuid = null;

        document.addEventListener('DOMContentLoaded', function() {
            // 筛选逻辑
            document.getElementById('filterApply').addEventListener('click', function() {
                const year = document.getElementById('filter_year').value;
                window.location.href = 'admin_teachers.php?year=' + year;
            });
            document.getElementById('filterReset').addEventListener('click', function() {
                window.location.href = 'admin_teachers.php';
            });

            // 全选与批量操作
            const selectAll = document.getElementById('selectAll');
            const deleteSelected = document.getElementById('deleteSelected');
            const resetSelected = document.getElementById('resetSelected');
            const checkboxes = document.querySelectorAll('.teacher-checkbox');

            function updateButtons() {
                const hasChecked = Array.from(checkboxes).some(cb => cb.checked);
                deleteSelected.classList.toggle('hidden', !hasChecked);
                resetSelected.classList.toggle('hidden', !hasChecked);
            }

            selectAll.addEventListener('change', function() {
                checkboxes.forEach(cb => cb.checked = this.checked);
                updateButtons();
            });
            checkboxes.forEach(cb => cb.addEventListener('change', updateButtons));

            // 保存逻辑 (AJAX 调用 api_teachers.php)
            document.getElementById('saveBtn').addEventListener('click', function() {
                const uuid = document.getElementById('m_uuid').value.trim();
                const name = document.getElementById('m_name').value.trim();

                if (!uuid || !name) {
                    alert('工号和姓名不能为空');
                    return;
                }

                const payload = {
                    action: currentModalMode === 'add' ? 'add' : 'update',
                    uuid: uuid,
                    name: name
                };
                if (currentModalMode === 'edit') payload.old_uuid = editingUuid;

                fetch('api_teachers.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                })
                .then(res => res.json())
                .then(data => {
                    if (data.success) location.reload();
                    else alert('操作失败: ' + data.message);
                })
                .catch(err => alert('请求失败: ' + err));
            });
        });

        function openModal(type, uuid, name = '') {
            currentModalMode = type;
            editingUuid = type === 'edit' ? uuid : null;
            document.getElementById('modalTitle').innerText = type === 'add' ? '注册新教师' : '编辑教师信息';
            document.getElementById('m_uuid').value = uuid;
            document.getElementById('m_name').value = name;
            document.getElementById('sideModal').classList.remove('hidden');
        }

        function closeModal() {
            document.getElementById('sideModal').classList.add('hidden');
        }

        // 单个删除与重置密码逻辑 (需对应 api_teachers.php)
        window.deleteTeacher = function(uuid) {
            if (confirm('确定要删除该教师吗？这将撤销其所有关联职务，请务必确认与该教师相关的数据已经清理完成。')) {
                fetch('api_teachers.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'delete', uuid: uuid })
                })
                .then(res => res.json())
                .then(data => { if (data.success) location.reload(); else alert(data.message); });
            }
        };

        window.resetPassword = function(uuid) {
            if (confirm('确定要重置该教师的密码吗？')) {
                fetch('api_teachers.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'reset_password', uuid: uuid })
                })
                .then(res => res.json())
                .then(data => { if (data.success) alert('密码已重置为初始密码'); });
            }
        };

        function exportTeachers() { window.location.href = 'export_teachers.php'; }
        
        function importTeachers(input) {
            if (!input.files.length) return;
            const formData = new FormData();
            formData.append('file', input.files[0]);
            fetch('api_import_teachers.php', { method: 'POST', body: formData })
            .then(res => res.json())
            .then(data => {
                if (data.success) { alert('导入成功'); location.reload(); }
                else alert('导入失败: ' + data.message);
            });
        }
    </script>
</body>
</html>