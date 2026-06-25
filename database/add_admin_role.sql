-- Add administrator permission to BRIDGE 206 users.
-- Run this once on an existing database after blog_schema.sql/sample data.
--
-- Default policy:
--   is_admin = 0 : normal member
--   is_admin = 1 : administrator, can enter pages/admin.php
--
-- The first sample/created user is promoted as the initial administrator.
-- If you want a different administrator, replace the final UPDATE condition
-- with that user's email or id.

ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `is_admin` TINYINT(1) NOT NULL DEFAULT 0
  COMMENT '관리자 권한 여부(0=일반회원, 1=관리자)'
  AFTER `notifications_read_at`;

UPDATE `users`
SET `is_admin` = 1
WHERE `id` = 1
LIMIT 1;

-- Example for choosing an administrator by email instead:
-- UPDATE `users` SET `is_admin` = 1 WHERE `email` = 'your-email@example.com' LIMIT 1;
