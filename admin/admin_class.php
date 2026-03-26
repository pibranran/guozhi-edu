<?php
require_once __DIR__ . "/../config.php";

// 1. 权限与身份验证
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'admin') {
    header("Location: /../index.php");
    exit();
}

// 2. 获取专业数据（一级筛选菜单）
$all_majors = [];
$major_res = $conn->query("SELECT major_id, major_name FROM majors ORDER BY major_name ASC");
if ($major_res) {
    while ($m = $major_res->fetch_assoc()) {
        $all_majors[] = $m;
    }
}

// 🔥 新增：获取所有教师（用于班主任下拉）
$all_teachers = [];
$teacher_res = $conn->query("SELECT uuid, name FROM teachers ORDER BY name ASC");
if ($teacher_res) {
    while ($t = $teacher_res->fetch_assoc()) {
        $all_teachers[] = $t;
    }
}

// 3. 分页设置
$perPage = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 20;
$currentPage = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($currentPage < 1) $currentPage = 1;

// 4. 筛选/搜索参数
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$major_filter = isset($_GET['major']) ? (int)$_GET['major'] : 0;

// 5. 构建筛选条件
$where = [];
$params = [];
$types = "";

if ($search) {
    $where[] = "(class_name LIKE ? OR class_id LIKE ?)";
    $t = "%$search%";
    $params[] = $t;
    $params[] = $t;
    $types .= "ss";
}
if ($major_filter) {
    $where[] = "major_id = ?";
    $params[] = $major_filter;
    $types .= "i";
}
$whereClause = $where ? "WHERE " . implode(" AND ", $where) : "";

// 6. 总记录数
$totalSql = "SELECT COUNT(*) as total FROM classes $whereClause";
$totalStmt = $conn->prepare($totalSql);
if ($types) {
    $totalStmt->bind_param($types, ...$params);
}
$totalStmt->execute();
$totalResult = $totalStmt->get_result();
$totalRow = $totalResult->fetch_assoc();
$totalRecords = $totalRow['total'];
$totalPages = ceil($totalRecords / $perPage);

// 7. 计算OFFSET
$offset = ($currentPage - 1) * $perPage;

// 8. 获取当前页班级数据（🔥 关联班主任）
$sql = "SELECT c.class_id, c.class_name, c.major_id, c.headmaster_uuid,
               t.name AS teacher_name
        FROM classes c
        LEFT JOIN teachers t ON c.headmaster_uuid = t.uuid
        $whereClause
        ORDER BY class_id DESC LIMIT ? OFFSET ?";

$stmt = $conn->prepare($sql);
$allParams = array_merge($params, [$perPage, $offset]);
$allTypes = $types . "ii";
if ($allTypes) {
    $stmt->bind_param($allTypes, ...$allParams);
}
$stmt->execute();
$res = $stmt->get_result();

// 9. 构建分页链接查询字符串
$queryParams = [];
if ($search) $queryParams['search'] = $search;
if ($major_filter) $queryParams['major'] = $major_filter;
$queryString = http_build_query($queryParams);
$baseUrl = 'admin_class.php?' . ($queryString ? $queryString . '&' : '');

// 10. 构建专业名称映射
$major_map = [];
foreach ($all_majors as $m) {
    $major_map[$m['major_id']] = $m['major_name'];
}
?>

<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <script src="https://cdn.tailwindcss.com"></script>
    <title>班级管理 - EDU.AMS</title>
    <link rel="icon" type="image/png" href="/../jiaowu.png">
</head>
<body class="flex h-screen bg-slate-50 overflow-hidden">
    <?php include __DIR__ . "/../sidebar.php"; ?>
    <main class="flex-1 flex flex-col min-w-0">
        <header class="h-20 bg-white border-b flex items-center justify-between px-8 shrink-0">
            <h1 class="text-xl font-bold">班级管理中心</h1>
            <form method="GET" class="relative">
                <input type="text" name="search" placeholder="搜索班级名称/编号..." value="<?=htmlspecialchars($search)?>" class="bg-slate-100 rounded-xl px-10 py-2 text-sm outline-none">
            </form>
        </header>
        <div class="p-8 flex-1 overflow-auto">
            <div class="max-w-5xl mx-auto">
                <div class="flex justify-between mb-6">
                    <p class="text-slate-400">当前共有班级 <?= $totalRecords ?> 个</p>
                    <div class="space-x-2">
                        <button onclick="exportClasses()" class="bg-green-600 text-white px-4 py-2 rounded-xl font-bold">📎 导出 Excel</button>
                        <label class="bg-blue-600 text-white px-4 py-2 rounded-xl font-bold cursor-pointer">
                            📂 导入 Excel
                            <input type="file" id="importFile" accept=".xlsx, .xls" class="hidden" onchange="importClasses(this)">
                        </label>
                        <button onclick="openModal('add')" class="bg-slate-900 text-white px-6 py-2 rounded-xl font-bold">+ 新增班级</button>
                    </div>
                </div>

                <div class="flex justify-between mb-4 flex-wrap gap-4">
                    <div class="flex items-center space-x-4">
                        <div class="flex items-center">
                            <input type="checkbox" id="selectAll" class="form-checkbox h-4 w-4 text-indigo-600 mr-2">
                            <span class="text-sm text-slate-400">全选</span>
                        </div>
                        <button id="deleteSelected" class="hidden bg-red-500 text-white px-4 py-2 rounded text-sm">删除选中的班级</button>
                    </div>
                    <div class="flex space-x-2">
                        <select id="filter_major" class="bg-slate-100 rounded px-3 py-1 text-sm">
                            <option value="">所有专业</option>
                            <?php foreach($all_majors as $m): ?>
                                <option value="<?=$m['major_id']?>" <?=($major_filter == $m['major_id']) ? 'selected' : ''?>><?=$m['major_name']?></option>
                            <?php endforeach; ?>
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
                                <th class="p-5">班级编号</th>
                                <th class="p-5">班级名称</th>
                                <th class="p-5">所属专业</th>
                                <th class="p-5">班主任</th>
                                <th class="p-5 text-center">操作</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php while ($row = $res->fetch_assoc()): ?>
                            <tr class="hover:bg-slate-50 transition-colors">
                                <td class="p-5"><input type="checkbox" class="class-checkbox form-checkbox h-4 w-4 text-indigo-600" value="<?= $row['class_id'] ?>"></td>
                                <td class="p-5 font-mono text-xs text-slate-400"><?=$row['class_id']?></td>
                                <td class="p-5 font-bold"><?=$row['class_name']?></td>
                                <td class="p-5 text-xs"><?=$major_map[$row['major_id']] ?? '未分配'?></td>
                                <td class="p-5 text-xs"><?=$row['teacher_name'] ?? '未设置'?></td>
                                <td class="p-5 text-center">
                                    <button onclick="openModal('edit', '<?=$row['class_id']?>', '<?=htmlspecialchars($row['class_name'])?>', '<?=$row['major_id']?>', '<?=$row['headmaster_uuid']?>')" class="text-amber-600 mr-2">✏️</button>
                                    <button onclick="deleteClass('<?=$row['class_id']?>')" class="text-red-500">🗑️</button>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>

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

    <!-- 侧滑模态框（原样不动 + 只加班主任下拉） -->
    <div id="sideModal" class="hidden fixed inset-0 bg-slate-900/50 backdrop-blur-sm z-50 flex justify-end">
        <div class="w-[400px] bg-white h-full p-8 shadow-2xl">
            <h2 id="modalTitle" class="text-xl font-bold mb-10">新增班级</h2>
            <div class="space-y-6">
                <div>
                    <label class="block text-[10px] font-bold text-slate-400 uppercase mb-2">班级编号</label>
                    <input type="text" id="m_class_id" class="w-full bg-slate-100 rounded-xl px-4 py-3 outline-none">
                </div>
                <div>
                    <label class="block text-[10px] font-bold text-slate-400 uppercase mb-2">班级名称</label>
                    <input type="text" id="m_class_name" class="w-full bg-slate-100 rounded-xl px-4 py-3 outline-none border-2 border-transparent focus:border-amber-500">
                </div>
                <div>
                    <label class="block text-[10px] font-bold text-slate-400 uppercase mb-2">所属专业</label>
                    <select id="m_major_id" class="w-full bg-slate-100 rounded-xl px-4 py-3 outline-none">
                        <option value="">-- 请选择专业 --</option>
                        <?php foreach($all_majors as $m): ?>
                            <option value="<?=$m['major_id']?>"><?=$m['major_name']?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- 🔥 只在这里加了班主任，样式完全沿用你的 -->
                <div>
                    <label class="block text-[10px] font-bold text-slate-400 uppercase mb-2">班主任</label>
                    <select id="m_headmaster_uuid" class="w-full bg-slate-100 rounded-xl px-4 py-3 outline-none">
                        <option value="">-- 不设置 --</option>
                        <?php foreach($all_teachers as $t): ?>
                            <option value="<?=$t['uuid']?>"><?=$t['name']?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="pt-6 flex gap-4">
                    <button type="button" onclick="closeModal()" class="flex-1 font-bold text-slate-400">取消</button>
                    <button type="button" id="saveBtn" class="flex-[2] bg-slate-900 text-white py-4 rounded-xl font-bold">保存班级</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        let currentModalMode = 'add';
        let editingClassId = null;

        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('filterApply').addEventListener('click', applyFilters);
            document.getElementById('filterReset').addEventListener('click', function() {
                window.location.href = 'admin_class.php';
            });

            const selectAll = document.getElementById('selectAll');
            const deleteSelected = document.getElementById('deleteSelected');
            const checkboxes = document.querySelectorAll('.class-checkbox');

            function updateButtons() {
                const checked = Array.from(checkboxes).filter(cb => cb.checked);
                deleteSelected.classList.toggle('hidden', !checked.length);
            }

            selectAll.addEventListener('change', function() {
                checkboxes.forEach(cb => cb.checked = this.checked);
                updateButtons();
            });
            checkboxes.forEach(cb => cb.addEventListener('change', updateButtons));
            updateButtons();

            deleteSelected.addEventListener('click', function() {
                const classIds = Array.from(checkboxes).filter(cb => cb.checked).map(cb => cb.value);
                if (!classIds.length) return;
                if (confirm(`确定要删除 ${classIds.length} 个班级吗？`)) {
                    fetch('api_classes.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ action: 'delete_batch', class_ids: classIds })
                    })
                    .then(res => res.json())
                    .then(data => {
                        if (data.success) location.reload();
                        else alert('删除失败: ' + data.message);
                    })
                    .catch(err => alert('请求失败: ' + err));
                }
            });

            document.getElementById('saveBtn').addEventListener('click', function() {
                const class_id = document.getElementById('m_class_id').value.trim();
                const class_name = document.getElementById('m_class_name').value.trim();
                const major_id = document.getElementById('m_major_id').value;
                const headmaster_uuid = document.getElementById('m_headmaster_uuid').value;

                if (!class_id || !class_name || !major_id) {
                    alert('班级编号、名称和所属专业不能为空');
                    return;
                }

                const payload = {
                    action: currentModalMode === 'add' ? 'add' : 'update',
                    class_id: class_id,
                    class_name: class_name,
                    major_id: major_id,
                    headmaster_uuid: headmaster_uuid
                };
                if (currentModalMode === 'edit') payload.old_class_id = editingClassId;

                fetch('api_classes.php', {
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

        function exportClasses() {
            window.location.href = 'export_classes.php';
        }

        function importClasses(input) {
            if (!input.files.length) return;
            const file = input.files[0];
            const formData = new FormData();
            formData.append('file', file);
            fetch('api_import_classes.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    alert(`导入成功！新增 ${data.inserted} 个，跳过 ${data.skipped} 个`);
                    location.reload();
                } else {
                    alert('导入失败：' + data.message);
                }
            })
            .catch(err => alert('请求失败：' + err));
            input.value = '';
        }

        window.deleteClass = function(classId) {
            if (confirm('确定要删除这个班级吗？')) {
                fetch('api_classes.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'delete', class_id: classId })
                })
                .then(res => res.json())
                .then(data => {
                    if (data.success) location.reload();
                    else alert('删除失败: ' + data.message);
                })
                .catch(err => alert('请求失败: ' + err));
            }
        };

        function applyFilters() {
            const major = document.getElementById('filter_major').value;
            const search = document.querySelector('input[name="search"]').value.trim();
            let url = 'admin_class.php?';
            const params = [];
            if (major) params.push('major=' + major);
            if (search) params.push('search=' + encodeURIComponent(search));
            if (params.length) url += params.join('&');
            window.location.href = url;
        }

        // 🔥 打开弹窗时自动回填班主任
        function openModal(type, classId = '', className = '', majorId = '', headmasterUuid = '') {
            currentModalMode = type;
            editingClassId = type === 'edit' ? classId : null;
            const modal = document.getElementById('sideModal');
            document.getElementById('modalTitle').innerText = type === 'add' ? '新增班级' : '编辑班级';
            document.getElementById('m_class_id').value = classId;
            document.getElementById('m_class_name').value = className;
            document.getElementById('m_major_id').value = majorId;
            document.getElementById('m_headmaster_uuid').value = headmasterUuid;

            if (type === 'edit') {
                document.getElementById('m_class_id').readOnly = true;
            } else {
                document.getElementById('m_class_id').readOnly = false;
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