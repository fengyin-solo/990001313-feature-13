<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';
requireAdmin();

/**
 * 留言列表结果下载
 * 与后台屏幕列表使用完全相同的定位口径（状态/类型/关键词/页码），
 * 导出内容即当前屏幕看到的记录；无数据时写出说明行。
 */

$filters = normalizeMessageFilters($_GET);
$pageSize = 15;
$offset = ($filters['page'] - 1) * $pageSize;
list($where, $params) = buildMessageWhere($filters);

$db = getDB();

$countStmt = $db->prepare("SELECT COUNT(*) FROM messages $where");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($total / $pageSize));
if ($filters['page'] > $totalPages) {
    $filters['page'] = $totalPages;
    $offset = ($filters['page'] - 1) * $pageSize;
}

$stmt = $db->prepare("SELECT * FROM messages $where ORDER BY created_at DESC LIMIT $pageSize OFFSET $offset");
$stmt->execute($params);
$messages = $stmt->fetchAll();

// 关闭可能已输出的错误内容，以文件流响应
while (ob_get_level() > 0) {
    ob_end_clean();
}

$filename = '留言结果_' . date('Ymd_His') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename*=UTF-8\'\'' . rawurlencode($filename));
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

// BOM，保证 Excel 打开中文不乱码
echo "\xEF\xBB\xBF";

$out = fopen('php://output', 'w');

fputcsv($out, ['ID', '类型', '标题', '昵称', '状态', '浏览', '时间']);

if (empty($messages)) {
    fputcsv($out, ['当前筛选条件下暂无数据（' . describeMessageFilters($filters) . '；共 ' . $total . ' 条）']);
} else {
    foreach ($messages as $msg) {
        fputcsv($out, [
            $msg['id'],
            getTypeLabel($msg['type']),
            $msg['title'],
            $msg['nickname'],
            getStatusLabel($msg['status']),
            $msg['views'],
            date('m-d H:i', strtotime($msg['created_at'])),
        ]);
    }
}

fclose($out);
exit;
