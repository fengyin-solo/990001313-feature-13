<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';
requireAdmin();

$pageTitle = '后台管理 - 社区便民留言板';
$currentPage = 'admin';
$cssPath = '../assets/css/style.css';
$jsPath = '../assets/js/main.js';

$db = getDB();
ensureSavedFiltersTable();

// 三类入口：
// 1) reset=1 手动重置，回到无条件列表并清空记忆
// 2) saved=ID 应用某个常用条件（含保存时的页码，即“原记录位置”）
// 3) 无任何定位参数重新进入后台：回到上次离开时的条件与页码
$reset = isset($_GET['reset']) && $_GET['reset'] == 1;
$savedId = intval($_GET['saved'] ?? 0);

if ($reset) {
    setcookie('admin_last_filters', '', time() - 3600, '/');
    header('Location: index.php');
    exit;
}

if ($savedId > 0) {
    $saved = getSavedFilter($savedId);
    if (!$saved) {
        $notice = '常用条件不存在或已被删除';
        $filters = normalizeMessageFilters($_GET);
    } else {
        $savedParams = json_decode($saved['params'], true);
        if (!is_array($savedParams)) $savedParams = [];
        $filters = normalizeMessageFilters($savedParams);
        $notice = '已回到常用条件「' . $saved['name'] . '」保存的位置（' . describeMessageFilters($filters) . '）';
    }
} else {
    $hasListParams = isset($_GET['status']) || isset($_GET['type']) || isset($_GET['keyword']) || isset($_GET['page']);
    if (!$hasListParams && !empty($_COOKIE['admin_last_filters'])) {
        $lastParams = json_decode(base64_decode($_COOKIE['admin_last_filters']), true);
        if (is_array($lastParams)) {
            $restored = normalizeMessageFilters($lastParams);
            $hasRestoredCriteria = $restored['status'] !== ''
                || $restored['type'] !== ''
                || $restored['keyword'] !== ''
                || $restored['page'] > 1;
            if ($hasRestoredCriteria) {
                header('Location: ' . messageListUrl($restored, $restored['page']));
                exit;
            }
        }
    }
    $filters = normalizeMessageFilters($_GET);
    $notice = '';
}

// 记忆本次定位口径（含页码），下次重新进入后台时回到此处
setcookie('admin_last_filters', base64_encode(json_encode($filters, JSON_UNESCAPED_UNICODE)), time() + 86400 * 365, '/');

$status = $filters['status'];
$type = $filters['type'];
$keyword = $filters['keyword'];
$page = $filters['page'];
$pageSize = 15;
$offset = ($page - 1) * $pageSize;

list($where, $params) = buildMessageWhere($filters);

$countStmt = $db->prepare("SELECT COUNT(*) FROM messages $where");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($total / $pageSize));
if ($page > $totalPages) {
    $page = $totalPages;
    $filters['page'] = $page;
    $offset = ($page - 1) * $pageSize;
}

$sql = "SELECT * FROM messages $where ORDER BY created_at DESC LIMIT $pageSize OFFSET $offset";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$messages = $stmt->fetchAll();

// 统计
$pendingCount = $db->query("SELECT COUNT(*) FROM messages WHERE status = 0")->fetchColumn();

// 常用条件
$savedFilters = getSavedFilters();

// 当前条件的下载/重置链接（始终携带完整定位口径，下载失败也不会丢条件）
$downloadUrl = 'export_messages.php?' . http_build_query($filters);
$resetUrl = 'index.php?reset=1';

include __DIR__ . '/header.php';
?>

<div class="admin-container">
    <aside class="admin-sidebar">
        <div class="sidebar-header">
            <h3>📋 管理后台</h3>
        </div>
        <nav class="sidebar-nav">
            <a href="index.php" class="sidebar-link active">📝 留言管理</a>
            <a href="index.php?status=0" class="sidebar-link">⏳ 待审核 <?= $pendingCount > 0 ? "($pendingCount)" : '' ?></a>
            <a href="reports.php" class="sidebar-link">🚩 举报管理</a>
            <?php $pendingReportCount = getPendingReportCount(); ?>
            <a href="reports.php?status=0" class="sidebar-link">⏳ 待处理举报 <?= $pendingReportCount > 0 ? "($pendingReportCount)" : '' ?></a>
            <a href="../index.php" class="sidebar-link" target="_blank">🌐 查看前台</a>
            <a href="logout.php" class="sidebar-link">🚪 退出登录</a>
        </nav>
    </aside>

    <div class="admin-main">
        <div class="admin-header">
            <h2>留言管理</h2>
            <span class="admin-user">👤 <?= cleanInput($_SESSION['admin_name']) ?></span>
        </div>

        <?php if (!empty($notice)): ?>
        <div class="saved-filter-notice">📌 <?= cleanInput($notice) ?></div>
        <?php endif; ?>

        <!-- 筛选栏 -->
        <div class="admin-filter">
            <form method="GET" class="filter-form">
                <select name="status">
                    <option value="">全部状态</option>
                    <option value="0" <?= $status === '0' ? 'selected' : '' ?>>待审核</option>
                    <option value="1" <?= $status === '1' ? 'selected' : '' ?>>已通过</option>
                    <option value="2" <?= $status === '2' ? 'selected' : '' ?>>已拒绝</option>
                </select>
                <select name="type">
                    <option value="">全部类型</option>
                    <option value="help" <?= $type === 'help' ? 'selected' : '' ?>>居民求助</option>
                    <option value="suggest" <?= $type === 'suggest' ? 'selected' : '' ?>>意见建议</option>
                    <option value="lost" <?= $type === 'lost' ? 'selected' : '' ?>>失物招领</option>
                </select>
                <input type="text" name="keyword" placeholder="搜索关键词..." value="<?= cleanInput($keyword) ?>">
                <button type="submit" class="btn btn-primary btn-sm">筛选</button>
                <a href="<?= $resetUrl ?>" class="btn btn-secondary btn-sm">重置</a>
                <button type="button" class="btn btn-success btn-sm" onclick="saveCurrentFilter()">💾 保存为常用条件</button>
                <button type="button" class="btn btn-info btn-sm" onclick="downloadResults()">⬇ 下载结果</button>
            </form>
        </div>

        <!-- 常用条件 -->
        <div class="saved-filter-bar">
            <span class="saved-filter-label">⭐ 常用条件：</span>
            <?php if (empty($savedFilters)): ?>
            <span class="saved-filter-empty">暂无，设置好筛选后点击「保存为常用条件」</span>
            <?php else: ?>
            <?php foreach ($savedFilters as $sf):
                $sfParams = json_decode($sf['params'], true);
                if (!is_array($sfParams)) $sfParams = [];
                $sfParams = normalizeMessageFilters($sfParams);
                $sfNameJs = htmlspecialchars(json_encode($sf['name'], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG), ENT_QUOTES, 'UTF-8');
            ?>
            <span class="saved-chip" title="<?= cleanInput(describeMessageFilters($sfParams)) ?>">
                <a href="index.php?saved=<?= $sf['id'] ?>"><?= cleanInput($sf['name']) ?></a>
                <button type="button" class="chip-op" title="用当前条件覆盖更新" onclick="updateSavedFilter(<?= $sf['id'] ?>, <?= $sfNameJs ?>)">↻</button>
                <button type="button" class="chip-op" title="重命名" onclick="renameSavedFilter(<?= $sf['id'] ?>, <?= $sfNameJs ?>)">✎</button>
                <button type="button" class="chip-op" title="删除" onclick="deleteSavedFilter(<?= $sf['id'] ?>, <?= $sfNameJs ?>)">×</button>
            </span>
            <?php endforeach; ?>
            <?php endif; ?>
            <span class="saved-filter-tools">
                <a href="api.php?action=export_filters" class="chip-link">📦 导出打包</a>
                <button type="button" class="chip-link" onclick="document.getElementById('importFilterFile').click()">📥 导入恢复</button>
                <input type="file" id="importFilterFile" accept=".json,application/json" style="display:none;" onchange="importFilters(this)">
            </span>
        </div>

        <!-- 下载失败提示（保留当前条件，可重试或改用直链下载） -->
        <div id="downloadErrorBar" class="download-error-bar" style="display:none;">
            <span id="downloadErrorText">下载失败，当前筛选条件未改变。</span>
            <button type="button" class="btn btn-warning btn-xs" onclick="retryDownload()">重试</button>
            <a id="downloadDirectLink" href="<?= htmlspecialchars($downloadUrl, ENT_QUOTES) ?>" class="btn btn-secondary btn-xs">直接下载</a>
        </div>

        <!-- 留言表格 -->
        <div class="admin-table-wrapper">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>类型</th>
                        <th>标题</th>
                        <th>昵称</th>
                        <th>状态</th>
                        <th>浏览</th>
                        <th>时间</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($messages)): ?>
                    <tr><td colspan="8" class="text-center">暂无数据</td></tr>
                    <?php else: ?>
                    <?php foreach ($messages as $msg): ?>
                    <tr>
                        <td><?= $msg['id'] ?></td>
                        <td><span class="badge badge-<?= $msg['type'] ?>"><?= getTypeLabel($msg['type']) ?></span></td>
                        <td class="td-title" title="<?= cleanInput($msg['title']) ?>"><?= cleanInput(mb_substr($msg['title'], 0, 20)) ?></td>
                        <td><?= cleanInput($msg['nickname']) ?></td>
                        <td><span class="status-badge status-<?= getStatusClass($msg['status']) ?>"><?= getStatusLabel($msg['status']) ?></span></td>
                        <td><?= $msg['views'] ?></td>
                        <td class="td-time"><?= date('m-d H:i', strtotime($msg['created_at'])) ?></td>
                        <td class="td-actions">
                            <button class="btn btn-xs btn-info" onclick="viewMessage(<?= $msg['id'] ?>)">查看</button>
                            <?php if ($msg['status'] != 1): ?>
                            <button class="btn btn-xs btn-success" onclick="auditMessage(<?= $msg['id'] ?>, 1)">通过</button>
                            <?php endif; ?>
                            <?php if ($msg['status'] != 2): ?>
                            <button class="btn btn-xs btn-warning" onclick="auditMessage(<?= $msg['id'] ?>, 2)">拒绝</button>
                            <?php endif; ?>
                            <button class="btn btn-xs btn-danger" onclick="deleteMessage(<?= $msg['id'] ?>)">删除</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- 分页 -->
        <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <?php if ($page > 1): ?>
            <a href="<?= messageListUrl($filters, $page - 1) ?>" class="page-btn">上一页</a>
            <?php endif; ?>
            <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
            <a href="<?= messageListUrl($filters, $i) ?>" class="page-btn <?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
            <?php endfor; ?>
            <?php if ($page < $totalPages): ?>
            <a href="<?= messageListUrl($filters, $page + 1) ?>" class="page-btn">下一页</a>
            <?php endif; ?>
            <span class="page-info">共 <?= $total ?> 条</span>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- 查看弹窗 -->
<div class="modal" id="viewModal" style="display:none;">
    <div class="modal-content">
        <div class="modal-header">
            <h3>留言详情</h3>
            <button class="modal-close" onclick="closeModal()">&times;</button>
        </div>
        <div class="modal-body" id="modalBody">加载中...</div>
    </div>
</div>

<script>
const currentFilters = {
    status: <?= json_encode($status, JSON_HEX_TAG) ?>,
    type: <?= json_encode($type, JSON_HEX_TAG) ?>,
    keyword: <?= json_encode($keyword, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>,
    page: <?= (int)$page ?>
};

function postApi(params) {
    const body = new URLSearchParams(params);
    return fetch('api.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: body.toString()
    }).then(r => r.json());
}

function saveCurrentFilter() {
    const name = prompt('请输入常用条件名称：');
    if (name === null) return;
    if (!name.trim()) { alert('名称不能为空'); return; }
    postApi(Object.assign({action: 'save_filter', name: name.trim()}, currentFilters))
    .then(data => {
        alert(data.msg);
        if (data.code === 0) location.reload();
    })
    .catch(() => alert('保存失败，请重试（当前条件未改变）'));
}

function updateSavedFilter(id, name) {
    if (!confirm('确定用当前筛选条件覆盖「' + name + '」吗？')) return;
    postApi(Object.assign({action: 'update_filter', id: id}, currentFilters))
    .then(data => {
        alert(data.msg);
        if (data.code === 0) location.reload();
    });
}

function renameSavedFilter(id, oldName) {
    const name = prompt('请输入新的名称：', oldName);
    if (name === null) return;
    if (!name.trim()) { alert('名称不能为空'); return; }
    postApi({action: 'rename_filter', id: id, name: name.trim()})
    .then(data => {
        alert(data.msg);
        if (data.code === 0) location.reload();
    });
}

function deleteSavedFilter(id, name) {
    if (!confirm('确定删除常用条件「' + name + '」吗？')) return;
    postApi({action: 'delete_filter', id: id})
    .then(data => {
        alert(data.msg);
        if (data.code === 0) location.reload();
    });
}

function importFilters(input) {
    const file = input.files && input.files[0];
    if (!file) return;
    if (!confirm('导入后同名条件不会被覆盖，将自动改名，确定继续？')) {
        input.value = '';
        return;
    }
    const formData = new FormData();
    formData.append('action', 'import_filters');
    formData.append('file', file);
    fetch('api.php', {method: 'POST', body: formData})
    .then(r => r.json())
    .then(data => {
        if (data.code === 0) {
            alert(data.msg);
            location.reload();
        } else {
            alert(data.msg);
        }
    })
    .catch(() => alert('导入失败，请重试'))
    .finally(() => { input.value = ''; });
}

// ===== 结果下载：失败时留在本页、条件不变，可重试 =====
let downloadRetryUrl = <?= json_encode($downloadUrl, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;

function downloadResults() {
    document.getElementById('downloadErrorBar').style.display = 'none';
    fetch(downloadRetryUrl, {headers: {'Accept': 'text/csv'}})
    .then(r => {
        if (!r.ok) throw new Error('服务器返回异常（HTTP ' + r.status + '）');
        const type = r.headers.get('Content-Type') || '';
        if (type.indexOf('text/csv') === -1) throw new Error('返回内容不是结果文件');
        const cd = r.headers.get('Content-Disposition') || '';
        const m = cd.match(/filename\*=UTF-8''([^;]+)/i) || cd.match(/filename="?([^";]+)"?/i);
        return r.blob().then(blob => {
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = m ? decodeURIComponent(m[1]) : '留言结果.csv';
            document.body.appendChild(a);
            a.click();
            a.remove();
            setTimeout(() => URL.revokeObjectURL(url), 10000);
        });
    })
    .catch(err => {
        document.getElementById('downloadErrorText').textContent = '下载失败：' + err.message + '，当前筛选条件未改变，可重试。';
        document.getElementById('downloadErrorBar').style.display = 'flex';
    });
}

function retryDownload() {
    downloadResults();
}

function auditMessage(id, status) {
    const action = status === 1 ? '通过' : '拒绝';
    if (!confirm('确定要' + action + '这条留言吗？')) return;
    fetch('api.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=audit&id=' + id + '&status=' + status
    })
    .then(r => r.json())
    .then(data => {
        if (data.code === 0) {
            alert('操作成功');
            location.reload();
        } else {
            alert(data.msg);
        }
    });
}

function deleteMessage(id) {
    if (!confirm('确定要删除这条留言吗？此操作不可恢复！')) return;
    fetch('api.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=delete&id=' + id
    })
    .then(r => r.json())
    .then(data => {
        if (data.code === 0) {
            alert('删除成功');
            location.reload();
        } else {
            alert(data.msg);
        }
    });
}

function viewMessage(id) {
    document.getElementById('viewModal').style.display = 'flex';
    document.getElementById('modalBody').innerHTML = '加载中...';
    fetch('api.php?action=detail&id=' + id)
    .then(r => r.json())
    .then(data => {
        if (data.code === 0) {
            const d = data.data;
            let html = '<div class="detail-view">';
            html += '<p><strong>类型：</strong>' + d.type_label + '</p>';
            html += '<p><strong>标题：</strong>' + d.title + '</p>';
            html += '<p><strong>昵称：</strong>' + d.nickname + '</p>';
            html += '<p><strong>电话：</strong>' + (d.phone || '未填写') + '</p>';
            html += '<p><strong>内容：</strong></p><div class="detail-text">' + d.content + '</div>';
            if (d.image) html += '<p><strong>图片：</strong><br><img src="../' + d.image + '" style="max-width:100%;margin-top:8px;"></p>';
            html += '<p><strong>状态：</strong>' + d.status_label + '</p>';
            html += '<p><strong>浏览量：</strong>' + d.views + '</p>';
            html += '<p><strong>时间：</strong>' + d.created_at + '</p>';
            html += '</div>';
            document.getElementById('modalBody').innerHTML = html;
        } else {
            document.getElementById('modalBody').innerHTML = data.msg;
        }
    });
}

function closeModal() {
    document.getElementById('viewModal').style.display = 'none';
}

document.getElementById('viewModal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});
</script>
