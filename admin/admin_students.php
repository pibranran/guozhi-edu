<?php
require_once __DIR__ . "/../config.php";

// 1. 权限与身份获取
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit();
}
$role = $_SESSION['role'] ?? 'admin';

// 2. 获取专业 (Majors) 和 班级 (Classes)
$all_majors = [];
$major_res = $conn->query("SELECT major_id, major_name AS dept_name FROM majors ORDER BY major_name ASC");
if ($major_res) {
    while ($m = $major_res->fetch_assoc()) {
        $all_majors[] = $m;
    }
}

$classes_json = [];
$classes_map = [];
$class_res = $conn->query("SELECT class_id, class_name, major_id FROM classes ORDER BY class_name ASC");
if ($class_res) {
    while ($c = $class_res->fetch_assoc()) {
        $c['major_id'] = (int)$c['major_id'];
        $classes_json[] = $c;
        $classes_map[$c['class_id']] = $c['class_name'];
    }
}

// 3. 生成建议学号（根据当前年份）
$currentYear = date('Y');
$next_uuid = $currentYear . '0001';
$max_res = $conn->query("SELECT MAX(uuid) as max_uuid FROM students");
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
            $next_uuid = $currentYear . '0001';
        }
    }
}

// 4. 分页设置
$perPage = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 20;
$currentPage = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($currentPage < 1) $currentPage = 1;

$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$major_filter = isset($_GET['major']) ? (int)$_GET['major'] : 0;
$class_filter = isset($_GET['class']) ? (int)$_GET['class'] : 0;
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
if ($major_filter) {
    $where[] = "class_id IN (SELECT class_id FROM classes WHERE major_id = ?)";
    $params[] = $major_filter;
    $types .= "i";
}
if ($class_filter) {
    $where[] = "class_id = ?";
    $params[] = $class_filter;
    $types .= "i";
}
if ($year_filter) {
    $where[] = "LEFT(uuid, 4) = ?";
    $params[] = $year_filter;
    $types .= "s";
}
$whereClause = $where ? "WHERE " . implode(" AND ", $where) : "";

// 6. 总记录数
$totalSql = "SELECT COUNT(*) as total FROM students $whereClause";
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

// 8. 获取当前页学生数据
$sql = "SELECT uuid, name, class_id FROM students $whereClause ORDER BY uuid DESC LIMIT ? OFFSET ?";
$stmt = $conn->prepare($sql);
$allParams = array_merge($params, [$perPage, $offset]);
$allTypes = $types . "ii";
if ($allTypes) {
    $stmt->bind_param($allTypes, ...$allParams);
}
$stmt->execute();
$res = $stmt->get_result();

// 9. 构建分页链接的查询字符串
$queryParams = [];
if ($search) $queryParams['search'] = $search;
if ($major_filter) $queryParams['major'] = $major_filter;
if ($class_filter) $queryParams['class'] = $class_filter;
if ($year_filter) $queryParams['year'] = $year_filter;
$queryString = http_build_query($queryParams);
$baseUrl = 'admin_students.php?' . ($queryString ? $queryString . '&' : '');
?>

<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <script src="https://cdn.tailwindcss.com"></script>
    <title>学生管理 - EDU.AMS</title>
</head>
<body class="flex h-screen bg-slate-50 overflow-hidden">
    <?php include __DIR__ . "/../sidebar.php";; ?>
    <main class="flex-1 flex flex-col min-w-0">
        <header class="h-20 bg-white border-b flex items-center justify-between px-8 shrink-0">
            <h1 class="text-xl font-bold">档案中心</h1>
            <form method="GET" class="relative">
                <input type="text" name="search" placeholder="搜索..." value="<?=htmlspecialchars($search)?>" class="bg-slate-100 rounded-xl px-10 py-2 text-sm outline-none">
            </form>
        </header>
        <div class="p-8 flex-1 overflow-auto">
            <div class="max-w-5xl mx-auto">
                <!-- 操作栏：添加、导出、导入按钮 -->
                <div class="flex justify-between mb-6">
                    <p class="text-slate-400">当前共有学生名单</p>
                    <div class="space-x-2">
                        <button onclick="exportStudents()" class="bg-green-600 text-white px-4 py-2 rounded-xl font-bold">📎 导出 Excel</button>
                        <label class="bg-blue-600 text-white px-4 py-2 rounded-xl font-bold cursor-pointer">
                            📂 导入 Excel
                            <input type="file" id="importFile" accept=".xlsx, .xls" class="hidden" onchange="importStudents(this)">
                        </label>
                        <button onclick="openModal('add', '<?=$next_uuid?>')" class="bg-slate-900 text-white px-6 py-2 rounded-xl font-bold">+ 注册学生</button>
                    </div>
                </div>

                <!-- 多选操作栏 + 筛选栏 -->
                <div class="flex justify-between mb-4 flex-wrap gap-4">
                    <div class="flex items-center space-x-4">
                        <div class="flex items-center">
                            <input type="checkbox" id="selectAll" class="form-checkbox h-4 w-4 text-indigo-600 mr-2">
                            <span class="text-sm text-slate-400">全选</span>
                        </div>
                        <button id="deleteSelected" class="hidden bg-red-500 text-white px-4 py-2 rounded text-sm">删除选中的学生</button>
                        <button id="resetSelected" class="hidden bg-yellow-500 text-white px-4 py-2 rounded text-sm">重置密码</button>
                    </div>
                    <div class="flex space-x-2">
                        <select id="filter_major" class="bg-slate-100 rounded px-3 py-1 text-sm">
                            <option value="">所有专业</option>
                            <?php foreach($all_majors as $m): ?>
                                <option value="<?=$m['major_id']?>" <?=($major_filter == $m['major_id']) ? 'selected' : ''?>><?=$m['dept_name']?></option>
                            <?php endforeach; ?>
                        </select>
                        <select id="filter_class" class="bg-slate-100 rounded px-3 py-1 text-sm">
                            <option value="">所有班级</option>
                        </select>
                        <select id="filter_year" class="bg-slate-100 rounded px-3 py-1 text-sm">
                            <option value="">所有年份</option>
                            <?php
                            $yearSql = "SELECT DISTINCT SUBSTRING(uuid, 1, 4) as year FROM students ORDER BY year DESC";
                            $yearRes = $conn->query($yearSql);
                            if ($yearRes && $yearRes->num_rows > 0) {
                                while ($y = $yearRes->fetch_assoc()) {
                                    $selected = ($year_filter == $y['year']) ? 'selected' : '';
                                    echo "<option value='{$y['year']}' $selected>{$y['year']}</option>";
                                }
                            } else {
                                for ($i = 0; $i < 5; $i++) {
                                    $year = date('Y') - $i;
                                    $selected = ($year_filter == $year) ? 'selected' : '';
                                    echo "<option value='$year' $selected>$year</option>";
                                }
                            }
                            ?>
                        </select>
                        <button id="filterApply" class="bg-indigo-500 text-white px-4 py-1 rounded text-sm">筛选</button>
                        <button id="filterReset" class="bg-gray-300 text-gray-700 px-4 py-1 rounded text-sm">重置</button>
                    </div>
                </div>

                <!-- 学生列表表格 -->
                <div class="bg-white border rounded-[24px] overflow-hidden shadow-sm">
                    <table class="w-full text-left">
                        <thead class="bg-slate-50 border-b text-[11px] text-slate-400 uppercase font-bold">
                            <tr>
                                <th class="p-5">选择</th>
                                <th class="p-5">学号</th>
                                <th class="p-5">姓名</th>
                                <th class="p-5">班级</th>
                                <th class="p-5 text-center">操作</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php while ($row = $res->fetch_assoc()): ?>
                            <tr class="hover:bg-slate-50 transition-colors">
                                <td class="p-5"><input type="checkbox" class="student-checkbox form-checkbox h-4 w-4 text-indigo-600" value="<?= $row['uuid'] ?>"></td>
                                <td class="p-5 font-mono text-xs text-slate-400"><?=$row['uuid']?></td>
                                <td class="p-5 font-bold"><?=$row['name']?></td>
                                <td class="p-5 text-xs"><?=$classes_map[$row['class_id']] ?? '未分配'?></td>
                                <td class="p-5 text-center">
                                    <button onclick="openModal('edit', '<?=$row['uuid']?>', '<?=htmlspecialchars($row['name'])?>', '<?=$row['class_id']?>')" class="text-amber-600 mr-2">✏️</button>
                                    <button onclick="deleteStudent('<?=$row['uuid']?>')" class="text-red-500 mr-2">🗑️</button>
                                    <button onclick="resetPassword('<?=$row['uuid']?>')" class="text-blue-500">🔑</button>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>

                <!-- 分页控制 -->
                <div class="mt-6 flex flex-col sm:flex-row sm:justify-between items-center gap-4">
                    <div class="flex items-center text-sm text-slate-400">
                        <span>每页显示：</span>
                        <select id="perPageSelect" onchange="changePerPage(this.value)" class="ml-2 bg-slate-100 rounded px-2 py-1 text-sm">
                            <option value="20" <?= $perPage == 20 ? 'selected' : '' ?>>20/页</option>
                            <option value="50" <?= $perPage == 50 ? 'selected' : '' ?>>50/页</option>
                            <option value="100" <?= $perPage == 100 ? 'selected' : '' ?>>100/页</option>
                        </select>
                    </div>
                    <div class="flex items-center text-sm text-slate-400">
                        共 <?= $totalRecords ?> 条记录，当前第 <?= $currentPage ?> 页，共 <?= $totalPages ?> 页
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

    <!-- 侧滑模态框（添加/编辑） -->
    <div id="sideModal" class="hidden fixed inset-0 bg-slate-900/50 backdrop-blur-sm z-50 flex justify-end">
        <div class="w-[400px] bg-white h-full p-8 shadow-2xl">
            <h2 id="modalTitle" class="text-xl font-bold mb-10">学生注册</h2>
            <div class="space-y-6">
                <div>
                    <label class="block text-[10px] font-bold text-slate-400 uppercase mb-2">学号</label>
                    <input type="text" id="m_uuid" class="w-full bg-slate-100 rounded-xl px-4 py-3 outline-none">
                </div>
                <div>
                    <label class="block text-[10px] font-bold text-slate-400 uppercase mb-2">学生姓名</label>
                    <input type="text" id="m_name" class="w-full bg-slate-100 rounded-xl px-4 py-3 outline-none border-2 border-transparent focus:border-amber-500">
                </div>
                <div>
                    <label class="block text-[10px] font-bold text-slate-400 uppercase mb-2">选择专业</label>
                    <select id="m_major" onchange="updateClassList()" class="w-full bg-slate-100 rounded-xl px-4 py-3 outline-none">
                        <option value="">-- 请选择专业 --</option>
                        <?php foreach($all_majors as $m): ?>
                            <option value="<?=$m['major_id']?>"><?=$m['dept_name']?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-[10px] font-bold text-slate-400 uppercase mb-2">分配班级</label>
                    <select id="m_class" class="w-full bg-slate-100 rounded-xl px-4 py-3 outline-none">
                        <option value="">-- 请先选择专业 --</option>
                    </select>
                </div>
                <div class="pt-6 flex gap-4">
                    <button type="button" onclick="closeModal()" class="flex-1 font-bold text-slate-400">取消</button>
                    <button type="button" id="saveBtn" class="flex-[2] bg-slate-900 text-white py-4 rounded-xl font-bold">保存档案</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        const classData = <?=json_encode($classes_json)?>;
        let currentModalMode = 'add'; // 'add' 或 'edit'
        let editingUuid = null;

        // 页面加载时初始化
        document.addEventListener('DOMContentLoaded', function() {
            // 初始化班级筛选联动
            updateClassFilter();
            document.getElementById('filter_major').addEventListener('change', updateClassFilter);
            document.getElementById('filterApply').addEventListener('click', applyFilters);
            document.getElementById('filterReset').addEventListener('click', function() {
                window.location.href = 'admin_students.php';
            });

            // 全选/删除/重置密码 逻辑
            const selectAll = document.getElementById('selectAll');
            const deleteSelected = document.getElementById('deleteSelected');
            const resetSelected = document.getElementById('resetSelected');
            const checkboxes = document.querySelectorAll('.student-checkbox');

            function updateButtons() {
                const checked = Array.from(checkboxes).filter(cb => cb.checked);
                const hasChecked = checked.length > 0;
                deleteSelected.classList.toggle('hidden', !hasChecked);
                resetSelected.classList.toggle('hidden', !hasChecked);
            }

            selectAll.addEventListener('change', function() {
                checkboxes.forEach(cb => cb.checked = this.checked);
                updateButtons();
            });
            checkboxes.forEach(cb => cb.addEventListener('change', updateButtons));
            updateButtons();

            // 批量删除
            deleteSelected.addEventListener('click', function() {
                const uuids = Array.from(checkboxes).filter(cb => cb.checked).map(cb => cb.value);
                if (uuids.length === 0) return;
                if (confirm(`确定要删除 ${uuids.length} 个学生吗？`)) {
                    fetch('api_students.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ action: 'delete_batch', uuids: uuids })
                    })
                    .then(res => res.json())
                    .then(data => {
                        if (data.success) location.reload();
                        else alert('删除失败: ' + data.message);
                    })
                    .catch(err => alert('请求失败: ' + err));
                }
            });

            // 批量重置密码
            resetSelected.addEventListener('click', function() {
                const uuids = Array.from(checkboxes).filter(cb => cb.checked).map(cb => cb.value);
                if (uuids.length === 0) return;
                if (confirm(`确定要重置 ${uuids.length} 个学生的密码吗？`)) {
                    fetch('api_students.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ action: 'reset_password_batch', uuids: uuids })
                    })
                    .then(res => res.json())
                    .then(data => {
                        if (data.success) {
                            alert(`已重置 ${data.reset_count} 个学生的密码`);
                            location.reload();
                        } else alert('操作失败: ' + data.message);
                    })
                    .catch(err => alert('请求失败: ' + err));
                }
            });

            // 保存学生（添加/编辑）
            document.getElementById('saveBtn').addEventListener('click', function() {
                const uuid = document.getElementById('m_uuid').value.trim();
                const name = document.getElementById('m_name').value.trim();
                const classId = document.getElementById('m_class').value;

                if (!uuid || !name) {
                    alert('学号和姓名不能为空');
                    return;
                }

                const payload = {
                    action: currentModalMode === 'add' ? 'add' : 'update',
                    uuid: uuid,
                    name: name,
                    class_id: classId || null
                };
                if (currentModalMode === 'edit') payload.old_uuid = editingUuid;

                fetch('api_students.php', {
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

        // 导出 Excel
        function exportStudents() {
            window.location.href = 'export_students.php';
        }

        // 导入 Excel
        function importStudents(input) {
            if (!input.files.length) return;
            const file = input.files[0];
            const formData = new FormData();
            formData.append('file', file);
            fetch('api_import_students.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    alert(`导入成功！共处理 ${data.inserted} 条，跳过 ${data.skipped} 条重复学号。`);
                    location.reload();
                } else {
                    alert('导入失败：' + data.message);
                }
            })
            .catch(err => alert('请求失败：' + err));
            input.value = '';
        }

        // 单个删除
        window.deleteStudent = function(uuid) {
            if (confirm('确定要删除这个学生吗？')) {
                fetch('api_students.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'delete', uuid: uuid })
                })
                .then(res => res.json())
                .then(data => {
                    if (data.success) location.reload();
                    else alert('删除失败: ' + data.message);
                })
                .catch(err => alert('请求失败: ' + err));
            }
        };

        // 重置单个密码
        window.resetPassword = function(uuid) {
            if (confirm('确定要重置该学生的密码吗？')) {
                fetch('api_students.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'reset_password', uuid: uuid })
                })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        alert('密码已重置');
                        location.reload();
                    } else alert('操作失败: ' + data.message);
                })
                .catch(err => alert('请求失败: ' + err));
            }
        };

        // 筛选相关函数
        function updateClassFilter() {
            const majorId = document.getElementById('filter_major').value;
            const classSelect = document.getElementById('filter_class');
            const currentClass = classSelect.value;
            classSelect.innerHTML = '<option value="">所有班级</option>';
            const filtered = classData.filter(c => Number(c.major_id) === Number(majorId));
            filtered.forEach(c => {
                const opt = document.createElement('option');
                opt.value = c.class_id;
                opt.innerText = c.class_name;
                if (c.class_id == currentClass) opt.selected = true;
                classSelect.appendChild(opt);
            });
        }

        function applyFilters() {
            const major = document.getElementById('filter_major').value;
            const cls = document.getElementById('filter_class').value;
            const year = document.getElementById('filter_year').value;
            let url = 'admin_students.php?';
            const params = [];
            if (major) params.push('major=' + major);
            if (cls) params.push('class=' + cls);
            if (year) params.push('year=' + year);
            if (params.length) url += params.join('&');
            window.location.href = url;
        }

        // 模态框相关
        function updateClassList(selectedClassId = '') {
            const majorId = document.getElementById('m_major').value;
            const classSelect = document.getElementById('m_class');
            classSelect.innerHTML = '<option value="">-- 选择班级 --</option>';
            const filtered = classData.filter(c => Number(c.major_id) === Number(majorId));
            filtered.forEach(c => {
                const opt = document.createElement('option');
                opt.value = c.class_id;
                opt.innerText = c.class_name;
                if (c.class_id === selectedClassId) opt.selected = true;
                classSelect.appendChild(opt);
            });
        }

        function openModal(type, uuid, name = '', classId = '') {
            currentModalMode = type;
            editingUuid = type === 'edit' ? uuid : null;
            const modal = document.getElementById('sideModal');
            document.getElementById('modalTitle').innerText = type === 'add' ? '注册新学生' : '编辑档案';
            document.getElementById('m_uuid').value = uuid;
            document.getElementById('m_name').value = name;

            if (type === 'add') {
                document.getElementById('m_major').value = '';
                updateClassList();
            } else {
                const currentCls = classData.find(c => c.class_id == classId);
                if (currentCls) {
                    document.getElementById('m_major').value = currentCls.major_id;
                    updateClassList(classId);
                } else {
                    document.getElementById('m_major').value = '';
                    updateClassList();
                }
            }
            modal.classList.remove('hidden');
        }

        function closeModal() {
            document.getElementById('sideModal').classList.add('hidden');
        }

        function changePerPage(perPage) {
            window.location.href = "<?=$baseUrl?>page=1&per_page=" + perPage;
        }
    </script>
</body>
</html>