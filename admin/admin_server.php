<?php
/**
 * 服务器后台概览仪表盘 (整合侧边栏 + 数据库状态)
 * 需配合 sidebar.php 使用
 */

// 引入数据库配置 (config.php 位于项目根目录)
require_once __DIR__ . '/../config.php';

// 判断会话是否合法
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    // 未授权，可重定向到登录页或显示 403
    header('Location: /../index.php');
    die('无权访问该页面');
    exit();
}


// 定义侧边栏所需的路径常量（若 sidebar.php 中未定义，则补全）
if (!defined('admin_url')) {
    define('admin_url', '/admin/');
}
if (!defined('teacher_url')) {
    define('teacher_url', '/../');
}
if (!defined('headmaster_url')) {
    define('headmaster_url', '/../');
}
if (!defined('dean_url')) {
    define('dean_url', '/../');
}

// 角色变量，供侧边栏使用
$role = $_SESSION['role'] ?? 'guest';

// 关闭错误显示，避免输出敏感信息（开发时可临时开启）
error_reporting(E_ALL);
ini_set('display_errors', 0);

// 安全分隔符
define('DS', DIRECTORY_SEPARATOR);

/**
 * 检测操作系统类型
 */
if (!function_exists('getOS')) {
    function getOS() {
        $os = strtoupper(PHP_OS);
        if (strpos($os, 'WIN') !== false) return 'WIN';
        if (strpos($os, 'LINUX') !== false) return 'LINUX';
        return 'OTHER';
    }
}

/**
 * 获取 CPU 使用率 (百分比)
 * Linux: 通过读取 /proc/stat 两次采样计算
 * Windows: 通过 wmic 获取当前负载
 */
if (!function_exists('getCpuUsage')) {
    function getCpuUsage($os) {
        if ($os === 'LINUX') {
            $stat1 = @file_get_contents('/proc/stat');
            if ($stat1 === false) return null;
            $line1 = strtok($stat1, "\n");
            $vals1 = preg_split('/\s+/', trim($line1));
            array_shift($vals1);
            $idle1 = (isset($vals1[3]) ? floatval($vals1[3]) : 0) + (isset($vals1[4]) ? floatval($vals1[4]) : 0);
            $total1 = array_sum(array_map('floatval', $vals1));

            usleep(300000); // 300ms 采样间隔

            $stat2 = @file_get_contents('/proc/stat');
            if ($stat2 === false) return null;
            $line2 = strtok($stat2, "\n");
            $vals2 = preg_split('/\s+/', trim($line2));
            array_shift($vals2);
            $idle2 = (isset($vals2[3]) ? floatval($vals2[3]) : 0) + (isset($vals2[4]) ? floatval($vals2[4]) : 0);
            $total2 = array_sum(array_map('floatval', $vals2));

            $deltaTotal = $total2 - $total1;
            $deltaIdle  = $idle2 - $idle1;
            if ($deltaTotal <= 0) return 0;
            $usage = (($deltaTotal - $deltaIdle) / $deltaTotal) * 100;
            return round($usage, 2);
        } elseif ($os === 'WIN') {
            $cmd = 'wmic cpu get loadpercentage /value 2>nul';
            $output = @shell_exec($cmd);
            if ($output && preg_match('/LoadPercentage=(\d+)/', $output, $match)) {
                return (float)$match[1];
            }
            return null;
        }
        return null;
    }
}

/**
 * 获取内存使用情况 (已用百分比，总内存MB，已用内存MB)
 */
if (!function_exists('getMemoryUsage')) {
    function getMemoryUsage($os) {
        if ($os === 'LINUX') {
            $meminfo = @file_get_contents('/proc/meminfo');
            if ($meminfo === false) return null;
            $total = $available = 0;
            if (preg_match('/MemTotal:\s+(\d+)\s+kB/', $meminfo, $m)) $total = $m[1] / 1024;
            if (preg_match('/MemAvailable:\s+(\d+)\s+kB/', $meminfo, $m)) {
                $available = $m[1] / 1024;
            } else {
                $free = $buffers = $cached = 0;
                if (preg_match('/MemFree:\s+(\d+)\s+kB/', $meminfo, $m)) $free = $m[1] / 1024;
                if (preg_match('/Buffers:\s+(\d+)\s+kB/', $meminfo, $m)) $buffers = $m[1] / 1024;
                if (preg_match('/Cached:\s+(\d+)\s+kB/', $meminfo, $m)) $cached = $m[1] / 1024;
                $available = $free + $buffers + $cached;
            }
            if ($total <= 0) return null;
            $used = $total - $available;
            $percent = ($used / $total) * 100;
            return [
                'percent' => round($percent, 2),
                'total'   => round($total, 0),
                'used'    => round($used, 0),
                'free'    => round($available, 0)
            ];
        } elseif ($os === 'WIN') {
            $cmd = 'wmic OS get TotalVisibleMemorySize,FreePhysicalMemory /value 2>nul';
            $output = @shell_exec($cmd);
            if ($output && preg_match('/TotalVisibleMemorySize=(\d+)/', $output, $tMatch) && preg_match('/FreePhysicalMemory=(\d+)/', $output, $fMatch)) {
                $totalKB = (int)$tMatch[1];
                $freeKB  = (int)$fMatch[1];
                $totalMB = $totalKB / 1024;
                $usedMB  = ($totalKB - $freeKB) / 1024;
                $percent = ($usedMB / $totalMB) * 100;
                return [
                    'percent' => round($percent, 2),
                    'total'   => round($totalMB, 0),
                    'used'    => round($usedMB, 0),
                    'free'    => round($freeKB / 1024, 0)
                ];
            }
            return null;
        }
        return null;
    }
}

/**
 * 获取磁盘使用率 (根目录)
 */
if (!function_exists('getDiskUsage')) {
    function getDiskUsage() {
        $path = '/';
        if (defined('PHP_WINDOWS_VERSION_MAJOR')) $path = 'C:';
        try {
            $total = @disk_total_space($path);
            $free  = @disk_free_space($path);
            if ($total === false || $free === false) return null;
            $used = $total - $free;
            $percent = ($used / $total) * 100;
            return [
                'percent' => round($percent, 2),
                'total'   => round($total / 1024 / 1024 / 1024, 2),
                'used'    => round($used / 1024 / 1024 / 1024, 2),
                'free'    => round($free / 1024 / 1024 / 1024, 2)
            ];
        } catch (Exception $e) {
            return null;
        }
    }
}

/**
 * 获取服务器 IP 地址 (内网)
 */
if (!function_exists('getServerIP')) {
    function getServerIP() {
        if (!empty($_SERVER['SERVER_ADDR'])) {
            return $_SERVER['SERVER_ADDR'];
        }
        $hostname = gethostname();
        $ip = @gethostbyname($hostname);
        if ($ip && $ip !== $hostname) return $ip;
        if (getOS() === 'LINUX') {
            $output = @shell_exec("hostname -I 2>/dev/null | awk '{print $1}'");
            if ($output && trim($output) !== '') return trim($output);
        }
        if (getOS() === 'WIN') {
            $output = @shell_exec('ipconfig | findstr "IPv4" | findstr /v "127.0.0.1"');
            if ($output && preg_match('/\d+\.\d+\.\d+\.\d+/', $output, $match)) return $match[0];
        }
        return '未检测到';
    }
}

/**
 * 获取系统负载 (Linux 1/5/15分钟)
 */
if (!function_exists('getLoadAverage')) {
    function getLoadAverage() {
        if (function_exists('sys_getloadavg')) {
            $load = sys_getloadavg();
            if ($load !== false) {
                return [
                    '1min'  => round($load[0], 2),
                    '5min'  => round($load[1], 2),
                    '15min' => round($load[2], 2)
                ];
            }
        }
        return null;
    }
}

/**
 * 获取系统运行时间 (友好格式) - 保留备用
 */
if (!function_exists('getUptime')) {
    function getUptime($os) {
        if ($os === 'LINUX') {
            $uptimeFile = @file_get_contents('/proc/uptime');
            if ($uptimeFile) {
                $uptimeSeconds = (float)explode(' ', $uptimeFile)[0];
                $days = floor($uptimeSeconds / 86400);
                $hours = floor(($uptimeSeconds % 86400) / 3600);
                $minutes = floor(($uptimeSeconds % 3600) / 60);
                return "{$days}天 {$hours}小时 {$minutes}分钟";
            }
        } elseif ($os === 'WIN') {
            $cmd = 'wmic os get lastbootuptime /value 2>nul';
            $output = @shell_exec($cmd);
            if ($output && preg_match('/LastBootUpTime=([\d\.\+\-]+)/', $output, $match)) {
                $bootTime = str_replace(['.', '+', '-'], [' ', ' ', ' '], $match[1]);
                $bootTimestamp = @strtotime($bootTime);
                if ($bootTimestamp !== false) {
                    $uptimeSeconds = time() - $bootTimestamp;
                    if ($uptimeSeconds > 0) {
                        $days = floor($uptimeSeconds / 86400);
                        $hours = floor(($uptimeSeconds % 86400) / 3600);
                        $minutes = floor(($uptimeSeconds % 3600) / 60);
                        return "{$days}天 {$hours}小时 {$minutes}分钟";
                    }
                }
            }
        }
        return '无法获取';
    }
}

/**
 * 获取主机名 (安全命名)
 */
if (!function_exists('getSystemHostname')) {
    function getSystemHostname() {
        $hostname = gethostname();
        if ($hostname) return $hostname;
        return '未知';
    }
}

// ===================== 收集所有数据 =====================
$osType = getOS();
$cpuUsage = getCpuUsage($osType);
$memory   = getMemoryUsage($osType);
$disk     = getDiskUsage();
$serverIP = getServerIP();
$loadAvg  = getLoadAverage();
$hostname = getSystemHostname();

// ===================== 数据库状态采集 =====================
$dbStatus = [
    'connected'   => false,
    'error'       => null,
    'server_info' => null,
    'database'    => null,
    'table_count' => 0,
    'data_size'   => 0,  // MB
    'index_size'  => 0   // MB
];

// 使用全局数据库连接 $conn (来自 config.php)
global $conn;
if (isset($conn) && $conn instanceof mysqli && !$conn->connect_error) {
    $dbStatus['connected'] = true;
    $dbStatus['server_info'] = $conn->server_info; // MySQL 版本信息
    // 获取当前数据库名称
    $dbNameRes = $conn->query("SELECT DATABASE()");
    if ($dbNameRes) {
        $row = $dbNameRes->fetch_row();
        $dbStatus['database'] = $row[0] ?? 'user'; // 默认为 user
    } else {
        $dbStatus['database'] = 'user'; // fallback
    }

    // 查询 information_schema 获取表数量和大小（需要权限）
    $sql = "SELECT 
                COUNT(*) AS table_count,
                ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS total_size_mb,
                ROUND(SUM(data_length) / 1024 / 1024, 2) AS data_size_mb,
                ROUND(SUM(index_length) / 1024 / 1024, 2) AS index_size_mb
            FROM information_schema.tables 
            WHERE table_schema = ?";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param('s', $dbStatus['database']);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $dbStatus['table_count'] = (int)$row['table_count'];
            $dbStatus['total_size']  = (float)$row['total_size_mb'];
            $dbStatus['data_size']   = (float)$row['data_size_mb'];
            $dbStatus['index_size']  = (float)$row['index_size_mb'];
        }
        $stmt->close();
    } else {
        // 如果无法查询 information_schema，尝试简单查询表数量
        $res = $conn->query("SHOW TABLES");
        if ($res) {
            $dbStatus['table_count'] = $res->num_rows;
            $dbStatus['total_size'] = $dbStatus['data_size'] = $dbStatus['index_size'] = 0; // 无法获取大小
        }
    }
} else {
    $dbStatus['error'] = $conn->connect_error ?? '数据库连接未初始化或已断开';
}

// 辅助函数：根据百分比返回颜色类
function getColorClass($percent) {
    if ($percent < 50) return 'bg-green-500';
    if ($percent < 80) return 'bg-yellow-500';
    return 'bg-red-500';
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>服务器监控仪表盘 | 后台概览</title>
    <link rel="icon" type="image/png" href="/../jiaowu.png">
    <!-- TailwindCSS CDN -->
    <script src="https://cdn.tailwindcss.com/3.4.17"></script>
    <!-- Font Awesome 6 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        .progress-bar { transition: width 0.4s ease-in-out; }
        .main-content { overflow-y: auto; max-height: 100vh; }
        html, body { height: 100%; margin: 0; padding: 0; overflow: hidden; }
    </style>
</head>
<body class="font-sans antialiased bg-gray-100">
    <div class="flex h-screen overflow-hidden">
        <!-- 侧边栏 -->
        <?php require_once __DIR__ . '/../sidebar.php'; ?>

        <!-- 主内容区域 -->
        <div class="main-content flex-1 overflow-y-auto">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
                <!-- 页眉区域 -->
                <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-8 gap-4">
                    <div>
                        <h1 class="text-3xl font-bold text-gray-800 flex items-center gap-3">
                            <i class="fas fa-server text-indigo-600"></i> 
                            服务器概览仪表盘
                        </h1>
                        <p class="text-gray-500 mt-1 flex items-center gap-2">
                            <i class="fas fa-info-circle text-sm"></i> 
                            实时系统资源监控 · <?php echo htmlspecialchars($hostname); ?>
                        </p>
                    </div>
                    <div class="flex items-center gap-3">
                        <span class="text-sm text-gray-500 bg-white px-3 py-1.5 rounded-full shadow-sm">
                            <i class="far fa-calendar-alt mr-1"></i> <?php echo date('Y-m-d H:i:s'); ?>
                        </span>
                        <a href="?" class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg shadow-sm transition flex items-center gap-2 text-sm font-medium">
                            <i class="fas fa-sync-alt"></i> 刷新数据
                        </a>
                    </div>
                </div>

                <!-- 卡片网格 -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                    <!-- CPU 卡片 -->
                    <div class="bg-white rounded-2xl shadow-md overflow-hidden card-hover transition-all duration-200">
                        <div class="px-5 pt-5 pb-2 border-b border-gray-100 flex justify-between items-center">
                            <div class="flex items-center gap-2">
                                <i class="fas fa-microchip text-blue-600 text-xl"></i>
                                <h2 class="font-semibold text-gray-700 text-lg">CPU 占用率</h2>
                            </div>
                            <span class="text-xs bg-gray-100 text-gray-600 px-2 py-1 rounded-full">实时采样</span>
                        </div>
                        <div class="p-5">
                            <?php if ($cpuUsage !== null): ?>
                                <div class="flex justify-between items-baseline mb-2">
                                    <span class="text-4xl font-bold text-gray-800"><?php echo $cpuUsage; ?><span class="text-xl text-gray-500">%</span></span>
                                    <span class="text-sm text-gray-500"><i class="fas fa-chart-line"></i> 系统负载</span>
                                </div>
                                <div class="w-full bg-gray-200 rounded-full h-3 mb-3 overflow-hidden">
                                    <div class="progress-bar h-3 rounded-full <?php echo getColorClass($cpuUsage); ?>" style="width: <?php echo min(100, max(0, $cpuUsage)); ?>%"></div>
                                </div>
                                <?php if ($loadAvg): ?>
                                    <div class="grid grid-cols-3 gap-2 text-center text-sm mt-3 pt-2 border-t border-gray-100">
                                        <div><span class="text-gray-500">1分钟</span><br><span class="font-mono font-medium"><?php echo $loadAvg['1min']; ?></span></div>
                                        <div><span class="text-gray-500">5分钟</span><br><span class="font-mono font-medium"><?php echo $loadAvg['5min']; ?></span></div>
                                        <div><span class="text-gray-500">15分钟</span><br><span class="font-mono font-medium"><?php echo $loadAvg['15min']; ?></span></div>
                                    </div>
                                <?php else: ?>
                                    <div class="text-xs text-gray-400 text-center mt-2">负载数据不可用</div>
                                <?php endif; ?>
                            <?php else: ?>
                                <div class="text-center text-red-500 py-4"><i class="fas fa-exclamation-triangle"></i> CPU 信息获取失败，请检查权限</div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- 内存卡片 -->
                    <div class="bg-white rounded-2xl shadow-md overflow-hidden card-hover transition-all duration-200">
                        <div class="px-5 pt-5 pb-2 border-b border-gray-100 flex items-center gap-2">
                            <i class="fas fa-memory text-purple-600 text-xl"></i>
                            <h2 class="font-semibold text-gray-700 text-lg">物理内存</h2>
                        </div>
                        <div class="p-5">
                            <?php if ($memory): ?>
                                <div class="flex justify-between items-baseline mb-2">
                                    <span class="text-4xl font-bold text-gray-800"><?php echo $memory['percent']; ?><span class="text-xl text-gray-500">%</span></span>
                                    <span class="text-sm text-gray-500"><?php echo $memory['used']; ?> / <?php echo $memory['total']; ?> MB</span>
                                </div>
                                <div class="w-full bg-gray-200 rounded-full h-3 mb-4 overflow-hidden">
                                    <div class="progress-bar h-3 rounded-full <?php echo getColorClass($memory['percent']); ?>" style="width: <?php echo min(100, max(0, $memory['percent'])); ?>%"></div>
                                </div>
                                <div class="flex justify-between text-sm text-gray-600">
                                    <span><i class="fas fa-chart-simple"></i> 已用: <?php echo $memory['used']; ?> MB</span>
                                    <span><i class="fas fa-database"></i> 空闲: <?php echo $memory['free']; ?> MB</span>
                                </div>
                            <?php else: ?>
                                <div class="text-center text-red-500 py-4"><i class="fas fa-exclamation-triangle"></i> 内存信息不可用</div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- 磁盘卡片 -->
                    <div class="bg-white rounded-2xl shadow-md overflow-hidden card-hover transition-all duration-200">
                        <div class="px-5 pt-5 pb-2 border-b border-gray-100 flex items-center gap-2">
                            <i class="fas fa-hdd text-emerald-600 text-xl"></i>
                            <h2 class="font-semibold text-gray-700 text-lg">磁盘使用率</h2>
                        </div>
                        <div class="p-5">
                            <?php if ($disk): ?>
                                <div class="flex justify-between items-baseline mb-2">
                                    <span class="text-4xl font-bold text-gray-800"><?php echo $disk['percent']; ?><span class="text-xl text-gray-500">%</span></span>
                                    <span class="text-sm text-gray-500"><?php echo $disk['used']; ?> / <?php echo $disk['total']; ?> GB</span>
                                </div>
                                <div class="w-full bg-gray-200 rounded-full h-3 mb-4 overflow-hidden">
                                    <div class="progress-bar h-3 rounded-full <?php echo getColorClass($disk['percent']); ?>" style="width: <?php echo min(100, max(0, $disk['percent'])); ?>%"></div>
                                </div>
                                <div class="flex justify-between text-sm text-gray-600">
                                    <span><i class="fas fa-folder-open"></i> 已用: <?php echo $disk['used']; ?> GB</span>
                                    <span><i class="fas fa-space-shuttle"></i> 可用: <?php echo $disk['free']; ?> GB</span>
                                </div>
                            <?php else: ?>
                                <div class="text-center text-red-500 py-4"><i class="fas fa-exclamation-triangle"></i> 磁盘信息获取失败</div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- 网络与主机卡片 -->
                    <div class="bg-white rounded-2xl shadow-md overflow-hidden card-hover transition-all duration-200">
                        <div class="px-5 pt-5 pb-2 border-b border-gray-100 flex items-center gap-2">
                            <i class="fas fa-network-wired text-sky-600 text-xl"></i>
                            <h2 class="font-semibold text-gray-700 text-lg">网络与主机</h2>
                        </div>
                        <div class="p-5 space-y-3">
                            <div class="flex items-start gap-3">
                                <i class="fas fa-ip text-gray-400 mt-1 w-5"></i>
                                <div>
                                    <p class="text-xs text-gray-500 uppercase tracking-wide">内网 IP 地址</p>
                                    <p class="font-mono text-gray-800 font-medium break-all"><?php echo htmlspecialchars($serverIP); ?></p>
                                </div>
                            </div>
                            <div class="flex items-start gap-3">
                                <i class="fas fa-computer text-gray-400 mt-1 w-5"></i>
                                <div>
                                    <p class="text-xs text-gray-500 uppercase tracking-wide">主机名</p>
                                    <p class="font-medium text-gray-800"><?php echo htmlspecialchars($hostname); ?></p>
                                </div>
                            </div>
                            <div class="flex items-start gap-3">
                                <i class="fas fa-chart-simple text-gray-400 mt-1 w-5"></i>
                                <div>
                                    <p class="text-xs text-gray-500 uppercase tracking-wide">操作系统</p>
                                    <p class="font-medium text-gray-800"><?php echo $osType === 'LINUX' ? 'Linux 服务器' : ($osType === 'WIN' ? 'Windows Server' : '其他系统'); ?></p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- 数据库状态卡片 (原运行时间卡片) -->
                    <div class="bg-white rounded-2xl shadow-md overflow-hidden card-hover transition-all duration-200">
                        <div class="px-5 pt-5 pb-2 border-b border-gray-100 flex items-center gap-2">
                            <i class="fas fa-database text-cyan-600 text-xl"></i>
                            <h2 class="font-semibold text-gray-700 text-lg">数据库状态</h2>
                        </div>
                        <div class="p-5">
                            <?php if ($dbStatus['connected']): ?>
                                <div class="flex items-center justify-between mb-3">
                                    <div class="flex items-center gap-2">
                                        <i class="fas fa-check-circle text-green-500"></i>
                                        <span class="text-sm font-medium text-gray-700">连接状态</span>
                                    </div>
                                    <span class="text-xs bg-green-100 text-green-700 px-2 py-0.5 rounded-full">正常</span>
                                </div>
                                <div class="space-y-2 text-sm">
                                    <div class="flex justify-between">
                                        <span class="text-gray-500">数据库名</span>
                                        <span class="font-mono font-medium"><?php echo htmlspecialchars($dbStatus['database']); ?></span>
                                    </div>
                                    <div class="flex justify-between">
                                        <span class="text-gray-500">MySQL 版本</span>
                                        <span class="font-mono text-xs"><?php echo htmlspecialchars($dbStatus['server_info']); ?></span>
                                    </div>
                                    <div class="flex justify-between">
                                        <span class="text-gray-500">表数量</span>
                                        <span class="font-mono font-medium"><?php echo $dbStatus['table_count']; ?> 张</span>
                                    </div>
                                    <?php if (isset($dbStatus['total_size']) && $dbStatus['total_size'] > 0): ?>
                                        <div class="flex justify-between">
                                            <span class="text-gray-500">数据大小</span>
                                            <span class="font-mono"><?php echo round($dbStatus['data_size'], 2); ?> MB</span>
                                        </div>
                                        <div class="flex justify-between">
                                            <span class="text-gray-500">索引大小</span>
                                            <span class="font-mono"><?php echo round($dbStatus['index_size'], 2); ?> MB</span>
                                        </div>
                                        <div class="mt-2">
                                            <div class="w-full bg-gray-200 rounded-full h-1.5">
                                                <div class="bg-cyan-500 h-1.5 rounded-full" style="width: <?php echo min(100, ($dbStatus['data_size'] / max($dbStatus['total_size'], 1)) * 100); ?>%"></div>
                                            </div>
                                            <p class="text-xs text-gray-400 mt-1 text-center">数据 / 索引占比</p>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <div class="text-center text-red-500 py-4">
                                    <i class="fas fa-exclamation-triangle text-2xl mb-2 block"></i>
                                    数据库连接失败<br>
                                    <span class="text-xs"><?php echo htmlspecialchars($dbStatus['error']); ?></span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- 健康状态卡片 -->
                    <div class="bg-white rounded-2xl shadow-md overflow-hidden card-hover transition-all duration-200 bg-gradient-to-br from-gray-50 to-white">
                        <div class="px-5 pt-5 pb-2 border-b border-gray-100 flex items-center gap-2">
                            <i class="fas fa-chart-pie text-indigo-600 text-xl"></i>
                            <h2 class="font-semibold text-gray-700 text-lg">健康状态</h2>
                        </div>
                        <div class="p-5">
                            <div class="space-y-3">
                                <?php 
                                    $healthScore = 100;
                                    if ($cpuUsage !== null && $cpuUsage > 85) $healthScore -= 25;
                                    if ($memory && $memory['percent'] > 85) $healthScore -= 20;
                                    if ($disk && $disk['percent'] > 85) $healthScore -= 15;
                                    if (!$dbStatus['connected']) $healthScore -= 40;
                                    $healthScore = max(0, $healthScore);
                                    $healthColor = $healthScore >= 80 ? 'text-green-600' : ($healthScore >= 50 ? 'text-yellow-600' : 'text-red-600');
                                ?>
                                <div class="flex justify-between items-center">
                                    <span class="text-gray-600">综合健康指数</span>
                                    <span class="text-2xl font-bold <?php echo $healthColor; ?>"><?php echo $healthScore; ?>%</span>
                                </div>
                                <div class="w-full bg-gray-200 rounded-full h-2">
                                    <div class="bg-indigo-500 h-2 rounded-full" style="width: <?php echo $healthScore; ?>%"></div>
                                </div>
                                <div class="grid grid-cols-2 gap-2 text-xs text-gray-500 pt-2">
                                    <div><i class="fas fa-tachometer-alt"></i> CPU: <?php echo $cpuUsage ?? 'N/A'; ?>%</div>
                                    <div><i class="fas fa-memory"></i> 内存: <?php echo $memory['percent'] ?? 'N/A'; ?>%</div>
                                    <div><i class="fas fa-hdd"></i> 磁盘: <?php echo $disk['percent'] ?? 'N/A'; ?>%</div>
                                    <div><i class="fas fa-database"></i> 数据库: <?php echo $dbStatus['connected'] ? '正常' : '异常'; ?></div>
                                </div>
                                <hr class="my-2">
                                <div class="text-center text-xs text-gray-400">
                                    <i class="far fa-question-circle"></i> 数据基于当前采样点，手动刷新获取最新状态
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mt-8 text-center text-xs text-gray-400 border-t pt-6">
                    <i class="fas fa-shield-alt"></i> 服务器监控面板 | 数据实时生成 · 刷新页面更新指标
                    <span class="mx-2">•</span> 
                    <?php if ($osType === 'LINUX'): ?>
                        <i class="fab fa-linux"></i> 基于 /proc 与系统命令采集
                    <?php else: ?>
                        <i class="fab fa-windows"></i> 基于 WMI 接口采集
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</body>
</html>