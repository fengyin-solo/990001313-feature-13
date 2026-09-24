<?php
session_start();

/**
 * 返回JSON响应
 */
function jsonResponse($code, $msg, $data = null) {
    header('Content-Type: application/json; charset=utf-8');
    $res = ['code' => $code, 'msg' => $msg];
    if ($data !== null) $res['data'] = $data;
    echo json_encode($res, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * 获取留言类型文字
 */
function getTypeLabel($type) {
    $map = ['help' => '居民求助', 'suggest' => '意见建议', 'lost' => '失物招领'];
    return $map[$type] ?? '其他';
}

/**
 * 获取类型图标
 */
function getTypeIcon($type) {
    $map = ['help' => '🆘', 'suggest' => '💡', 'lost' => '🔍'];
    return $map[$type] ?? '📌';
}

/**
 * 获取状态文字
 */
function getStatusLabel($status) {
    $map = [0 => '待审核', 1 => '已通过', 2 => '已拒绝'];
    return $map[$status] ?? '未知';
}

/**
 * 获取状态样式类
 */
function getStatusClass($status) {
    $map = [0 => 'pending', 1 => 'approved', 2 => 'rejected'];
    return $map[$status] ?? '';
}

/**
 * 时间格式化
 */
function timeAgo($datetime) {
    $now = new DateTime();
    $ago = new DateTime($datetime);
    $diff = $now->diff($ago);

    if ($diff->y > 0) return $diff->y . '年前';
    if ($diff->m > 0) return $diff->m . '个月前';
    if ($diff->d > 0) return $diff->d . '天前';
    if ($diff->h > 0) return $diff->h . '小时前';
    if ($diff->i > 0) return $diff->i . '分钟前';
    return '刚刚';
}

/**
 * 检查管理员登录
 */
function requireAdmin() {
    if (empty($_SESSION['admin_id'])) {
        header('Location: login.php');
        exit;
    }
}

/**
 * 过滤输入
 */
function cleanInput($str) {
    return htmlspecialchars(trim($str), ENT_QUOTES, 'UTF-8');
}

/**
 * 获取访客唯一标识
 * 基于session和cookie实现匿名用户标识
 */
function getVisitorId() {
    if (empty($_SESSION['visitor_id'])) {
        if (!empty($_COOKIE['visitor_id'])) {
            $_SESSION['visitor_id'] = $_COOKIE['visitor_id'];
        } else {
            $visitorId = md5(uniqid('visitor_', true) . $_SERVER['REMOTE_ADDR'] . $_SERVER['HTTP_USER_AGENT']);
            $_SESSION['visitor_id'] = $visitorId;
            setcookie('visitor_id', $visitorId, time() + 86400 * 365, '/');
        }
    }
    return $_SESSION['visitor_id'];
}

/**
 * 检查留言是否已收藏
 */
function isFavorited($messageId) {
    $visitorId = getVisitorId();
    $db = getDB();
    $stmt = $db->prepare("SELECT id FROM favorites WHERE visitor_id = ? AND message_id = ?");
    $stmt->execute([$visitorId, $messageId]);
    return $stmt->fetch() !== false;
}

/**
 * 获取当前访客收藏的所有留言ID
 */
function getFavoritedMessageIds() {
    $visitorId = getVisitorId();
    $db = getDB();
    $stmt = $db->prepare("SELECT message_id FROM favorites WHERE visitor_id = ?");
    $stmt->execute([$visitorId]);
    return array_column($stmt->fetchAll(), 'message_id');
}

/**
 * 切换收藏状态
 * 返回: ['favorited' => bool, 'action' => 'add'|'remove']
 */
function toggleFavorite($messageId) {
    $visitorId = getVisitorId();
    $db = getDB();

    $db->beginTransaction();
    try {
        $stmt = $db->prepare("SELECT id FROM favorites WHERE visitor_id = ? AND message_id = ? FOR UPDATE");
        $stmt->execute([$visitorId, $messageId]);
        $exists = $stmt->fetch();

        if ($exists) {
            $db->prepare("DELETE FROM favorites WHERE visitor_id = ? AND message_id = ?")
                ->execute([$visitorId, $messageId]);
            $result = ['favorited' => false, 'action' => 'remove'];
        } else {
            $db->prepare("INSERT INTO favorites (visitor_id, message_id) VALUES (?, ?)")
                ->execute([$visitorId, $messageId]);
            $result = ['favorited' => true, 'action' => 'add'];
        }

        $db->commit();
        return $result;
    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }
}

/**
 * 获取举报类型文字
 */
function getReportTypeLabel($type) {
    $map = [
        'spam' => '垃圾信息',
        'abuse' => '辱骂攻击',
        'illegal' => '违法违规',
        'porn' => '色情低俗',
        'other' => '其他'
    ];
    return $map[$type] ?? '未知';
}

/**
 * 获取举报状态文字
 */
function getReportStatusLabel($status) {
    $map = [
        0 => '待处理',
        1 => '已处理-已删除',
        2 => '已处理-已忽略',
        3 => '已驳回'
    ];
    return $map[$status] ?? '未知';
}

/**
 * 获取举报状态样式类
 */
function getReportStatusClass($status) {
    $map = [
        0 => 'pending',
        1 => 'resolved-deleted',
        2 => 'resolved-ignored',
        3 => 'rejected'
    ];
    return $map[$status] ?? '';
}

/**
 * 检查当前访客是否已举报过某条留言
 */
function hasReported($messageId) {
    $visitorId = getVisitorId();
    $db = getDB();
    $stmt = $db->prepare("SELECT id FROM reports WHERE visitor_id = ? AND message_id = ?");
    $stmt->execute([$visitorId, $messageId]);
    return $stmt->fetch() !== false;
}

/**
 * 提交举报
 */
function submitReport($messageId, $reportType, $description = '') {
    $visitorId = getVisitorId();
    $db = getDB();

    $validTypes = ['spam', 'abuse', 'illegal', 'porn', 'other'];
    if (!in_array($reportType, $validTypes)) {
        throw new Exception('无效的举报类型');
    }

    $stmt = $db->prepare("SELECT id FROM messages WHERE id = ? AND status = 1");
    $stmt->execute([$messageId]);
    if (!$stmt->fetch()) {
        throw new Exception('留言不存在或未通过审核');
    }

    if (hasReported($messageId)) {
        throw new Exception('您已经举报过这条留言了');
    }

    $stmt = $db->prepare("INSERT INTO reports (message_id, visitor_id, report_type, description) VALUES (?, ?, ?, ?)");
    $stmt->execute([$messageId, $visitorId, $reportType, $description]);

    return $db->lastInsertId();
}

/**
 * 获取待处理举报数量
 */
function getPendingReportCount() {
    $db = getDB();
    return $db->query("SELECT COUNT(*) FROM reports WHERE status = 0")->fetchColumn();
}

/* ===================== 后台列表常用条件 ===================== */

/**
 * 常用筛选条件表（幂等建表，兼容已安装的环境）
 */
function ensureSavedFiltersTable() {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    $db = getDB();
    $db->exec("CREATE TABLE IF NOT EXISTS `saved_filters` (
        `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `name` VARCHAR(50) NOT NULL COMMENT '条件名称',
        `params` TEXT NOT NULL COMMENT '筛选参数JSON: status,type,keyword,page',
        `created_by` INT UNSIGNED DEFAULT NULL COMMENT '创建人管理员ID',
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
        UNIQUE KEY `uk_name` (`name`),
        INDEX `idx_created_by` (`created_by`),
        FOREIGN KEY (`created_by`) REFERENCES `admins`(`id`) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='后台常用筛选条件'");
}

/**
 * 规范化留言列表筛选参数（白名单，列表页/下载/保存共用同一口径）
 */
function normalizeMessageFilters(array $src) {
    $status = isset($src['status']) && in_array((string)$src['status'], ['0', '1', '2'], true)
        ? (string)$src['status'] : '';
    $type = isset($src['type']) && in_array((string)$src['type'], ['help', 'suggest', 'lost'], true)
        ? (string)$src['type'] : '';
    $keyword = isset($src['keyword']) ? trim((string)$src['keyword']) : '';
    $keyword = mb_substr($keyword, 0, 100);
    $page = isset($src['page']) ? intval($src['page']) : 1;
    if ($page < 1) $page = 1;
    if ($page > 10000) $page = 10000;
    return ['status' => $status, 'type' => $type, 'keyword' => $keyword, 'page' => $page];
}

/**
 * 根据筛选参数构造留言列表 WHERE（与屏幕列表完全一致的口径）
 * 返回 [whereSql, params]
 */
function buildMessageWhere(array $filters) {
    $where = "WHERE 1=1";
    $params = [];
    if ($filters['status'] !== '') {
        $where .= " AND status = ?";
        $params[] = intval($filters['status']);
    }
    if ($filters['type'] !== '') {
        $where .= " AND type = ?";
        $params[] = $filters['type'];
    }
    if ($filters['keyword'] !== '') {
        $where .= " AND (title LIKE ? OR content LIKE ? OR nickname LIKE ?)";
        $kw = '%' . $filters['keyword'] . '%';
        $params[] = $kw;
        $params[] = $kw;
        $params[] = $kw;
    }
    return [$where, $params];
}

/**
 * 留言列表分页链接
 */
function messageListUrl(array $filters, $page = null) {
    $query = [];
    if ($filters['status'] !== '') $query['status'] = $filters['status'];
    if ($filters['type'] !== '') $query['type'] = $filters['type'];
    if ($filters['keyword'] !== '') $query['keyword'] = $filters['keyword'];
    if ($page !== null && $page > 1) $query['page'] = $page;
    return 'index.php' . ($query ? '?' . http_build_query($query) : '');
}

/**
 * 筛选条件的人类可读描述（用于提示与文件说明）
 */
function describeMessageFilters(array $filters) {
    $statusMap = ['' => '全部', '0' => '待审核', '1' => '已通过', '2' => '已拒绝'];
    $typeMap = ['' => '全部', 'help' => '居民求助', 'suggest' => '意见建议', 'lost' => '失物招领'];
    return '状态：' . $statusMap[$filters['status']]
        . '；类型：' . $typeMap[$filters['type']]
        . '；关键词：' . ($filters['keyword'] !== '' ? $filters['keyword'] : '无')
        . '；第 ' . $filters['page'] . ' 页';
}

/**
 * 获取全部常用条件
 */
function getSavedFilters() {
    ensureSavedFiltersTable();
    $db = getDB();
    return $db->query("SELECT sf.*, a.username AS admin_name
        FROM saved_filters sf
        LEFT JOIN admins a ON sf.created_by = a.id
        ORDER BY sf.updated_at DESC")->fetchAll();
}

/**
 * 按ID获取常用条件
 */
function getSavedFilter($id) {
    ensureSavedFiltersTable();
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM saved_filters WHERE id = ?");
    $stmt->execute([intval($id)]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * 判断 PDO 异常是否为唯一键冲突
 */
function isDuplicateKeyError(PDOException $e) {
    return $e->getCode() === '23000';
}
