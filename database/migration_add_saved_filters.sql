-- 后台常用筛选条件表迁移脚本
-- 执行此 SQL 来添加“常用条件保存/导入导出”所需的表结构
-- 注：后台页面加载时也会自动幂等建表，本脚本供手动部署使用

USE `community_board`;

CREATE TABLE IF NOT EXISTS `saved_filters` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(50) NOT NULL COMMENT '条件名称',
    `params` TEXT NOT NULL COMMENT '筛选参数JSON: status,type,keyword,page',
    `created_by` INT UNSIGNED DEFAULT NULL COMMENT '创建人管理员ID',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
    UNIQUE KEY `uk_name` (`name`),
    INDEX `idx_created_by` (`created_by`),
    FOREIGN KEY (`created_by`) REFERENCES `admins`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='后台常用筛选条件';

-- name 列的唯一索引保证多人同时保存同名条件时不会互相覆盖。
-- 验证：
-- SHOW TABLES LIKE 'saved_filters';
-- DESCRIBE saved_filters;
