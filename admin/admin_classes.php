<?php
require_once __DIR__ . "/../config.php";

// 1. 权限与身份验证
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'admin') {
    header("Location: /../index.php");
    exit();
}
$role = $_SESSION['role'] ?? 'admin';

// 2. 分页设置
$perPage = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 20;
$currentPage = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($currentPage < 1) $currentPage = 1;

// 3. 筛选条件
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// 4. 构建筛选条件
$where = [];
$params = [];
$types = "";

if ($search) {
    $where[] = "(course_code LIKE ? OR course_name LIKE ?)";
    $t = "%$search%";
    $params[] = $t;
    $params[] = $t;
    $types .= "ss";
}
$whereClause = $where ? "WHERE " . implode(" AND ", $where) : "";

// 5. 总记录数
$totalSql = "SELECT COUNT(*) as total FROM courses $whereClause";
$totalStmt = $conn->prepare($totalSql);
if ($types) {
    $totalStmt->bind_param($types, ...$params);
}
$totalStmt->execute();
$totalResult = $totalStmt->get_result();
$totalRow = $totalResult->fetch_assoc();
$totalRecords = $totalRow['total'];
$totalPages = ceil($totalRecords / $perPage);

// 6. 计算 OFFSET
$offset = ($currentPage - 1) * $perPage;

// 7. 获取当前页课程数据
$sql = "SELECT id, course_code, course_name FROM courses $whereClause ORDER BY id DESC LIMIT ? OFFSET ?";
$stmt = $conn->prepare($sql);
$allParams = array_merge($params, [$perPage, $offset]);
$allTypes = $types . "ii";
if ($allTypes) {
    $stmt->bind_param($allTypes, ...$allParams);
}
$stmt->execute();
$res = $stmt->get_result();

// 8. 构建分页链接的查询字符串
$queryParams = [];
if ($search) $queryParams['search'] = $search;
$queryString = http_build_query($queryParams);
$baseUrl = 'admin_classes.php?' . ($queryString ? $queryString . '&' : '');
?>

<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <script src="https://cdn.tailwindcss.com"></script>
    <title>课程管理 - EDU.AMS</title>
    <link rel="icon" type="image/png" href="/../jiaowu.png">
</head>
<body class="flex h-screen bg-slate-50 overflow-hidden">
    <?php include __DIR__ . "/../sidebar.php"; ?>
    <main class="flex-1 flex flex-col min-w-0">
        <header class="h-20 bg-white border-b flex items-center justify-between px-8 shrink-0">
            <h1 class="text-xl font-bold">课程管理中心</h1>
            <form method="GET" class="relative">
                <input type="text" name="search" placeholder="搜索课程代码/名称..." value="<?=htmlspecialchars($search)?>" class="bg-slate-100 rounded-xl px-10 py-2 text-sm outline-none">
            </form>
        </header>
        <div class="p-8 flex-1 overflow-auto">
            <div class="max-w-5xl mx-auto">
                <!-- 操作栏：添加、导出按钮 -->
                <div class="flex justify-between mb-6">
                    <p class="text-slate-400">当前共有课程名单</p>
                    <div class="space-x-2">
                        <button onclick="exportCourses()" class="bg-green-600 text-white px-4 py-2 rounded-xl font-bold">📎 导出 Excel</button>
                        <label class="bg-blue-600 text-white px-4 py-2 rounded-xl font-bold cursor-pointer">
                            📂 导入 Excel
                            <input type="file" id="importFile" accept=".xlsx, .xls" class="hidden" onchange="importCourses(this)">
                        </label>
                        <button onclick="openModal('add')" class="bg-slate-900 text-white px-6 py-2 rounded-xl font-bold">+ 添加课程</button>
                    </div>
                </div>

                <!-- 多选操作栏 + 筛选重置 -->
                <div class="flex justify-between mb-4 flex-wrap gap-4">
                    <div class="flex items-center space-x-4">
                        <div class="flex items-center">
                            <input type="checkbox" id="selectAll" class="form-checkbox h-4 w-4 text-indigo-600 mr-2">
                            <span class="text-sm text-slate-400">全选</span>
                        </div>
                        <button id="deleteSelected" class="hidden bg-red-500 text-white px-4 py-2 rounded text-sm">删除选中的课程</button>
                    </div>
                    <div class="flex space-x-2">
                        <button id="filterReset" class="bg-gray-300 text-gray-700 px-4 py-1 rounded text-sm">重置筛选</button>
                    </div>
                </div>

                <!-- 课程列表表格 -->
                <div class="bg-white border rounded-[24px] overflow-hidden shadow-sm">
                    <table class="w-full text-left">
                        <thead class="bg-slate-50 border-b text-[11px] text-slate-400 uppercase font-bold">
                            <tr>
                                <th class="p-5">选择</th>
                                <th class="p-5">课程ID</th>
                                <th class="p-5">课程代码</th>
                                <th class="p-5">课程名称</th>
                                <th class="p-5 text-center">操作</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php while ($row = $res->fetch_assoc()): ?>
                            <tr class="hover:bg-slate-50 transition-colors">
                                <td class="p-5"><input type="checkbox" class="course-checkbox form-checkbox h-4 w-4 text-indigo-600" value="<?= $row['id'] ?>"></td>
                                <td class="p-5 font-mono text-xs text-slate-400"><?=$row['id']?></td>
                                <td class="p-5 font-bold"><?=$row['course_code']?></td>
                                <td class="p-5 font-bold"><?=$row['course_name']?></td>
                                <td class="p-5 text-center">
                                    <button onclick="openModal('edit', '<?=$row['id']?>', '<?=htmlspecialchars($row['course_code'])?>', '<?=htmlspecialchars($row['course_name'])?>')" class="text-amber600 mr-2">✏️</button>
                                    <button onclick="deleteCourse('<?=$row['id']?>')" class="text-red-500">🗑️</button>
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
            <h2 id="modalTitle" class="text-xl font-bold mb-10">添加课程</h2>
            <div class="space-y-6">
                <input type="hidden" id="m_id" value="">
                <div>
                    <label class="block text-[10px] font-bold text-slate-400 uppercase mb-2">课程代码</label>
                    <input type="text" id="m_course_code" class="w-full bg-slate-100 rounded-xl px-4 py-3 outline-none">
                </div>
                <div>
                    <label class="block text-[10px] font-bold text-slate-400 uppercase mb-2">课程名称</label>
                    <input type="text" id="m_course_name" class="w-full bg-slate-100 rounded-xl px-4 py-3 outline-none border-2 border-transparent focus:border-amber-500">
                </div>
                <div class="pt-6 flex gap-4">
                    <button type="button" onclick="closeModal()" class="flex-1 font-bold text-slate-400">取消</button>
                    <button type="button" id="saveBtn" class="flex-[2] bg-slate-900 text-white py-4 rounded-xl font-bold">保存课程</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        let currentModalMode = 'add';
        let editingId = null;

        document.addEventListener('DOMContentLoaded', function() {
            const selectAll = document.getElementById('selectAll');
            const deleteSelected = document.getElementById('deleteSelected');
            const checkboxes = document.querySelectorAll('.course-checkbox');

            function updateButtons() {
                const checked = Array.from(checkboxes).filter(cb => cb.checked);
                const hasChecked = checked.length > 0;
                deleteSelected.classList.toggle('hidden', !hasChecked);
            }

            selectAll.addEventListener('change', function() {
                checkboxes.forEach(cb => cb.checked = this.checked);
                updateButtons();
            });
            checkboxes.forEach(cb => cb.addEventListener('change', updateButtons));
            updateButtons();

            deleteSelected.addEventListener('click', function() {
                const ids = Array.from(checkboxes).filter(cb => cb.checked).map(cb => cb.value);
                if (ids.length === 0) return;
                if (confirm(`确定要删除 ${ids.length} 个课程吗？`)) {
                    fetch('api_courses.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ action: 'delete_batch', ids: ids })
                    })
                    .then(res => res.json())
                    .then(data => {
                        if (data.success) location.reload();
                        else alert('删除失败: ' + data.message);
                    })
                    .catch(err => alert('请求失败: ' + err));
                }
            });

            document.getElementById('filterReset').addEventListener('click', function() {
                window.location.href = 'admin_classes.php';
            });

            document.getElementById('saveBtn').addEventListener('click', function() {
                const courseCode = document.getElementById('m_course_code').value.trim();
                const courseName = document.getElementById('m_course_name').value.trim();
                const courseId = document.getElementById('m_id').value.trim();

                if (!courseCode || !courseName) {
                    alert('课程代码和课程名称不能为空');
                    return;
                }

                const payload = {
                    action: currentModalMode === 'add' ? 'add' : 'update',
                    course_code: courseCode,
                    course_name: courseName
                };
                if (currentModalMode === 'edit') payload.id = courseId;

                fetch('api_courses.php', {
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

        function exportCourses() {
            window.location.href = 'export_courses.php';
        }

        function importCourses(input) {
            if (!input.files.length) return;
            const file = input.files[0];
            const formData = new FormData();
            formData.append('file', file);
            fetch('api_import_courses.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    alert(`导入成功！共处理 ${data.inserted} 条，跳过 ${data.skipped} 条重复课程代码。`);
                    location.reload();
                } else {
                    alert('导入失败：' + data.message);
                }
            })
            .catch(err => alert('请求失败：' + err));
            input.value = '';
        }

        window.deleteCourse = function(id) {
            if (confirm('确定要删除这个课程吗？删除后关联的排课和成绩数据可能受影响！')) {
                fetch('api_courses.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'delete', id: id })
                })
                .then(res => res.json())
                .then(data => {
                    if (data.success) location.reload();
                    else alert('删除失败: ' + data.message);
                })
                .catch(err => alert('请求失败: ' + err));
            }
        };

        function openModal(type, id = '', courseCode = '', courseName = '') {
            currentModalMode = type;
            editingId = type === 'edit' ? id : null;
            const modal = document.getElementById('sideModal');
            document.getElementById('modalTitle').innerText = type === 'add' ? '添加新课程' : '编辑课程信息';
            document.getElementById('m_id').value = id;
            document.getElementById('m_course_code').value = courseCode;
            document.getElementById('m_course_name').value = courseName;
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