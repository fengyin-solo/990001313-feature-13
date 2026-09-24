-- 常用筛选条件与后台定位记忆 迁移脚本
-- 执行此 SQL 来添加“常用条件保存 / 结果下载 / 条件导入导出”所需的表结构

USE `community_board`;

-- 常用筛选条件表（按管理员隔离，同名条件唯一，并发保存不会互相覆盖）
CREATE TABLE IF NOT EXISTS `saved_filters` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='后台常用筛选条件';

-- 管理员列表定位记忆表（重新进入后台时回到原记录位置）
CREATE TABLE IF NOT EXISTS `admin_view_states` (
    `admin_id` INT UNSIGNED NOT NULL COMMENT '管理员ID',
    `page_key` VARCHAR(50) NOT NULL DEFAULT 'messages' COMMENT '页面标识: messages留言列表',
    `params` VARCHAR(500) NOT NULL DEFAULT '' COMMENT '最后访问的查询参数(query string)',
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
    PRIMARY KEY (`admin_id`, `page_key`),
    FOREIGN KEY (`admin_id`) REFERENCES `admins`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='后台列表定位记忆';

-- 执行完成后，可以通过以下命令验证：
-- SHOW TABLES LIKE 'saved_filters';
-- SHOW TABLES LIKE 'admin_view_states';
-- DESCRIBE saved_filters;
