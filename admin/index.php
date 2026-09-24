<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';
requireAdmin();

$pageTitle = '后台管理 - 社区便民留言板';
$currentPage = 'admin';
$cssPath = '../assets/css/style.css';
$jsPath = '../assets/js/main.js';

$db = getDB();
ensureFilterTables($db);

// 应用已保存的常用条件：跳转到该条件保存时的定位口径（含页码）
if (isset($_GET['apply_filter'])) {
    $stmt = $db->prepare("SELECT * FROM saved_filters WHERE id = ? AND admin_id = ?");
    $stmt->execute([intval($_GET['apply_filter']), $_SESSION['admin_id']]);
    $sf = $stmt->fetch();
    if ($sf) {
        header('Location: index.php?' . http_build_query([
            'status' => $sf['status'],
            'type' => $sf['type'],
            'keyword' => $sf['keyword'],
            'page' => max(1, intval($sf['page'])),
        ]));
        exit;
    }
}

// 重置：清除定位记忆后回到无条件列表（否则下次裸进入又会恢复旧条件）
if (isset($_GET['reset'])) {
    $empty = normalizeMessageFilters([]);
    saveAdminViewState($db, $_SESSION['admin_id'], $empty);
    header('Location: index.php');
    exit;
}

// 裸进入后台（无任何查询参数）时，回到上次的记录位置
if (empty($_GET) && !empty($_SESSION['admin_id'])) {
    $last = getAdminViewState($db, $_SESSION['admin_id']);
    if (!empty($last)) {
        header('Location: index.php?' . http_build_query($last));
        exit;
    }
}

// 筛选参数（与下载共用同一套归一化口径，保证结果一致）
$filters = normalizeMessageFilters($_GET);
$status = $filters['status'];
$type = $filters['type'];
$keyword = $filters['keyword'];
$page = $filters['page'];
$pageSize = 15;
$offset = ($page - 1) * $pageSize;

// 记录当前定位口径（含页码），供下次进入后台时恢复
saveAdminViewState($db, $_SESSION['admin_id'], $filters);

list($where, $params) = buildMessageWhere($filters);

$countStmt = $db->prepare("SELECT COUNT(*) FROM messages $where");
$countStmt->execute($params);
$total = $countStmt->fetchColumn();
$totalPages = ceil($total / $pageSize);

$sql = "SELECT * FROM messages $where ORDER BY created_at DESC LIMIT $pageSize OFFSET $offset";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$messages = $stmt->fetchAll();

// 当前条件的查询串（下载、分页、保存共用）
$currentQuery = http_build_query([
    'status' => $status,
    'type' => $type,
    'keyword' => $keyword,
]);

// 已保存的常用条件
$savedFilters = getSavedFilters($db, $_SESSION['admin_id']);

// 统计
$pendingCount = $db->query("SELECT COUNT(*) FROM messages WHERE status = 0")->fetchColumn();

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
                <a href="index.php?reset=1" class="btn btn-secondary btn-sm">重置</a>
            </form>
        </div>

        <!-- 常用条件与下载 -->
        <div class="saved-filter-bar">
            <form method="GET" class="saved-filter-form">
                <label class="saved-filter-label">⭐ 常用条件：</label>
                <select name="apply_filter" id="applyFilterSelect">
                    <option value="">— 选择已保存条件 —</option>
                    <?php foreach ($savedFilters as $sf): ?>
                    <option value="<?= intval($sf['id']) ?>"><?= cleanInput($sf['name']) ?>（<?= cleanInput(describeMessageFilters(['status' => $sf['status'], 'type' => $sf['type'], 'keyword' => $sf['keyword']])) ?>）</option>
                    <?php endforeach; ?>
                </select>
            </form>
            <div class="saved-filter-actions">
                <button type="button" class="btn btn-secondary btn-sm" id="saveFilterBtn">💾 保存当前条件</button>
                <button type="button" class="btn btn-secondary btn-sm" id="manageFilterBtn">📦 管理/导出</button>
                <button type="button" class="btn btn-primary btn-sm" id="downloadBtn">⬇️ 下载结果</button>
            </div>
        </div>
        <input type="hidden" id="currentQuery" value="<?= cleanInput($currentQuery) ?>">
        <div class="download-tip" id="downloadTip" style="display:none;"></div>

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
            <a href="index.php?<?= $currentQuery ?>&page=<?= $page - 1 ?>" class="page-btn">上一页</a>
            <?php endif; ?>
            <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
            <a href="index.php?<?= $currentQuery ?>&page=<?= $i ?>" class="page-btn <?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
            <?php endfor; ?>
            <?php if ($page < $totalPages): ?>
            <a href="index.php?<?= $currentQuery ?>&page=<?= $page + 1 ?>" class="page-btn">下一页</a>
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

<!-- 保存条件弹窗 -->
<div class="modal" id="saveFilterModal" style="display:none;">
    <div class="modal-content">
        <div class="modal-header">
            <h3>保存常用条件</h3>
            <button class="modal-close" onclick="closeSaveFilterModal()">&times;</button>
        </div>
        <div class="modal-body">
            <p class="text-muted" id="saveFilterSummary"></p>
            <div class="form-group">
                <label for="filterName">条件名称</label>
                <input type="text" id="filterName" maxlength="50" placeholder="例如：本周待审核求助">
            </div>
            <div class="alert alert-error" id="saveFilterError" style="display:none;"></div>
            <div class="form-actions">
                <button type="button" class="btn btn-secondary" onclick="closeSaveFilterModal()">取消</button>
                <button type="button" class="btn btn-primary" id="confirmSaveFilterBtn">保存</button>
            </div>
        </div>
    </div>
</div>

<!-- 条件管理弹窗 -->
<div class="modal" id="manageFilterModal" style="display:none;">
    <div class="modal-content modal-wide">
        <div class="modal-header">
            <h3>常用条件管理</h3>
            <button class="modal-close" onclick="closeManageFilterModal()">&times;</button>
        </div>
        <div class="modal-body">
            <div class="form-actions" style="justify-content:flex-start;margin-bottom:12px;">
                <button type="button" class="btn btn-secondary btn-sm" id="exportAllBtn">📤 导出全部条件</button>
                <button type="button" class="btn btn-secondary btn-sm" id="importBtn">📥 导入条件包</button>
                <input type="file" id="importFile" accept=".json,application/json" style="display:none;">
            </div>
            <div class="alert" id="importResult" style="display:none;"></div>
            <div class="admin-table-wrapper">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th style="width:36px;"><input type="checkbox" id="checkAllFilters" title="全选"></th>
                            <th>名称</th>
                            <th>条件口径</th>
                            <th>保存时间</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody id="savedFilterList"></tbody>
                </table>
            </div>
            <div class="form-actions" style="justify-content:flex-start;">
                <button type="button" class="btn btn-secondary btn-sm" id="exportSelectedBtn">导出勾选条件</button>
            </div>
        </div>
    </div>
</div>

<script>
// 选择已保存条件：GET 跳转回该条件的定位口径
document.getElementById('applyFilterSelect').addEventListener('change', function() {
    if (this.value) this.form.submit();
});

/* ---------- 保存当前条件 ---------- */
function openSaveFilterModal() {
    document.getElementById('saveFilterError').style.display = 'none';
    document.getElementById('filterName').value = '';
    refreshFilterSummary();
    document.getElementById('saveFilterModal').style.display = 'flex';
    document.getElementById('filterName').focus();
}
function closeSaveFilterModal() {
    document.getElementById('saveFilterModal').style.display = 'none';
}
function refreshFilterSummary() {
    const p = new URLSearchParams(document.getElementById('currentQuery').value);
    const statusText = ({'': '全部', '0': '待审核', '1': '已通过', '2': '已拒绝'})[p.get('status') || ''] || '全部';
    const typeMap = {'': '全部', 'help': '居民求助', 'suggest': '意见建议', 'lost': '失物招领'};
    const typeText = typeMap[p.get('type') || ''] || '全部';
    const kw = p.get('keyword') || '';
    document.getElementById('saveFilterSummary').textContent =
        '将保存当前定位口径：状态「' + statusText + '」、类型「' + typeText +
        '」、关键词「' + (kw || '无') + '」、第 ' + (new URLSearchParams(location.search).get('page') || 1) + ' 页';
}
document.getElementById('saveFilterBtn').addEventListener('click', openSaveFilterModal);
document.getElementById('confirmSaveFilterBtn').addEventListener('click', function() {
    const name = document.getElementById('filterName').value.trim();
    const errBox = document.getElementById('saveFilterError');
    if (!name) {
        errBox.textContent = '请输入条件名称';
        errBox.style.display = 'block';
        return;
    }
    const body = new URLSearchParams(document.getElementById('currentQuery').value);
    body.set('action', 'save_filter');
    body.set('name', name);
    body.set('page', new URLSearchParams(location.search).get('page') || 1);
    fetch('api.php', {method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: body})
        .then(r => r.json())
        .then(data => {
            if (data.code === 0) {
                location.reload();
            } else {
                errBox.textContent = data.msg || '保存失败';
                errBox.style.display = 'block';
            }
        })
        .catch(() => {
            errBox.textContent = '网络错误，当前条件未改动，可重试保存';
            errBox.style.display = 'block';
        });
});
document.getElementById('saveFilterModal').addEventListener('click', function(e) {
    if (e.target === this) closeSaveFilterModal();
});

/* ---------- 条件管理 / 导入导出 ---------- */
let savedFilterCache = [];
function openManageFilterModal() {
    document.getElementById('importResult').style.display = 'none';
    document.getElementById('manageFilterModal').style.display = 'flex';
    loadSavedFilters();
}
function closeManageFilterModal() {
    document.getElementById('manageFilterModal').style.display = 'none';
}
document.getElementById('manageFilterBtn').addEventListener('click', openManageFilterModal);
document.getElementById('manageFilterModal').addEventListener('click', function(e) {
    if (e.target === this) closeManageFilterModal();
});

function loadSavedFilters() {
    fetch('api.php?action=list_filters')
        .then(r => r.json())
        .then(data => {
            savedFilterCache = data.code === 0 ? data.data : [];
            renderSavedFilters();
        });
}
function renderSavedFilters() {
    const tbody = document.getElementById('savedFilterList');
    if (!savedFilterCache.length) {
        tbody.innerHTML = '<tr><td colspan="5" class="text-center">暂无保存的条件</td></tr>';
        return;
    }
    const statusMap = {'': '全部', '0': '待审核', '1': '已通过', '2': '已拒绝'};
    const typeMap = {'': '全部', 'help': '居民求助', 'suggest': '意见建议', 'lost': '失物招领'};
    tbody.innerHTML = savedFilterCache.map(f => {
        const summary = '状态「' + statusMap[f.status] + '」、类型「' + typeMap[f.type] +
            '」、关键词「' + (f.keyword || '无') + '」、第' + f.page + '页';
        return '<tr>' +
            '<td><input type="checkbox" class="filter-check" value="' + f.id + '"></td>' +
            '<td>' + escapeHtml(f.name) + '</td>' +
            '<td class="text-muted">' + summary + '</td>' +
            '<td class="td-time">' + (f.created_at || '') + '</td>' +
            '<td class="td-actions">' +
                '<button class="btn btn-xs btn-info" onclick="applySavedFilter(' + f.id + ')">应用</button>' +
                '<button class="btn btn-xs btn-danger" onclick="removeSavedFilter(' + f.id + ')">删除</button>' +
            '</td>' +
        '</tr>';
    }).join('');
}
function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}
function applySavedFilter(id) {
    location.href = 'index.php?apply_filter=' + id;
}
function removeSavedFilter(id) {
    if (!confirm('确定删除这条常用条件吗？')) return;
    const body = new URLSearchParams({action: 'delete_filter', id: id});
    fetch('api.php', {method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body})
        .then(r => r.json())
        .then(data => {
            if (data.code === 0) loadSavedFilters();
            else alert(data.msg);
        });
}
document.getElementById('checkAllFilters').addEventListener('change', function() {
    document.querySelectorAll('.filter-check').forEach(c => c.checked = this.checked);
});

function downloadFile(url, fallbackName, expectType) {
    return fetch(url, {credentials: 'same-origin'})
        .then(r => {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            const ctype = r.headers.get('Content-Type') || '';
            return r.blob().then(blob => {
                // CSV 下载若被重定向到登录页等非 CSV 响应（常见于会话过期），按失败处理
                if (expectType === 'csv' && ctype.indexOf('text/csv') === -1) {
                    return blob.text().then(text => {
                        let msg = '服务器未返回 CSV 文件（可能登录已过期，请刷新页面重新登录后重试）';
                        try {
                            const j = JSON.parse(text);
                            if (j.msg) msg = j.msg;
                        } catch (e2) { /* 非 JSON，沿用默认提示 */ }
                        throw new Error(msg);
                    });
                }
                if (ctype.indexOf('application/json') === 0 && blob.size < 500) {
                    return blob.text().then(text => {
                        try {
                            const j = JSON.parse(text);
                            if (j.code !== undefined && j.code !== 0) throw new Error(j.msg || '下载失败');
                        } catch (e) { if (!(e instanceof SyntaxError)) throw e; }
                        return triggerBlob(blob, r, fallbackName);
                    });
                }
                return triggerBlob(blob, r, fallbackName);
            });
        });
}
function triggerBlob(blob, r, fallbackName) {
    const disp = r.headers.get('Content-Disposition') || '';
    const m = /filename\*?=(?:UTF-8'')?["']?([^;"']+)/i.exec(disp);
    const name = m ? decodeURIComponent(m[1]) : fallbackName;
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = name;
    document.body.appendChild(a);
    a.click();
    setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 1000);
}

document.getElementById('exportAllBtn').addEventListener('click', function() {
    downloadFile('api.php?action=export_filters', 'saved-filters.json', 'json')
        .catch(e => alert('导出失败，可重试：' + e.message));
});
document.getElementById('exportSelectedBtn').addEventListener('click', function() {
    const ids = Array.from(document.querySelectorAll('.filter-check:checked')).map(c => c.value);
    if (!ids.length) { alert('请先勾选要导出的条件'); return; }
    downloadFile('api.php?action=export_filters&ids=' + ids.join(','), 'saved-filters.json', 'json')
        .catch(e => alert('导出失败，可重试：' + e.message));
});
document.getElementById('importBtn').addEventListener('click', () => document.getElementById('importFile').click());
document.getElementById('importFile').addEventListener('change', function() {
    const file = this.files[0];
    if (!file) return;
    const fd = new FormData();
    fd.append('action', 'import_filters');
    fd.append('file', file);
    const resultBox = document.getElementById('importResult');
    fetch('api.php', {method: 'POST', body: fd})
        .then(r => r.json())
        .then(data => {
            resultBox.style.display = 'block';
            if (data.code === 0) {
                resultBox.className = 'alert alert-success';
                resultBox.textContent = data.msg;
                loadSavedFilters();
            } else {
                resultBox.className = 'alert alert-error';
                resultBox.textContent = data.msg || '导入失败';
            }
        })
        .catch(() => {
            resultBox.style.display = 'block';
            resultBox.className = 'alert alert-error';
            resultBox.textContent = '网络错误，导入未完成，可重试';
        });
    this.value = '';
});

/* ---------- 结果下载：失败保留当前条件并可重试 ---------- */
const downloadBtn = document.getElementById('downloadBtn');
const downloadTip = document.getElementById('downloadTip');
function startDownload() {
    const q = document.getElementById('currentQuery').value;
    downloadBtn.disabled = true;
    downloadBtn.textContent = '下载中...';
    downloadTip.style.display = 'none';
    downloadFile('download.php?' + q, 'messages.csv', 'csv')
        .then(() => {
            downloadBtn.disabled = false;
            downloadBtn.textContent = '⬇️ 下载结果';
        })
        .catch(e => {
            downloadBtn.disabled = false;
            downloadBtn.textContent = '🔄 重试下载';
            downloadTip.className = 'download-tip download-error';
            downloadTip.textContent = '下载失败（' + e.message + '），当前筛选条件已保留，点击按钮可重试';
            downloadTip.style.display = 'block';
        });
}
downloadBtn.addEventListener('click', startDownload);

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
