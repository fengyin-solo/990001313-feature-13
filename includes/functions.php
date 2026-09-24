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

/* ===================== 常用条件保存 / 定位记忆 / 结果下载 ===================== */

/**
 * 确保常用条件相关表存在（幂等，与 database/migration_add_saved_filters.sql 保持一致）
 */
function ensureFilterTables($db) {
    static $checked = false;
    if ($checked) return;
    $db->exec("CREATE TABLE IF NOT EXISTS `saved_filters` (
        `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `admin_id` INT UNSIGNED NOT NULL COMMENT '所属管理员ID',
        `name` VARCHAR(50) NOT NULL COMMENT '条件名称',
        `status` VARCHAR(2) NOT NULL DEFAULT '' COMMENT '状态筛选: 空=全部, 0待审核, 1已通过, 2已拒绝',
        `type` VARCHAR(10) NOT NULL DEFAULT '' COMMENT '类型筛选: 空=全部, help求助, suggest建议, lost失物招领',
        `keyword` VARCHAR(100) NOT NULL DEFAULT '' COMMENT '搜索关键词',
        `page` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT '保存时所在页码（定位口径）',
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
        UNIQUE KEY `uk_admin_name` (`admin_id`, `name`),
        INDEX `idx_admin_id` (`admin_id`),
        FOREIGN KEY (`admin_id`) REFERENCES `admins`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='后台常用筛选条件'");

    $db->exec("CREATE TABLE IF NOT EXISTS `admin_view_states` (
        `admin_id` INT UNSIGNED NOT NULL COMMENT '管理员ID',
        `page_key` VARCHAR(50) NOT NULL DEFAULT 'messages' COMMENT '页面标识: messages留言列表',
        `params` VARCHAR(500) NOT NULL DEFAULT '' COMMENT '最后访问的查询参数(query string)',
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
        PRIMARY KEY (`admin_id`, `page_key`),
        FOREIGN KEY (`admin_id`) REFERENCES `admins`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='后台列表定位记忆'");

    $checked = true;
}

/**
 * 归一化留言列表筛选口径（屏幕列表与下载共用，保证结果一致）
 */
function normalizeMessageFilters($input) {
    $status = isset($input['status']) && in_array((string)$input['status'], ['0', '1', '2'], true)
        ? (string)$input['status'] : '';
    $type = isset($input['type']) && in_array((string)$input['type'], ['help', 'suggest', 'lost'], true)
        ? (string)$input['type'] : '';
    $keyword = isset($input['keyword']) ? mb_substr(trim((string)$input['keyword']), 0, 100) : '';
    $page = max(1, intval($input['page'] ?? 1));
    return ['status' => $status, 'type' => $type, 'keyword' => $keyword, 'page' => $page];
}

/**
 * 依据筛选口径构造 WHERE 与参数（屏幕列表与下载共用）
 */
function buildMessageWhere($filters) {
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
        $kw = "%{$filters['keyword']}%";
        $params[] = $kw;
        $params[] = $kw;
        $params[] = $kw;
    }
    return [$where, $params];
}

/**
 * 读取管理员上次留言列表的定位参数（含页码）
 */
function getAdminViewState($db, $adminId, $pageKey = 'messages') {
    ensureFilterTables($db);
    $stmt = $db->prepare("SELECT params FROM admin_view_states WHERE admin_id = ? AND page_key = ?");
    $stmt->execute([$adminId, $pageKey]);
    $params = $stmt->fetchColumn();
    if ($params === false || $params === '') return [];
    parse_str($params, $parsed);
    return is_array($parsed) ? $parsed : [];
}

/**
 * 记录管理员当前留言列表定位口径（含页码）
 */
function saveAdminViewState($db, $adminId, $filters, $pageKey = 'messages') {
    ensureFilterTables($db);
    $query = http_build_query([
        'status' => $filters['status'],
        'type' => $filters['type'],
        'keyword' => $filters['keyword'],
        'page' => $filters['page'],
    ]);
    $stmt = $db->prepare(
        "INSERT INTO admin_view_states (admin_id, page_key, params) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE params = VALUES(params)"
    );
    $stmt->execute([$adminId, $pageKey, $query]);
}

/**
 * 列出管理员保存的常用条件
 */
function getSavedFilters($db, $adminId) {
    ensureFilterTables($db);
    $stmt = $db->prepare("SELECT * FROM saved_filters WHERE admin_id = ? ORDER BY id DESC");
    $stmt->execute([$adminId]);
    return $stmt->fetchAll();
}

/**
 * 保存（新增）常用条件。
 * 仅做 INSERT：同一管理员同名时由唯一键拒绝，返回 null；
 * 多人/并发保存同名条件时互不覆盖，调用方提示改名即可。
 */
function createSavedFilter($db, $adminId, $name, $filters) {
    ensureFilterTables($db);
    $name = mb_substr(trim($name), 0, 50);
    if ($name === '') {
        throw new Exception('请输入条件名称');
    }
    try {
        $stmt = $db->prepare(
            "INSERT INTO saved_filters (admin_id, name, status, type, keyword, page) VALUES (?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $adminId, $name,
            $filters['status'], $filters['type'], $filters['keyword'], $filters['page'],
        ]);
        return $db->lastInsertId();
    } catch (PDOException $e) {
        if (($e->errorInfo[1] ?? null) == 1062) return null; // 重名，不覆盖
        throw $e;
    }
}

/**
 * 删除常用条件（只能删自己的）
 */
function deleteSavedFilter($db, $adminId, $id) {
    ensureFilterTables($db);
    $stmt = $db->prepare("DELETE FROM saved_filters WHERE id = ? AND admin_id = ?");
    $stmt->execute([$id, $adminId]);
    return $stmt->rowCount() > 0;
}

/**
 * 条件口径文字摘要（用于界面展示与导出文件说明）
 */
function describeMessageFilters($filters) {
    $parts = [];
    $parts[] = '状态：' . ($filters['status'] === '' ? '全部' : getStatusLabel(intval($filters['status'])));
    $parts[] = '类型：' . ($filters['type'] === '' ? '全部' : getTypeLabel($filters['type']));
    $parts[] = '关键词：' . ($filters['keyword'] === '' ? '无' : $filters['keyword']);
    return implode('；', $parts);
}

/**
 * 导出管理员常用条件为可移植的数据包（JSON 字符串）
 */
function exportSavedFiltersPayload($db, $adminId, $ids = null) {
    $rows = getSavedFilters($db, $adminId);
    if ($ids !== null) {
        $idMap = array_flip(array_map('intval', $ids));
        $rows = array_filter($rows, function ($r) use ($idMap) {
            return isset($idMap[intval($r['id'])]);
        });
    }
    $items = array_map(function ($r) {
        return [
            'name' => $r['name'],
            'status' => $r['status'],
            'type' => $r['type'],
            'keyword' => $r['keyword'],
            'page' => intval($r['page']),
        ];
    }, array_values($rows));

    return [
        'app' => 'community_board',
        'kind' => 'saved_filters',
        'version' => 1,
        'exported_at' => date('c'),
        'filters' => $items,
    ];
}

/**
 * 校验导入数据包中的单条条件，返回归一化后的结构；不合法返回 null
 */
function normalizeImportedFilter($item) {
    if (!is_array($item)) return null;
    $name = isset($item['name']) ? mb_substr(trim((string)$item['name']), 0, 50) : '';
    if ($name === '') return null;
    $status = isset($item['status']) && in_array((string)$item['status'], ['', '0', '1', '2'], true)
        ? (string)$item['status'] : '';
    $type = isset($item['type']) && in_array((string)$item['type'], ['', 'help', 'suggest', 'lost'], true)
        ? (string)$item['type'] : '';
    $keyword = isset($item['keyword']) ? mb_substr(trim((string)$item['keyword']), 0, 100) : '';
    $page = max(1, min(10000, intval($item['page'] ?? 1)));
    return compact('name', 'status', 'type', 'keyword', 'page');
}

/**
 * 导入条件数据包。重名不覆盖，自动追加“(2)/(3)…”后缀；确实冲突到无法落库则跳过。
 * 返回 [导入数, 改名数, 跳过数]
 */
function importSavedFiltersPayload($db, $adminId, $payload) {
    ensureFilterTables($db);
    if (!is_array($payload) || ($payload['kind'] ?? '') !== 'saved_filters' || !is_array($payload['filters'] ?? null)) {
        throw new Exception('文件格式不正确，不是有效的条件包');
    }

    // 当前已占用的名称，导入过程中同步维护
    $stmt = $db->prepare("SELECT name FROM saved_filters WHERE admin_id = ?");
    $stmt->execute([$adminId]);
    $usedNames = array_flip(array_map(function ($n) { return mb_strtolower($n); }, $stmt->fetchAll(PDO::FETCH_COLUMN)));

    $insert = $db->prepare(
        "INSERT INTO saved_filters (admin_id, name, status, type, keyword, page) VALUES (?, ?, ?, ?, ?, ?)"
    );

    $imported = $renamed = $skipped = 0;
    foreach ($payload['filters'] as $item) {
        $f = normalizeImportedFilter($item);
        if ($f === null) { $skipped++; continue; }

        // 为重名预留后缀空间，如“原名称(2)”，保证总长不超过 50
        $suffix = 2;
        $suffixStr = '(' . $suffix . ')';
        $base = mb_strlen($f['name']) > 50 - strlen($suffixStr)
            ? mb_substr($f['name'], 0, 50 - strlen($suffixStr))
            : $f['name'];
        $finalName = $base;
        $isRenamed = false;
        while (isset($usedNames[mb_strtolower($finalName)])) {
            $suffixStr = '(' . $suffix . ')';
            $base = mb_strlen($f['name']) > 50 - strlen($suffixStr)
                ? mb_substr($f['name'], 0, 50 - strlen($suffixStr))
                : $f['name'];
            $finalName = $base . $suffixStr;
            $suffix++;
            $isRenamed = true;
        }

        try {
            $insert->execute([$adminId, $finalName, $f['status'], $f['type'], $f['keyword'], $f['page']]);
            $usedNames[mb_strtolower($finalName)] = true;
            $imported++;
            if ($isRenamed) $renamed++;
        } catch (PDOException $e) {
            if (($e->errorInfo[1] ?? null) == 1062) { $skipped++; continue; }
            throw $e;
        }
    }
    return [$imported, $renamed, $skipped];
}
