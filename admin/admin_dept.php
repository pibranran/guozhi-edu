<?php
require_once __DIR__ . "/../config.php";

// 1. 权限与身份获取
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'admin') {
    header("Location: /../index.php");
    exit();
}
$role = $_SESSION['role'] ?? 'admin';

// 2. 获取所有教师（用于主任下拉框）
$teachers_json = [];
$teachers_res = $conn->query("SELECT uuid, name FROM teachers ORDER BY name ASC");
if ($teachers_res) {
    while ($t = $teachers_res->fetch_assoc()) {
        $teachers_json[] = $t;
    }
}

// 3. 生成建议专业ID（MAX + 1）
$next_id_res = $conn->query("SELECT IFNULL(MAX(major_id), 0) + 1 as next_id FROM majors");
$next_major_id = 1;
if ($next_id_res) {
    $row = $next_id_res->fetch_assoc();
    $next_major_id = (int)$row['next_id'];
}

// 4. 分页设置
$perPage = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 20;
$currentPage = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($currentPage < 1) $currentPage = 1;

$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// 5. 构建筛选条件（仅搜索专业ID或名称）
$where = [];
$params = [];
$types = "";
if ($search) {
    $where[] = "(major_name LIKE ? OR CAST(major_id AS CHAR) LIKE ?)";
    $t = "%$search%";
    $params[] = $t;
    $params[] = $t;
    $types .= "ss";
}
$whereClause = $where ? "WHERE " . implode(" AND ", $where) : "";

// 6. 总记录数
$totalSql = "SELECT COUNT(*) as total FROM majors $whereClause";
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

// 8. 获取当前页专业数据（关联主任姓名）
$sql = "SELECT m.major_id, m.major_name, m.dean_uuid, 
               COALESCE(t.name, '未分配主任') as dean_name 
        FROM majors m 
        LEFT JOIN teachers t ON m.dean_uuid = t.uuid 
        $whereClause 
        ORDER BY m.major_id ASC LIMIT ? OFFSET ?";
$stmt = $conn->prepare($sql);
$allParams = array_merge($params, [$perPage, $offset]);
$allTypes = $types . "ii";
$stmt->bind_param($allTypes, ...$allParams);
$stmt->execute();
$res = $stmt->get_result();

// 9. 分页链接查询字符串
$queryParams = [];
if ($search) $queryParams['search'] = $search;
$queryString = http_build_query($queryParams);
$baseUrl = 'admin_dept.php?' . ($queryString ? $queryString . '&' : '');
?>

<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <script src="https://cdn.tailwindcss.com"></script>
    <title>专业管理 - EDU.AMS</title>
    <link rel="icon" type="image/png" href="/../jiaowu.png">
</head>
<body class="flex h-screen bg-slate-50 overflow-hidden">
    <?php include __DIR__ . "/../sidebar.php"; ?>
    <main class="flex-1 flex flex-col min-w-0">
        <header class="h-20 bg-white border-b flex items-center justify-between px-8 shrink-0">
            <h1 class="text-xl font-bold">专业管理</h1>
            <form method="GET" class="relative">
                <input type="text" name="search" placeholder="搜索专业名称或ID..." value="<?=htmlspecialchars($search)?>" class="bg-slate-100 rounded-xl px-10 py-2 text-sm outline-none">
            </form>
        </header>
        <div class="p-8 flex-1 overflow-auto">
            <div class="max-w-5xl mx-auto">
                <!-- 操作栏 -->
                <div class="flex justify-between mb-6">
                    <p class="text-slate-400">当前共有专业名单</p>
                    <div class="space-x-2">
                        <button onclick="exportMajors()" class="bg-green-600 text-white px-4 py-2 rounded-xl font-bold">📎 导出 Excel</button>
                        <button onclick="openModal('add', '<?=$next_major_id?>', '', '')" class="bg-slate-900 text-white px-6 py-2 rounded-xl font-bold">+ 添加专业</button>
                    </div>
                </div>

                <!-- 多选操作栏 -->
                <div class="flex justify-between mb-4 flex-wrap gap-4">
                    <div class="flex items-center space-x-4">
                        <div class="flex items-center">
                            <input type="checkbox" id="selectAll" class="form-checkbox h-4 w-4 text-indigo-600 mr-2">
                            <span class="text-sm text-slate-400">全选</span>
                        </div>
                        <button id="deleteSelected" class="hidden bg-red-500 text-white px-4 py-2 rounded text-sm">删除选中的专业</button>
                    </div>
                </div>

                <!-- 专业列表表格 -->
                <div class="bg-white border rounded-[24px] overflow-hidden shadow-sm">
                    <table class="w-full text-left">
                        <thead class="bg-slate-50 border-b text-[11px] text-slate-400 uppercase font-bold">
                            <tr>
                                <th class="p-5">选择</th>
                                <th class="p-5">专业ID</th>
                                <th class="p-5">专业名称</th>
                                <th class="p-5">主任</th>
                                <th class="p-5 text-center">操作</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php while ($row = $res->fetch_assoc()): ?>
                            <tr class="hover:bg-slate-50 transition-colors">
                                <td class="p-5"><input type="checkbox" class="major-checkbox form-checkbox h-4 w-4 text-indigo-600" value="<?= $row['major_id'] ?>"></td>
                                <td class="p-5 font-mono text-xs text-slate-400"><?= $row['major_id'] ?></td>
                                <td class="p-5 font-bold"><?= htmlspecialchars($row['major_name']) ?></td>
                                <td class="p-5 text-xs"><?= htmlspecialchars($row['dean_name']) ?></td>
                                <td class="p-5 text-center">
                                    <button onclick="openModal('edit', '<?= $row['major_id'] ?>', '<?= htmlspecialchars($row['major_name']) ?>', '<?= $row['dean_uuid'] ?>')" class="text-amber-600 mr-2">✏️</button>
                                    <button onclick="deleteMajor('<?= $row['major_id'] ?>')" class="text-red-500">🗑️</button>
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
                            <a href="<?= $baseUrl ?>page=<?= $currentPage-1 ?>&per_page=<?= $perPage ?>" class="px-3 py-1 bg-slate-200 rounded hover:bg-slate-300 text-slate-700">上一页</a>
                        <?php endif; ?>
                        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                            <a href="<?= $baseUrl ?>page=<?= $i ?>&per_page=<?= $perPage ?>" class="px-3 py-1 <?= $i == $currentPage ? 'bg-slate-900 text-white' : 'bg-slate-200 rounded hover:bg-slate-300 text-slate-700' ?>"><?= $i ?></a>
                        <?php endfor; ?>
                        <?php if ($currentPage < $totalPages): ?>
                            <a href="<?= $baseUrl ?>page=<?= $currentPage+1 ?>&per_page=<?= $perPage ?>" class="px-3 py-1 bg-slate-200 rounded hover:bg-slate-300 text-slate-700">下一页</a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- 侧滑模态框（添加/编辑） -->
    <div id="sideModal" class="hidden fixed inset-0 bg-slate-900/50 backdrop-blur-sm z-50 flex justify-end">
        <div class="w-[400px] bg-white h-full p-8 shadow-2xl">
            <h2 id="modalTitle" class="text-xl font-bold mb-10">添加专业</h2>
            <div class="space-y-6">
                <div>
                    <label class="block text-[10px] font-bold text-slate-400 uppercase mb-2">专业编号</label>
                    <input type="text" id="m_id" class="w-full bg-slate-100 rounded-xl px-4 py-3 outline-none">
                </div>
                <div>
                    <label class="block text-[10px] font-bold text-slate-400 uppercase mb-2">专业名称</label>
                    <input type="text" id="m_name" class="w-full bg-slate-100 rounded-xl px-4 py-3 outline-none border-2 border-transparent focus:border-amber-500">
                </div>
                <div>
                    <label class="block text-[10px] font-bold text-slate-400 uppercase mb-2">选择主任</label>
                    <select id="m_dean" class="w-full bg-slate-100 rounded-xl px-4 py-3 outline-none">
                        <option value="">-- 请选择主任 --</option>
                    </select>
                </div>
                <div class="pt-6 flex gap-4">
                    <button type="button" onclick="closeModal()" class="flex-1 font-bold text-slate-400">取消</button>
                    <button type="button" id="saveBtn" class="flex-[2] bg-slate-900 text-white py-4 rounded-xl font-bold">保存专业</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        const teacherData = <?= json_encode($teachers_json) ?>;
        let currentModalMode = 'add';
        let editingId = null;

        // 页面加载初始化
        document.addEventListener('DOMContentLoaded', function() {
            const selectAll = document.getElementById('selectAll');
            const deleteSelected = document.getElementById('deleteSelected');
            const checkboxes = document.querySelectorAll('.major-checkbox');

            function updateButtons() {
                const checked = Array.from(checkboxes).filter(cb => cb.checked);
                deleteSelected.classList.toggle('hidden', checked.length === 0);
            }

            selectAll.addEventListener('change', function() {
                checkboxes.forEach(cb => cb.checked = this.checked);
                updateButtons();
            });
            checkboxes.forEach(cb => cb.addEventListener('change', updateButtons));
            updateButtons();

            // 批量删除
            deleteSelected.addEventListener('click', function() {
                const ids = Array.from(checkboxes).filter(cb => cb.checked).map(cb => cb.value);
                if (ids.length === 0) return;
                if (confirm(`确定要删除 ${ids.length} 个专业吗？（关联班级将被影响）`)) {
                    fetch('api_majors.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ action: 'delete_batch', major_ids: ids })
                    })
                    .then(res => res.json())
                    .then(data => {
                        if (data.success) location.reload();
                        else alert('删除失败: ' + data.message);
                    })
                    .catch(err => alert('请求失败: ' + err));
                }
            });

            // 保存按钮
            document.getElementById('saveBtn').addEventListener('click', function() {
                const id = document.getElementById('m_id').value.trim();
                const name = document.getElementById('m_name').value.trim();
                const deanUuid = document.getElementById('m_dean').value || null;

                if (!id || !name) {
                    alert('专业编号和名称不能为空');
                    return;
                }

                const payload = {
                    action: currentModalMode === 'add' ? 'add' : 'update',
                    major_id: id,
                    major_name: name,
                    dean_uuid: deanUuid
                };
                if (currentModalMode === 'edit') payload.old_id = editingId;

                fetch('api_majors.php', {
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
        function exportMajors() {
            window.location.href = 'export_majors.php';
        }

        // 导入 Excel
        function importMajors(input) {
            if (!input.files.length) return;
            const file = input.files[0];
            const formData = new FormData();
            formData.append('file', file);
            fetch('api_import_majors.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    alert(`导入成功！共处理 ${data.inserted} 条，跳过 ${data.skipped} 条重复专业ID。`);
                    location.reload();
                } else {
                    alert('导入失败：' + data.message);
                }
            })
            .catch(err => alert('请求失败：' + err));
            input.value = '';
        }

        // 单个删除
        window.deleteMajor = function(id) {
            if (confirm('确定要删除这个专业吗？')) {
                fetch('api_majors.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'delete', major_id: id })
                })
                .then(res => res.json())
                .then(data => {
                    if (data.success) location.reload();
                    else alert('删除失败: ' + data.message);
                })
                .catch(err => alert('请求失败: ' + err));
            }
        };

        // 主任下拉填充
        function populateDeanSelect(selected = '') {
            const select = document.getElementById('m_dean');
            select.innerHTML = '<option value="">-- 请选择主任 --</option>';
            teacherData.forEach(t => {
                const opt = document.createElement('option');
                opt.value = t.uuid;
                opt.textContent = `${t.name} (${t.uuid})`;
                if (t.uuid === selected) opt.selected = true;
                select.appendChild(opt);
            });
        }

        // 打开模态框
        function openModal(type, id, name = '', deanUuid = '') {
            currentModalMode = type;
            editingId = type === 'edit' ? id : null;
            const modal = document.getElementById('sideModal');
            document.getElementById('modalTitle').innerText = type === 'add' ? '添加新专业' : '编辑专业';
            document.getElementById('m_id').value = id;
            document.getElementById('m_name').value = name;
            populateDeanSelect(deanUuid);
            modal.classList.remove('hidden');
        }

        function closeModal() {
            document.getElementById('sideModal').classList.add('hidden');
        }

        function changePerPage(perPage) {
            window.location.href = "<?= $baseUrl ?>page=1&per_page=" + perPage;
        }
    </script>
</body>
</html>