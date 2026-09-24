<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';
requireAdmin();

header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$db = getDB();

switch ($action) {
    case 'detail':
        $id = intval($_GET['id'] ?? 0);
        $stmt = $db->prepare("SELECT * FROM messages WHERE id = ?");
        $stmt->execute([$id]);
        $msg = $stmt->fetch();
        if (!$msg) jsonResponse(1, '留言不存在');
        $msg['type_label'] = getTypeLabel($msg['type']);
        $msg['status_label'] = getStatusLabel($msg['status']);
        $msg['content'] = nl2br(cleanInput($msg['content']));
        $msg['title'] = cleanInput($msg['title']);
        $msg['nickname'] = cleanInput($msg['nickname']);
        jsonResponse(0, 'ok', $msg);
        break;

    case 'audit':
        $id = intval($_POST['id'] ?? 0);
        $status = intval($_POST['status'] ?? 0);
        if (!in_array($status, [1, 2])) jsonResponse(1, '无效状态');
        $stmt = $db->prepare("UPDATE messages SET status = ? WHERE id = ?");
        $stmt->execute([$status, $id]);
        jsonResponse(0, '操作成功');
        break;

    case 'delete':
        $id = intval($_POST['id'] ?? 0);
        // 删除关联图片
        $stmt = $db->prepare("SELECT image FROM messages WHERE id = ?");
        $stmt->execute([$id]);
        $msg = $stmt->fetch();
        if ($msg && $msg['image']) {
            $imgFile = __DIR__ . '/../' . $msg['image'];
            if (file_exists($imgFile)) unlink($imgFile);
        }
        $db->prepare("DELETE FROM messages WHERE id = ?")->execute([$id]);
        jsonResponse(0, '删除成功');
        break;

    case 'report_detail':
        $id = intval($_GET['id'] ?? 0);
        $stmt = $db->prepare("SELECT r.*, m.title as message_title, m.nickname as message_nickname, m.type as message_type, m.content as message_content, m.image as message_image, a.username as admin_name FROM reports r LEFT JOIN messages m ON r.message_id = m.id LEFT JOIN admins a ON r.processed_by = a.id WHERE r.id = ?");
        $stmt->execute([$id]);
        $report = $stmt->fetch();
        if (!$report) jsonResponse(1, '举报不存在');

        $report['report_type_label'] = getReportTypeLabel($report['report_type']);
        $report['status_label'] = getReportStatusLabel($report['status']);
        $report['status_class'] = getReportStatusClass($report['status']);
        $report['message_exists'] = !empty($report['message_title']);
        $report['message_type_label'] = $report['message_type'] ? getTypeLabel($report['message_type']) : '';
        $report['message_title'] = $report['message_title'] ? cleanInput($report['message_title']) : '';
        $report['message_nickname'] = $report['message_nickname'] ? cleanInput($report['message_nickname']) : '';
        $report['message_content'] = $report['message_content'] ? nl2br(cleanInput($report['message_content'])) : '';
        $report['description'] = $report['description'] ? nl2br(cleanInput($report['description'])) : '';
        $report['process_note'] = $report['process_note'] ? nl2br(cleanInput($report['process_note'])) : '';
        $report['admin_name'] = $report['admin_name'] ? cleanInput($report['admin_name']) : '';

        jsonResponse(0, 'ok', $report);
        break;

    case 'process_report':
        $id = intval($_POST['id'] ?? 0);
        $status = intval($_POST['status'] ?? 0);
        $note = cleanInput($_POST['note'] ?? '');

        if (!in_array($status, [1, 2, 3])) jsonResponse(1, '无效状态');

        $db->beginTransaction();
        try {
            $stmt = $db->prepare("SELECT * FROM reports WHERE id = ? AND status = 0 FOR UPDATE");
            $stmt->execute([$id]);
            $report = $stmt->fetch();
            if (!$report) jsonResponse(1, '举报不存在或已处理');

            if ($status === 1) {
                $stmt = $db->prepare("SELECT image FROM messages WHERE id = ?");
                $stmt->execute([$report['message_id']]);
                $msg = $stmt->fetch();
                if ($msg && $msg['image']) {
                    $imgFile = __DIR__ . '/../' . $msg['image'];
                    if (file_exists($imgFile)) unlink($imgFile);
                }
                $db->prepare("DELETE FROM messages WHERE id = ?")->execute([$report['message_id']]);
            }

            $stmt = $db->prepare("UPDATE reports SET status = ?, processed_by = ?, processed_at = NOW(), process_note = ? WHERE id = ?");
            $stmt->execute([$status, $_SESSION['admin_id'], $note, $id]);

            $db->commit();

            $statusMsg = [1 => '已删除留言', 2 => '已忽略举报', 3 => '已驳回举报'];
            jsonResponse(0, $statusMsg[$status] . '成功');
        } catch (Exception $e) {
            $db->rollBack();
            jsonResponse(1, '操作失败: ' . $e->getMessage());
        }
        break;

    /* ===================== 常用筛选条件 ===================== */

    case 'save_filter': {
        // 新增常用条件。名称全局唯一，靠唯一索引保证多人同时保存同名时不会互相覆盖
        $name = trim($_POST['name'] ?? '');
        if ($name === '') jsonResponse(1, '名称不能为空');
        if (mb_strlen($name) > 50) jsonResponse(1, '名称最长 50 个字符');

        $filters = normalizeMessageFilters($_POST);
        try {
            $stmt = $db->prepare("INSERT INTO saved_filters (name, params, created_by) VALUES (?, ?, ?)");
            $stmt->execute([
                $name,
                json_encode($filters, JSON_UNESCAPED_UNICODE),
                $_SESSION['admin_id'],
            ]);
        } catch (PDOException $e) {
            if (isDuplicateKeyError($e)) {
                jsonResponse(1, '条件名称「' . $name . '」已存在，请换个名称（未覆盖任何已有条件）');
            }
            jsonResponse(1, '保存失败：' . $e->getMessage());
        }
        jsonResponse(0, '常用条件已保存');
        break;
    }

    case 'update_filter': {
        // 用当前条件覆盖指定常用条件（显式按ID更新，不依赖名称，避免误覆盖同名条件）
        $id = intval($_POST['id'] ?? 0);
        if (!getSavedFilter($id)) jsonResponse(1, '常用条件不存在，可能已被他人删除');
        $filters = normalizeMessageFilters($_POST);
        $db->prepare("UPDATE saved_filters SET params = ? WHERE id = ?")
            ->execute([json_encode($filters, JSON_UNESCAPED_UNICODE), $id]);
        jsonResponse(0, '常用条件已更新');
        break;
    }

    case 'rename_filter': {
        $id = intval($_POST['id'] ?? 0);
        if (!getSavedFilter($id)) jsonResponse(1, '常用条件不存在，可能已被他人删除');
        $name = trim($_POST['name'] ?? '');
        if ($name === '') jsonResponse(1, '名称不能为空');
        if (mb_strlen($name) > 50) jsonResponse(1, '名称最长 50 个字符');
        try {
            $stmt = $db->prepare("UPDATE saved_filters SET name = ? WHERE id = ?");
            $stmt->execute([$name, $id]);
        } catch (PDOException $e) {
            if (isDuplicateKeyError($e)) {
                jsonResponse(1, '条件名称「' . $name . '」已存在，请换个名称');
            }
            jsonResponse(1, '重命名失败：' . $e->getMessage());
        }
        jsonResponse(0, '重命名成功');
        break;
    }

    case 'delete_filter': {
        $id = intval($_POST['id'] ?? 0);
        $db->prepare("DELETE FROM saved_filters WHERE id = ?")->execute([$id]);
        jsonResponse(0, '已删除');
        break;
    }

    case 'export_filters': {
        // 导出全部常用条件打包文件
        $rows = $db->query("SELECT name, params, created_at, updated_at FROM saved_filters ORDER BY id")->fetchAll();
        $package = [
            'app' => 'community_board',
            'type' => 'admin_saved_filters',
            'version' => 1,
            'exported_at' => date('c'),
            'filters' => $rows,
        ];

        while (ob_get_level() > 0) ob_end_clean();
        $filename = '常用条件包_' . date('Ymd_His') . '.json';
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename*=UTF-8\'\'' . rawurlencode($filename));
        header('Cache-Control: no-store, no-cache, must-revalidate');
        echo json_encode($package, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    case 'import_filters': {
        // 导入常用条件包；同名一律不覆盖，自动改名后导入
        if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            jsonResponse(1, '文件上传失败，请重试');
        }
        if ($_FILES['file']['size'] > 1024 * 1024) jsonResponse(1, '文件不能超过 1MB');

        $raw = file_get_contents($_FILES['file']['tmp_name']);
        $package = json_decode($raw, true);
        if (!is_array($package) || ($package['type'] ?? '') !== 'admin_saved_filters' || !is_array($package['filters'] ?? null)) {
            jsonResponse(1, '文件格式不正确，请选择本系统导出的常用条件包（.json）');
        }
        if (count($package['filters']) > 500) jsonResponse(1, '文件内条件数量超过上限（500）');

        $imported = 0;
        $renamed = 0;
        $skipped = 0;
        $usedNames = [];

        foreach ($package['filters'] as $item) {
            $name = trim($item['name'] ?? '');
            if ($name === '' || !isset($item['params'])) {
                $skipped++;
                continue;
            }
            if (mb_strlen($name) > 50) $name = mb_substr($name, 0, 50);
            $params = is_array($item['params']) ? normalizeMessageFilters($item['params']) : normalizeMessageFilters([]);

            // 冲突处理：名称已存在（含本次导入刚占用的）则自动改名，绝不覆盖
            $baseName = $name;
            $suffix = 1;
            $nameExistsStmt = $db->prepare("SELECT 1 FROM saved_filters WHERE name = ?");
            while ($suffix <= 1000) {
                $candidate = $suffix === 1 ? $name : $baseName . '(' . $suffix . ')';
                if (mb_strlen($candidate) > 50) $candidate = mb_substr($baseName, 0, 50 - strlen('(' . $suffix . ')')) . '(' . $suffix . ')';
                $nameExistsStmt->execute([$candidate]);
                if (!$nameExistsStmt->fetchColumn() && !in_array($candidate, $usedNames, true)) {
                    break;
                }
                $candidate = null;
                $suffix++;
            }
            if (!isset($candidate) || $suffix > 1000) {
                $skipped++;
                continue;
            }
            if ($suffix > 1) $renamed++;

            try {
                $stmt = $db->prepare("INSERT INTO saved_filters (name, params, created_by) VALUES (?, ?, ?)");
                $stmt->execute([$candidate, json_encode($params, JSON_UNESCAPED_UNICODE), $_SESSION['admin_id']]);
                $usedNames[] = $candidate;
                $imported++;
            } catch (PDOException $e) {
                if (isDuplicateKeyError($e)) {
                    // 并发撞名：跳过本条而不是覆盖
                    $skipped++;
                    continue;
                }
                jsonResponse(1, '导入中断：' . $e->getMessage());
            }
        }

        jsonResponse(0, "导入完成：成功 {$imported} 条" . ($renamed ? "，其中 {$renamed} 条因同名自动改名" : '') . ($skipped ? "，跳过 {$skipped} 条" : ''));
        break;
    }

    default:
        jsonResponse(1, '未知操作');
}
