<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';
requireAdmin();

$db = getDB();
ensureFilterTables($db);

// 与屏幕列表完全相同的口径（归一化 + 同一 WHERE 构造），导出全部匹配记录
$filters = normalizeMessageFilters($_GET);
list($where, $params) = buildMessageWhere($filters);

$countStmt = $db->prepare("SELECT COUNT(*) FROM messages $where");
$countStmt->execute($params);
$total = intval($countStmt->fetchColumn());

$stmt = $db->prepare("SELECT * FROM messages $where ORDER BY created_at DESC, id DESC");
$stmt->execute($params);
$rows = $stmt->fetchAll();

// CSV 下载头（BOM 保证 Excel 打开中文不乱码）
$filename = 'messages_' . date('Ymd_His') . '.csv';
while (ob_get_level() > 0) {
    ob_end_clean();
}
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM

// 防止 CSV 公式注入：用户可控内容若以 = + - @ Tab CR 开头，加前导单引号（Excel 中不显示）
function csvCell($value) {
    $value = (string)$value;
    if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
        return "'" . $value;
    }
    return $value;
}

// 与屏幕表格一致的列：ID / 类型 / 标题 / 昵称 / 状态 / 浏览 / 时间
fputcsv($out, ['ID', '类型', '标题', '昵称', '状态', '浏览', '时间']);

if ($total === 0) {
    // 没有数据时写出说明，而不是只给一个空文件
    fputcsv($out, ['说明：未找到符合当前条件的记录（0 条）']);
    fputcsv($out, ['当前条件：' . describeMessageFilters($filters)]);
    fputcsv($out, ['导出时间：' . date('Y-m-d H:i:s')]);
} else {
    foreach ($rows as $msg) {
        fputcsv($out, [
            csvCell($msg['id']),
            csvCell(getTypeLabel($msg['type'])),
            csvCell($msg['title']),
            csvCell($msg['nickname']),
            csvCell(getStatusLabel(intval($msg['status']))),
            csvCell($msg['views']),
            csvCell($msg['created_at']),
        ]);
    }
}

fclose($out);
exit;
