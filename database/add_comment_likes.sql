-- Add like support for comments.
-- Run once on an existing database after database/blog_schema.sql has already been applied.

CREATE TABLE IF NOT EXISTS `comment_likes` (
  `id` int(11) NOT NULL AUTO_INCREMENT COMMENT '댓글 좋아요 고유 번호',
  `comment_id` int(11) NOT NULL COMMENT '좋아요한 댓글',
  `user_id` int(11) NOT NULL COMMENT '좋아요한 회원',
  `created_at` datetime NOT NULL DEFAULT current_timestamp() COMMENT '좋아요 일시',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_comment_likes_comment_user` (`comment_id`,`user_id`),
  KEY `idx_comment_likes_user` (`user_id`),
  KEY `idx_comment_likes_user_created` (`user_id`,`created_at`,`comment_id`),
  CONSTRAINT `fk_comment_likes_comment` FOREIGN KEY (`comment_id`) REFERENCES `comments` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_comment_likes_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
